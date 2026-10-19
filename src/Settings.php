<?php

namespace Madeit\InpostPro;

defined('ABSPATH') || exit;

/**
 * Settings of the Pro add-on: the ShipX connection, the shipment defaults, the accent colour and the texts.
 */
final class Settings
{
    /**
     * @return array{organization_id: string, api_token: string, default_size: string, sending_method: string, accent: string}
     */
    public static function defaults(): array
    {
        return ['organization_id' => '', 'api_token' => '', 'default_size' => 'A', 'sending_method' => 'dispatch_order', 'accent' => '#294a74'];
    }

    /**
     * @return array<string, string>
     */
    public static function get(): array
    {
        $defaults = self::defaults();
        $saved = get_option('madeit_inpost_pro_settings', []);
        $settings = array_merge($defaults, is_array($saved) ? array_intersect_key($saved, $defaults) : []);
        $settings = array_map(static fn ($value): string => trim((string) $value), $settings);
        $settings['organization_id'] = preg_replace('/\D+/', '', $settings['organization_id']) ?? '';
        $settings['default_size'] = Calculator::normalizeSize($settings['default_size']);
        $settings['sending_method'] = $settings['sending_method'] === 'parcel_locker' ? 'parcel_locker' : 'dispatch_order';
        $settings['accent'] = sanitize_hex_color($settings['accent']) ?: $defaults['accent'];

        return $settings;
    }
}
