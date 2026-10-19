<?php

namespace Madeit\InpostPro;

use Madeit\Inpost\Calculator as PointCheck;
use Madeit\Inpost\Plugin as FreePlugin;

defined('ABSPATH') || exit;

/**
 * Shipments from the order screen: creating a ShipX shipment, refreshing its status and downloading the label.
 */
final class Orders
{
    public const META_SIZE = '_madeit_inpost_size';

    public const META_SHIPMENT = '_madeit_inpost_shipment_id';

    public const META_TRACKING = '_madeit_inpost_tracking';

    public const META_STATUS = '_madeit_inpost_status';

    public function register(): void
    {
        add_action('madeit_inpost_order_box', [$this, 'renderBox'], 10, 2);
        add_action('madeit_inpost_point_saved', [$this, 'saveDefaultSize']);
        add_action('admin_post_madeit_inpost_create', [$this, 'create']);
        add_action('admin_post_madeit_inpost_refresh', [$this, 'refresh']);
        add_action('admin_post_madeit_inpost_label', [$this, 'label']);
        add_action('admin_notices', [$this, 'notices']);
    }

    public static function shipx(): ShipX
    {
        $settings = Settings::get();

        return new ShipX($settings['organization_id'], $settings['api_token'], FreePlugin::settings()['sandbox'] === 'yes');
    }

    public function saveDefaultSize(\WC_Order $order): void
    {
        $order->update_meta_data(self::META_SIZE, Settings::get()['default_size']);
    }

