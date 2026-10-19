<?php

/**
 * Shipment logic without WordPress. Run: php tests/calculator.php
 */
define('ABSPATH', __DIR__);

require __DIR__.'/../src/Calculator.php';

use Madeit\InpostPro\Calculator;

function expectThat(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

expectThat(Calculator::normalizePhone('+48 512-345-678') === '512345678', 'The +48 prefix is removed.');
expectThat(Calculator::normalizePhone('0048512345678') === '512345678', 'The 0048 prefix is removed.');
expectThat(Calculator::normalizePhone('512 345 678') === '512345678', 'Spaces are removed.');
expectThat(Calculator::isValidPhone('512345678') && ! Calculator::isValidPhone('0123') && ! Calculator::isValidPhone('012345678'), 'Phone number validation.');

expectThat(Calculator::normalizeSize('b') === 'B' && Calculator::normalizeSize('Z') === 'A', 'Parcel size.');

$payload = Calculator::shipmentPayload([
    'first_name' => 'Anna', 'last_name' => 'Nowak', 'email' => 'anna@example.com', 'phone' => '+48 512 345 678',
    'point' => 'WAW01M', 'size' => 'C', 'reference' => 'Order 123', 'cod' => 0.0, 'currency' => 'PLN', 'sending_method' => 'parcel_locker',
]);
expectThat($payload['parcels'][0]['template'] === 'large', 'Size C is large.');
expectThat($payload['receiver']['phone'] === '512345678', 'The phone number is normalised.');
expectThat($payload['custom_attributes'] === ['target_point' => 'WAW01M', 'sending_method' => 'parcel_locker'], 'Point and sending method.');
expectThat(! isset($payload['cod']), 'Without cash on delivery there is no cod field.');
$cod = Calculator::shipmentPayload(['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.pl', 'phone' => '512345678', 'point' => 'WAW01M', 'size' => 'A', 'reference' => 'x', 'cod' => 99.9, 'currency' => 'PLN', 'sending_method' => 'bogus']);
expectThat($cod['cod'] === ['amount' => 99.9, 'currency' => 'PLN'], 'Cash on delivery with an amount and a currency.');
expectThat($cod['custom_attributes']['sending_method'] === 'dispatch_order', 'An unknown sending method falls back to courier pickup.');

echo "OK\n";
