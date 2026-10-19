<?php
/**
 * Plugin Name: MadeIT InPost Pro
 * Plugin URI: https://labs.made-it.pl/en/plugins/madeit-inpost-pro
 * Description: Adds InPost ShipX shipments, PDF labels and cash on delivery to MadeIT InPost, plus editable texts and an accent colour.
 * Version: 1.0.0
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce, madeit-inpost
 * Author: MadeIT Labs
 * Author URI: https://labs.made-it.pl/en
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: madeit-inpost-pro
 * Domain Path: /languages
 * Update URI: https://labs.made-it.pl/updates/madeit-inpost-pro
 */

use Madeit\Inpost\Plugin;
use Madeit\InpostPro\Features;
use Madeit\InpostPro\Updater;

defined('ABSPATH') || exit;

define('MADEIT_INPOST_PRO_VERSION', '1.0.0');
// Oldest free plugin version this add-on works with. Raise it when the add-on needs a newer hook from the free plugin.
define('MADEIT_INPOST_PRO_MIN_FREE_VERSION', '1.0.0');

require_once __DIR__.'/src/Calculator.php';
require_once __DIR__.'/src/Settings.php';
require_once __DIR__.'/src/ShipX.php';
require_once __DIR__.'/src/Orders.php';
require_once __DIR__.'/src/Features.php';
require_once __DIR__.'/src/Updater.php';

add_action('plugins_loaded', static function (): void {
    if (! class_exists(Plugin::class)) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'.esc_html__('MadeIT InPost Pro needs the free MadeIT InPost plugin to be installed and active.', 'madeit-inpost-pro').'</p></div>';
        });

        return;
    }

    if (version_compare((string) (defined('MADEIT_INPOST_VERSION') ? MADEIT_INPOST_VERSION : '0'), MADEIT_INPOST_PRO_MIN_FREE_VERSION, '<')) {
        add_action('admin_notices', static function (): void {
            /* translators: %s: minimum version of the free plugin */
            echo '<div class="notice notice-error"><p>'.esc_html(sprintf(__('MadeIT InPost Pro needs MadeIT InPost %s or newer. Please update the free plugin.', 'madeit-inpost-pro'), MADEIT_INPOST_PRO_MIN_FREE_VERSION)).'</p></div>';
        });

        return;
    }

    (new Features)->register();
    $apiUrl = defined('WCO_LABS_API_URL') ? WCO_LABS_API_URL : 'https://labs.made-it.pl';
    (new Updater(__FILE__, $apiUrl))->register();
}, 20);
