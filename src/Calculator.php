<?php

namespace Madeit\InpostPro;

defined('ABSPATH') || exit;

/**
 * Pure logic of the shipments: parcel sizes, the receiver's phone number and the ShipX payload. No WordPress needed.
 */
final class Calculator
{
    public const SIZES = ['A' => 'small', 'B' => 'medium', 'C' => 'large'];

    public static function normalizeSize(string $size): string
    {
        $size = strtoupper(trim($size));

        return array_key_exists($size, self::SIZES) ? $size : 'A';
    }

    /**
     * A phone number in the ShipX format: 9 digits without the country prefix.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '48')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '0048')) {
            $digits = substr($digits, 4);
        }

        return $digits;
    }

    public static function isValidPhone(string $phone): bool
    {
        return preg_match('/^[1-9]\d{8}$/', self::normalizePhone($phone)) === 1;
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: string, point: string, size: string, reference: string, cod: float, currency: string, sending_method: string}  $input
     * @return array<string, mixed>
     */
    public static function shipmentPayload(array $input): array
    {
        $payload = [
            'receiver' => [
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'phone' => self::normalizePhone($input['phone']),
            ],
            'parcels' => [['template' => self::SIZES[self::normalizeSize($input['size'])]]],
            'service' => 'inpost_locker_standard',
            'custom_attributes' => [
                'target_point' => $input['point'],
                'sending_method' => $input['sending_method'] === 'parcel_locker' ? 'parcel_locker' : 'dispatch_order',
            ],
            'reference' => mb_substr($input['reference'], 0, 100),
        ];
        if ($input['cod'] > 0) {
            $payload['cod'] = ['amount' => round($input['cod'], 2), 'currency' => $input['currency']];
        }

        return $payload;
    }
}