    public function renderBox(\WC_Order $order, string $point): void
    {
        $shipment = (string) $order->get_meta(self::META_SHIPMENT);
        $size = Calculator::normalizeSize((string) ($order->get_meta(self::META_SIZE) ?: Settings::get()['default_size']));
        if ($shipment !== '') {
            $tracking = (string) $order->get_meta(self::META_TRACKING);
            $status = (string) $order->get_meta(self::META_STATUS);
            echo '<p><strong>'.esc_html__('Status:', 'madeit-inpost-pro').'</strong> '.esc_html($status !== '' ? $status : __('created', 'madeit-inpost-pro')).'</p>';
            echo '<p><strong>'.esc_html__('Tracking number:', 'madeit-inpost-pro').'</strong> '.esc_html($tracking !== '' ? $tracking : __('not assigned yet (refresh in a moment)', 'madeit-inpost-pro')).'</p>';
            echo '<p><a class="button button-primary" href="'.esc_url($this->actionUrl('madeit_inpost_label', $order)).'">'.esc_html__('Download label (PDF)', 'madeit-inpost-pro').'</a> ';
            echo '<a class="button" href="'.esc_url($this->actionUrl('madeit_inpost_refresh', $order)).'">'.esc_html__('Refresh status', 'madeit-inpost-pro').'</a></p>';

            return;
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="madeit_inpost_create"><input type="hidden" name="order_id" value="'.esc_attr((string) $order->get_id()).'">';
        wp_nonce_field('madeit_inpost_create_'.$order->get_id());
        echo '<p><label><strong>'.esc_html__('Point code', 'madeit-inpost-pro').'</strong><br><input type="text" name="point" class="widefat" value="'.esc_attr($point).'" placeholder="'.esc_attr__('E.g. WAW01M', 'madeit-inpost-pro').'" maxlength="20"></label></p>';
        echo '<p><label><strong>'.esc_html__('Parcel size', 'madeit-inpost-pro').'</strong><br><select name="size" class="widefat">';
        foreach (self::sizeOptions() as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($size, $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label></p>';
        echo '<p><button type="submit" class="button button-primary">'.esc_html__('Create shipment', 'madeit-inpost-pro').'</button></p></form>';
        if (! self::shipx()->isConfigured()) {
            echo '<p class="description">'.esc_html__('First fill in the ShipX connection in the MadeIT InPost settings.', 'madeit-inpost-pro').'</p>';
        }
    }

    /**
     * @return array<string, string>
     */
    public static function sizeOptions(): array
    {
        return [
            'A' => __('A: small (8 × 38 × 64 cm)', 'madeit-inpost-pro'),
            'B' => __('B: medium (19 × 38 × 64 cm)', 'madeit-inpost-pro'),
            'C' => __('C: large (41 × 38 × 64 cm)', 'madeit-inpost-pro'),
        ];
    }

    private function actionUrl(string $action, \WC_Order $order): string
    {
        return wp_nonce_url(add_query_arg(['action' => $action, 'order_id' => $order->get_id()], admin_url('admin-post.php')), $action.'_'.$order->get_id());
    }

    private function backUrl(\WC_Order $order, string $notice, string $detail = ''): string
    {
        if ($detail !== '') {
            set_transient('madeit_inpost_error_'.get_current_user_id(), $detail, 60);
        }

        return add_query_arg('madeit_inpost_notice', $notice, $order->get_edit_order_url());
    }

    private function loadOrder(string $action): \WC_Order
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-inpost-pro'));
        }
        $orderId = isset($_REQUEST['order_id']) ? absint(wp_unslash($_REQUEST['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.
        check_admin_referer($action.'_'.$orderId);
        $order = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            wp_die(esc_html__('Order not found.', 'madeit-inpost-pro'));
        }

        return $order;
    }

    /**
     * Creates the shipment in ShipX and stores it in the order.
     */
    public function createShipment(\WC_Order $order, string $point, string $size): true|\WP_Error
    {
        if (! PointCheck::isValidPointName($point)) {
            return new \WP_Error('madeit_inpost_point', __('Enter a valid InPost point code, for example WAW01M.', 'madeit-inpost-pro'));
        }
        $first = (string) ($order->get_shipping_first_name() ?: $order->get_billing_first_name());
        $last = (string) ($order->get_shipping_last_name() ?: $order->get_billing_last_name());
        $phone = (string) ($order->get_shipping_phone() ?: $order->get_billing_phone());
        if (! Calculator::isValidPhone($phone)) {
            return new \WP_Error('madeit_inpost_phone', __('ShipX requires a Polish mobile number (9 digits). Correct the phone number in the order.', 'madeit-inpost-pro'));
        }
        $payload = Calculator::shipmentPayload([
            'first_name' => $first,
            'last_name' => $last,
            'email' => (string) $order->get_billing_email(),
            'phone' => $phone,
            'point' => $point,
            'size' => $size,
            /* translators: %s: order number */
            'reference' => sprintf(__('Order %s', 'madeit-inpost-pro'), $order->get_order_number()),
            'cod' => $order->get_payment_method() === 'cod' ? (float) $order->get_total() : 0.0,
            'currency' => $order->get_currency(),
            'sending_method' => Settings::get()['sending_method'],
        ]);
        $result = self::shipx()->createShipment($payload);
        if (is_wp_error($result)) {
            return $result;
        }
        $order->update_meta_data(FreePlugin::META_POINT, $point);
        $order->update_meta_data(self::META_SIZE, $size);
        $order->update_meta_data(self::META_SHIPMENT, (string) ($result['id'] ?? ''));
        $order->update_meta_data(self::META_STATUS, (string) ($result['status'] ?? 'created'));
        $order->update_meta_data(self::META_TRACKING, (string) ($result['tracking_number'] ?? ''));
        /* translators: 1: point code, 2: parcel size */
        $order->add_order_note(sprintf(__('InPost: shipment created for point %1$s (size %2$s).', 'madeit-inpost-pro'), $point, $size));
        $order->save();

        return true;
    }

    /**
     * Reads the current status and tracking number of the shipment.
     */
    public function refreshShipment(\WC_Order $order): true|\WP_Error
    {
        $id = (string) $order->get_meta(self::META_SHIPMENT);
        $result = $id !== '' ? self::shipx()->shipment($id) : new \WP_Error('madeit_inpost_none', __('This order has no InPost shipment.', 'madeit-inpost-pro'));
        if (is_wp_error($result)) {
            return $result;
        }
        $tracking = (string) ($result['tracking_number'] ?? '');
        if ($tracking !== '' && $tracking !== (string) $order->get_meta(self::META_TRACKING)) {
            /* translators: %s: tracking number */
            $order->add_order_note(sprintf(__('InPost: tracking number %s.', 'madeit-inpost-pro'), $tracking));
        }
        $order->update_meta_data(self::META_TRACKING, $tracking);
        $order->update_meta_data(self::META_STATUS, (string) ($result['status'] ?? ''));
        $order->save();

        return true;
    }

    public function create(): void
    {
        $order = $this->loadOrder('madeit_inpost_create');
        $point = isset($_POST['point']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['point']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in loadOrder().
        $size = Calculator::normalizeSize(isset($_POST['size']) ? sanitize_text_field(wp_unslash($_POST['size'])) : 'A'); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $result = $this->createShipment($order, $point, $size);
        wp_safe_redirect(is_wp_error($result) ? $this->backUrl($order, 'error', $result->get_error_message()) : $this->backUrl($order, 'created'));
        exit;
    }

    public function refresh(): void
    {
        $order = $this->loadOrder('madeit_inpost_refresh');
        $result = $this->refreshShipment($order);
        wp_safe_redirect(is_wp_error($result) ? $this->backUrl($order, 'error', $result->get_error_message()) : $this->backUrl($order, 'refreshed'));
        exit;
    }

    public function label(): void
    {
        $order = $this->loadOrder('madeit_inpost_label');
        $pdf = self::shipx()->label((string) $order->get_meta(self::META_SHIPMENT));
        if (is_wp_error($pdf)) {
            wp_safe_redirect($this->backUrl($order, 'error', $pdf->get_error_message()));
            exit;
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="inpost-'.absint($order->get_id()).'.pdf"');
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a binary PDF.
        exit;
    }

    public function notices(): void
    {
        $notice = isset($_GET['madeit_inpost_notice']) ? sanitize_key(wp_unslash($_GET['madeit_inpost_notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects a message.
        if ($notice === '') {
            return;
        }
        if ($notice === 'error') {
            $detail = (string) get_transient('madeit_inpost_error_'.get_current_user_id());
            delete_transient('madeit_inpost_error_'.get_current_user_id());
            echo '<div class="notice notice-error"><p>'.esc_html(sprintf(/* translators: %s: error message */ __('InPost: %s', 'madeit-inpost-pro'), $detail !== '' ? $detail : __('the operation failed.', 'madeit-inpost-pro'))).'</p></div>';
        } elseif (in_array($notice, ['created', 'refreshed'], true)) {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($notice === 'created' ? __('The InPost shipment was created.', 'madeit-inpost-pro') : __('The shipment status was refreshed.', 'madeit-inpost-pro')).'</p></div>';
        }
    }
}
