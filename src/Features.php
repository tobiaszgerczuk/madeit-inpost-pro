<?php

namespace Madeit\InpostPro;

use Madeit\Inpost\Admin as FreeAdmin;
use Madeit\Inpost\Plugin as FreePlugin;

defined('ABSPATH') || exit;

// The tabs only select what a read-only screen shows; every change goes through admin-post.php with a nonce.
// phpcs:disable WordPress.Security.NonceVerification.Missing

/**
 * The Pro features, attached to MadeIT InPost through its public filters and actions.
 */
final class Features
{
    public function register(): void
    {
        add_filter('madeit_inpost_settings', [$this, 'settings']);
        add_filter('madeit_inpost_content', [$this, 'content']);
        add_filter('madeit_inpost_settings_tabs', [$this, 'tabs']);
        add_filter('madeit_inpost_admin_pages', [$this, 'adminPages']);
        add_action('madeit_inpost_hero', [$this, 'heroLink']);
        add_action('madeit_inpost_render_settings_tab', [$this, 'renderTab']);
        add_action('admin_post_madeit_inpost_pro_save', [$this, 'save']);
        (new Orders)->register();
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, string>
     */
    public function settings(array $settings): array
    {
        $saved = get_option('madeit_inpost_pro_settings', []);
        $accent = is_array($saved) ? sanitize_hex_color((string) ($saved['accent'] ?? '')) : null;
        if ($accent) {
            $settings['accent'] = $accent;
        }

        return $settings;
    }

    /**
     * @param  array<string, string>  $content
     * @return array<string, string>
     */
    public function content(array $content): array
    {
        $saved = get_option('madeit_inpost_pro_content', []);
        if (! is_array($saved)) {
            return $content;
        }
        foreach (array_keys($content) as $key) {
            if (isset($saved[$key]) && is_string($saved[$key]) && trim($saved[$key]) !== '') {
                $content[$key] = $saved[$key];
            }
        }

        return $content;
    }

    /**
     * @param  array<string, string>  $tabs
     * @return array<string, string>
     */
    public function tabs(array $tabs): array
    {
        return $tabs + [
            'shipments' => __('Shipments', 'madeit-inpost-pro'),
            'appearance' => __('Appearance', 'madeit-inpost-pro'),
            'content' => __('Texts', 'madeit-inpost-pro'),
        ];
    }

    /**
     * @param  array<int, string>  $pages
     * @return array<int, string>
     */
    public function adminPages(array $pages): array
    {
        $pages[] = Updater::PAGE;

        return $pages;
    }

    public function heroLink(): void
    {
        echo '<a class="madeit-button madeit-button-light" href="'.esc_url(admin_url('admin.php?page='.Updater::PAGE)).'">'.esc_html__('License', 'madeit-inpost-pro').' <span aria-hidden="true">↗</span></a>';
    }

    public function renderTab(string $tab): void
    {
        if ($tab === 'shipments') {
            $this->shipmentsTab();
        } elseif ($tab === 'appearance') {
            $this->appearanceTab();
        } elseif ($tab === 'content') {
            $this->contentTab();
        }
    }

    private function formOpen(string $tab, string $class = 'madeit-settings-form'): void
    {
        echo '<form class="'.esc_attr($class).'" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="madeit_inpost_pro_save"><input type="hidden" name="tab" value="'.esc_attr($tab).'">';
        wp_nonce_field('madeit_inpost_pro_save');
    }

    private function shipmentsTab(): void
    {
        $settings = Settings::get();
        $sandbox = FreePlugin::settings()['sandbox'] === 'yes';
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>'.esc_html__('Shipments and labels', 'madeit-inpost-pro').'</h2><p>'.esc_html__('The ShipX data is in the InPost Parcel Manager, on the API tab. The map token and the ShipX token are two different keys.', 'madeit-inpost-pro').'</p></div></div>';
        $this->formOpen('shipments');
        echo '<label><span>'.esc_html__('ShipX organization ID', 'madeit-inpost-pro').'</span><input type="text" name="organization_id" value="'.esc_attr($settings['organization_id']).'" inputmode="numeric" autocomplete="off"></label>';
        echo '<label><span>'.esc_html__('ShipX API token', 'madeit-inpost-pro').'</span><input type="password" name="api_token" value="'.esc_attr($settings['api_token']).'" autocomplete="off" spellcheck="false"><small>'.esc_html__('Needed to create shipments and download labels. The token gives access to your InPost account, so do not share it with anyone.', 'madeit-inpost-pro').'</small></label>';
        echo '<label><span>'.esc_html__('Default parcel size', 'madeit-inpost-pro').'</span><select name="default_size">';
        foreach (Orders::sizeOptions() as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($settings['default_size'], $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select><small>'.esc_html__('You can change it for every order before the shipment is created.', 'madeit-inpost-pro').'</small></label>';
        echo '<label><span>'.esc_html__('Sending method', 'madeit-inpost-pro').'</span><select name="sending_method">';
        foreach (['dispatch_order' => __('Courier pickup', 'madeit-inpost-pro'), 'parcel_locker' => __('Drop-off in a parcel locker', 'madeit-inpost-pro')] as $value => $label) {
            echo '<option value="'.esc_attr($value).'"'.selected($settings['sending_method'], $value, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label>';
        /* translators: %s: environment name, Sandbox or Production */
        echo '<p class="description">'.esc_html(sprintf(__('Environment: %s. Switch it with the test mode option on the Map tab.', 'madeit-inpost-pro'), $sandbox ? __('Sandbox', 'madeit-inpost-pro') : __('Production', 'madeit-inpost-pro'))).'</p>';
        echo '<button class="madeit-button madeit-button-primary" type="submit">'.esc_html__('Save shipments', 'madeit-inpost-pro').'</button></form></section>';
    }

    private function appearanceTab(): void
    {
        $settings = FreePlugin::settings();
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>'.esc_html__('Picker appearance', 'madeit-inpost-pro').'</h2><p>'.esc_html__('The colour of the button and the frame. The background follows your theme.', 'madeit-inpost-pro').'</p></div></div>';
        echo '<div class="madeit-settings-layout">';
        $this->formOpen('appearance');
        echo '<label><span>'.esc_html__('Accent colour', 'madeit-inpost-pro').'</span><input type="color" name="accent" value="'.esc_attr($settings['accent']).'"></label>';
        echo '<button class="madeit-button madeit-button-primary" type="submit">'.esc_html__('Save appearance', 'madeit-inpost-pro').'</button></form>';
        echo '<div class="madeit-settings-preview">'.FreeAdmin::preview($settings, FreePlugin::content()).'</div></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while the preview is built.
    }

    private function contentTab(): void
    {
        $content = FreePlugin::content();
        $fields = [
            'hint' => [__('Hint above the button', 'madeit-inpost-pro'), __('A short explanation of what the customer should do.', 'madeit-inpost-pro')],
            'button_choose' => [__('Button before choosing', 'madeit-inpost-pro'), __('The text on the button that opens the map.', 'madeit-inpost-pro')],
            'button_change' => [__('Button after choosing', 'madeit-inpost-pro'), __('The text on the button when a point is already chosen.', 'madeit-inpost-pro')],
            'selected' => [__('Message about the chosen point', 'madeit-inpost-pro'), __('Placeholders: {point} (point code), {address} (address).', 'madeit-inpost-pro')],
            'missing' => [__('Error when nothing is chosen', 'madeit-inpost-pro'), __('Shown when the customer tries to order without a parcel locker.', 'madeit-inpost-pro')],
        ];
        echo '<section class="madeit-card madeit-settings-card"><div class="madeit-section-heading"><div><h2>'.esc_html__('Texts shown to customers', 'madeit-inpost-pro').'</h2><p>'.esc_html__('Available placeholders: {point}, {address}.', 'madeit-inpost-pro').'</p></div></div>';
        echo '<div class="madeit-settings-layout">';
        $this->formOpen('content');
        foreach ($fields as $key => [$label, $hint]) {
            echo '<label><span>'.esc_html($label).'</span><textarea name="'.esc_attr($key).'" rows="2">'.esc_textarea($content[$key]).'</textarea><small>'.esc_html($hint).'</small></label>';
        }
        echo '<button class="madeit-button madeit-button-primary" type="submit">'.esc_html__('Save texts', 'madeit-inpost-pro').'</button></form>';
        echo '<div class="madeit-settings-preview">'.FreeAdmin::preview(FreePlugin::settings(), $content).'</div></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while the preview is built.
    }

    public function save(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-inpost-pro'));
        }
        check_admin_referer('madeit_inpost_pro_save');
        $tab = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : 'shipments';
        if ($tab === 'shipments') {
            $settings = Settings::get();
            $settings['organization_id'] = isset($_POST['organization_id']) ? (string) preg_replace('/\D+/', '', sanitize_text_field(wp_unslash($_POST['organization_id']))) : '';
            $settings['api_token'] = isset($_POST['api_token']) ? trim(sanitize_text_field(wp_unslash($_POST['api_token']))) : '';
            $settings['default_size'] = Calculator::normalizeSize(isset($_POST['default_size']) ? sanitize_text_field(wp_unslash($_POST['default_size'])) : 'A');
            $method = isset($_POST['sending_method']) ? sanitize_key(wp_unslash($_POST['sending_method'])) : '';
            $settings['sending_method'] = $method === 'parcel_locker' ? 'parcel_locker' : 'dispatch_order';
            update_option('madeit_inpost_pro_settings', $settings, false);
        } elseif ($tab === 'appearance') {
            $settings = Settings::get();
            $accent = isset($_POST['accent']) ? sanitize_hex_color(wp_unslash($_POST['accent'])) : null;
            $settings['accent'] = $accent ?: Settings::defaults()['accent'];
            update_option('madeit_inpost_pro_settings', $settings, false);
        } else {
            $tab = 'content';
            $defaults = FreePlugin::contentDefaults();
            $content = [];
            foreach ($defaults as $key => $default) {
                $value = isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : $default;
                $content[$key] = trim($value) === '' ? $default : $value;
            }
            update_option('madeit_inpost_pro_content', $content, false);
        }
        wp_safe_redirect(admin_url('admin.php?page='.FreePlugin::PAGE.'&tab='.$tab.'&madeit_notice=settings'));
        exit;
    }
}
