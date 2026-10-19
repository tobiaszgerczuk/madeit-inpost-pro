<?php

/**
 * ShipX flow with a mocked API: creating a shipment, cash on delivery, status refresh, label and errors.
 * Run: wp eval-file wp-content/plugins/madeit-inpost-pro/tests/smoke.php (the free MadeIT InPost plugin must be active)
 * It creates and deletes orders, so use it only in an isolated test site.
 */

use Madeit\Inpost\Plugin as FreePlugin;
use Madeit\Inpost\ShippingMethod;
use Madeit\InpostPro\Orders;
use Madeit\InpostPro\Settings;

function madeit_inpost_pro_expect(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$originalPro = get_option('madeit_inpost_pro_settings', []);
$originalFree = get_option('madeit_inpost_settings', []);
update_option('madeit_inpost_pro_settings', ['organization_id' => '12345', 'api_token' => 'secret', 'default_size' => 'B', 'sending_method' => 'parcel_locker', 'accent' => '#aa3366'], false);
update_option('madeit_inpost_settings', ['sandbox' => 'yes'], false);

madeit_inpost_pro_expect(Settings::get()['default_size'] === 'B' && Settings::get()['organization_id'] === '12345', 'Pro settings are read.');
madeit_inpost_pro_expect(FreePlugin::settings()['accent'] === '#aa3366', 'The accent colour reaches the free plugin.');
madeit_inpost_pro_expect(array_key_exists('shipments', apply_filters('madeit_inpost_settings_tabs', [])), 'The Pro tabs are added.');

$requests = [];
$responses = [];
add_filter('pre_http_request', static function ($pre, array $args, string $url) use (&$requests, &$responses) {
    $requests[] = ['method' => $args['method'] ?? 'GET', 'url' => $url, 'body' => $args['body'] ?? ''];
    $next = array_shift($responses);

    return $next ?? new WP_Error('no_mock', 'No mocked response');
}, 10, 3);
$json = static fn (int $code, array $body): array => ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];

WC()->shipping()->load_shipping_methods();
$makeOrder = static function (string $payment, string $phone) {
    $order = wc_create_order();
    $item = new WC_Order_Item_Shipping;
    $item->set_method_title('InPost Parcel Locker');
    $item->set_method_id(ShippingMethod::ID);
    $order->add_item($item);
    $order->set_billing_first_name('Anna');
    $order->set_billing_last_name('Nowak');
    $order->set_billing_email('anna@example.test');
    $order->set_billing_phone($phone);
    $order->set_payment_method($payment);
    $order->set_total(120.5);
    $order->save();

    return $order;
};
$orders = new Orders;

// A normal shipment.
$order = $makeOrder('bacs', '+48 512 345 678');
$responses[] = $json(201, ['id' => 987, 'status' => 'created', 'tracking_number' => null]);
$result = $orders->createShipment($order, 'WAW01M', 'C');
madeit_inpost_pro_expect($result === true, 'The shipment is created.');
madeit_inpost_pro_expect(str_contains($requests[0]['url'], 'sandbox-api-shipx-pl') && str_contains($requests[0]['url'], '/organizations/12345/shipments'), 'The sandbox endpoint and the organization are used.');
$payload = json_decode($requests[0]['body'], true);
madeit_inpost_pro_expect($payload['custom_attributes'] === ['target_point' => 'WAW01M', 'sending_method' => 'parcel_locker'] && $payload['parcels'][0]['template'] === 'large' && ! isset($payload['cod']), 'The payload has the point, the size and no cash on delivery.');
$order = wc_get_order($order->get_id());
madeit_inpost_pro_expect($order->get_meta(Orders::META_SHIPMENT) === '987' && $order->get_meta(Orders::META_STATUS) === 'created', 'The shipment is stored in the order.');

// Refresh and label.
$responses[] = $json(200, ['id' => 987, 'status' => 'confirmed', 'tracking_number' => '600000000000000000000001']);
madeit_inpost_pro_expect($orders->refreshShipment($order) === true && wc_get_order($order->get_id())->get_meta(Orders::META_TRACKING) === '600000000000000000000001', 'The tracking number is read.');
$responses[] = ['headers' => [], 'body' => '%PDF-1.4 test', 'response' => ['code' => 200, 'message' => ''], 'cookies' => [], 'filename' => null];
$pdf = Orders::shipx()->label('987');
madeit_inpost_pro_expect($pdf === '%PDF-1.4 test', 'The label is returned.');

// Cash on delivery.
$cod = $makeOrder('cod', '512345678');
$responses[] = $json(201, ['id' => 988, 'status' => 'created']);
$orders->createShipment($cod, 'KRA010', 'A');
$payload = json_decode(end($requests)['body'], true);
madeit_inpost_pro_expect(($payload['cod']['amount'] ?? 0) == 120.5, 'Cash on delivery passes the order total.');

// Errors.
$bad = $makeOrder('bacs', '123');
$result = $orders->createShipment($bad, 'WAW01M', 'A');
madeit_inpost_pro_expect(is_wp_error($result) && $result->get_error_code() === 'madeit_inpost_phone', 'A wrong phone number is rejected before the request.');
$result = $orders->createShipment($bad, 'bad code', 'A');
madeit_inpost_pro_expect(is_wp_error($result) && $result->get_error_code() === 'madeit_inpost_point', 'A wrong point code is rejected.');
$ok = $makeOrder('bacs', '512345678');
$responses[] = $json(401, ['message' => 'Unauthorized']);
$result = $orders->createShipment($ok, 'WAW01M', 'A');
madeit_inpost_pro_expect(is_wp_error($result) && $result->get_error_code() === 'madeit_inpost_auth', 'A rejected token gives a clear error.');
$responses[] = $json(400, ['details' => ['receiver' => ['phone' => ['invalid']]]]);
$result = $orders->createShipment($ok, 'WAW01M', 'A');
madeit_inpost_pro_expect(is_wp_error($result) && str_contains($result->get_error_message(), 'receiver.phone: invalid'), 'API validation errors are described.');

// The box on the order screen.
ob_start();
(new Madeit\Inpost\Orders)->renderMetaBox($order);
$box = (string) ob_get_clean();
madeit_inpost_pro_expect(str_contains($box, '600000000000000000000001') && str_contains($box, 'madeit_inpost_label') && ! str_contains($box, 'MadeIT InPost Pro</a>'), 'The box shows the tracking number and the label link without the Pro hint.');

foreach ([$order, $cod, $bad, $ok] as $created) {
    $created->delete(true);
}
update_option('madeit_inpost_pro_settings', $originalPro, false);
update_option('madeit_inpost_settings', $originalFree, false);
echo "OK\n";
