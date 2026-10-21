<?php

namespace Madeit\InpostPro;

defined('ABSPATH') || exit;

final class Updater
{
    public const PAGE = 'madeit-inpost-license';

    private string $basename;

    private string $lastError = '';

    private int $lastCode = 0;

    private bool $lastWasUnknownKey = false;

    public function __construct(private string $pluginFile, private string $apiUrl)
    {
        $this->basename = plugin_basename($pluginFile);
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
        add_filter('upgrader_pre_download', [$this, 'download'], 10, 4);
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_madeit_inpost_pro_license', [$this, 'saveLicense']);
    }

    private function request(string $path): ?array
    {
        $key = (string) get_option('madeit_inpost_pro_license_key', '');
        if ($key === '' || str_ends_with($this->apiUrl, '.invalid')) {
            return null;
        }
        if (! $this->isSecureUrl($this->apiUrl)) {
            $this->lastError = __('The MadeIT Labs address must use HTTPS.', 'madeit-inpost-pro');

            return null;
        }
        $response = wp_remote_post(rtrim($this->apiUrl, '/').'/api/v1/'.$path, [
            'timeout' => 8,
            'body' => ['license_key' => $key, 'site_url' => home_url('/')],
        ]);
        if (is_wp_error($response)) {
            $this->lastError = sprintf(/* translators: %s: error message */ __('Could not connect to MadeIT Labs (%s).', 'madeit-inpost-pro'), $response->get_error_message());

            return null;
        }
        $code = wp_remote_retrieve_response_code($response);
        $this->lastCode = $code;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        // Laravel answers an unknown key with a "No query results for model [..License]" 404;
        // a missing route is a 404 too, and must not be reported as a bad key.
        $this->lastWasUnknownKey = $code === 404 && is_array($data) && str_contains((string) ($data['message'] ?? ''), 'License');
        if ($code !== 200) {
            $this->lastError = $this->describeFailure($code, is_array($data) ? (string) ($data['message'] ?? '') : '');

            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function describeFailure(int $code, string $message): string
    {
        return match (true) {
            $code === 404 && $this->lastWasUnknownKey => __('No such license key was found.', 'madeit-inpost-pro'),
            $code === 429 => __('Too many attempts. Try again in a minute.', 'madeit-inpost-pro'),
            $code === 403 && $message !== '' => $message,
            $code === 403 => __('This license is not allowed to do that.', 'madeit-inpost-pro'),
            $code === 422 => __('The server rejected the data (site address or key).', 'madeit-inpost-pro'),
            default => sprintf(/* translators: %d: HTTP status code */ __('The MadeIT Labs server returned error %d.', 'madeit-inpost-pro'), $code),
        };
    }

    public function check($transient)
    {
        if (! isset($transient->checked[$this->basename])) {
            return $transient;
        }
        $data = $this->request('updates/check');
        if (! isset($data['version'], $data['download_url']) || ! version_compare($data['version'], $transient->checked[$this->basename], '>')) {
            return $transient;
        }
        $transient->response[$this->basename] = (object) [
            'id' => $this->apiUrl.'/updates/madeit-inpost-pro',
            'slug' => 'madeit-inpost-pro',
            'plugin' => $this->basename,
            'new_version' => $data['version'],
            'package' => $data['download_url'],
            'url' => $this->apiUrl,
            'requires' => $data['requires_wp'] ?? '',
            'requires_php' => $data['requires_php'] ?? '',
        ];

        return $transient;
    }

    public function pluginInformation($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? null) !== 'madeit-inpost-pro') {
            return $result;
        }
        $data = $this->request('updates/check');
        if (! isset($data['version'])) {
            return $result;
        }

        return (object) [
            'name' => 'MadeIT InPost Pro',
            'slug' => 'madeit-inpost-pro',
            'version' => $data['version'],
            'author' => 'MadeIT Labs',
            'requires' => $data['requires_wp'] ?? '',
            'requires_php' => $data['requires_php'] ?? '',
            'download_link' => $data['download_url'] ?? '',
            'sections' => ['changelog' => wpautop(esc_html((string) ($data['changelog'] ?? '')))],
        ];
    }

    public function download($reply, $package, $upgrader, $hookExtra)
    {
        if (($hookExtra['plugin'] ?? null) !== $this->basename) {
            return $reply;
        }
        $data = $this->request('updates/check');
        if (empty($data['download_url'])) {
            return new \WP_Error('madeit_inpost_pro_update', __('The update could not be downloaded. Check your license and the connection to MadeIT Labs.', 'madeit-inpost-pro'));
        }

        $package = (string) $data['download_url'];
        $expected = strtolower((string) ($data['sha256'] ?? ''));
        if (! $this->isSecureUrl($package) || wp_parse_url($package, PHP_URL_HOST) !== wp_parse_url($this->apiUrl, PHP_URL_HOST) || ! preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return new \WP_Error('madeit_inpost_pro_update', __('The update package was rejected because it could not be verified.', 'madeit-inpost-pro'));
        }
        $file = download_url($package);
        if (is_wp_error($file)) {
            return $file;
        }
        if (! hash_equals($expected, strtolower((string) hash_file('sha256', $file)))) {
            wp_delete_file($file);

            return new \WP_Error('madeit_inpost_pro_update', __('The downloaded update package failed the integrity check and was not installed.', 'madeit-inpost-pro'));
        }

        return $file;
    }

    /**
     * HTTPS is required, except for local development hosts.
     */
    private function isSecureUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') === 'https' && $host !== '') {
            return true;
        }

        return ($parts['scheme'] ?? '') === 'http' && ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test'));
    }

    /**
     * Current license state as reported by MadeIT Labs. Cached for a few minutes unless $refresh is true.
     *
     * @return array{state: string, message?: string, expires_at?: string, sites_used?: int, site_limit?: int, site_active?: bool}
     */
    public function licenseStatus(bool $refresh = false): array
    {
        $key = (string) get_option('madeit_inpost_pro_license_key', '');
        if ($key === '') {
            return ['state' => 'none'];
        }
        if (str_ends_with($this->apiUrl, '.invalid')) {
            return ['state' => 'unavailable'];
        }
        $fingerprint = md5($key.home_url('/'));
        $cached = get_transient('madeit_inpost_pro_license_status');
        if (! $refresh && is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint) {
            return $cached;
        }
        $this->lastCode = 0;
        $this->lastWasUnknownKey = false;
        $data = $this->request('licenses/status');
        if (! is_array($data) || ! isset($data['state'])) {
            return $this->lastWasUnknownKey
                ? ['state' => 'unknown', 'message' => __('The server does not know this license key.', 'madeit-inpost-pro')]
                : ['state' => 'unreachable', 'message' => $this->lastError ?: __('Could not check the license status.', 'madeit-inpost-pro')];
        }
        $data['fingerprint'] = $fingerprint;
        set_transient('madeit_inpost_pro_license_status', $data, 5 * MINUTE_IN_SECONDS);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array{tone: string, icon: string, title: string, text: string, updates: string}
     */
    private function describeStatus(array $status): array
    {
        $date = isset($status['expires_at']) ? wp_date(get_option('date_format'), strtotime((string) $status['expires_at'])) : '';
        $renew = esc_url(rtrim($this->apiUrl, '/').'/en/account');
        $sites = ['used' => (int) ($status['sites_used'] ?? 0), 'limit' => (int) ($status['site_limit'] ?? 0)];

        return match ($status['state'] ?? '') {
            'none' => ['tone' => 'idle', 'icon' => '—', 'title' => __('No key', 'madeit-inpost-pro'), 'text' => __('Paste your license key to activate this shop and receive updates.', 'madeit-inpost-pro'), 'updates' => __('Unavailable without a license', 'madeit-inpost-pro')],
            'unavailable' => ['tone' => 'idle', 'icon' => '—', 'title' => __('Key saved', 'madeit-inpost-pro'), 'text' => __('The connection to MadeIT Labs is not configured yet.', 'madeit-inpost-pro'), 'updates' => __('Waiting for configuration', 'madeit-inpost-pro')],
            'unreachable' => ['tone' => 'warn', 'icon' => '!', 'title' => __('Could not check the license', 'madeit-inpost-pro'), 'text' => (string) ($status['message'] ?? __('Could not connect to MadeIT Labs.', 'madeit-inpost-pro')), 'updates' => __('Could not be determined', 'madeit-inpost-pro')],
            'unknown' => ['tone' => 'error', 'icon' => '×', 'title' => __('Unknown key', 'madeit-inpost-pro'), 'text' => __('The server does not know this license key. Check that you pasted all of it.', 'madeit-inpost-pro'), 'updates' => __('Unavailable', 'madeit-inpost-pro')],
            /* translators: %s: date */
            'expired' => ['tone' => 'warn', 'icon' => '!', 'title' => sprintf(__('License expired on %s', 'madeit-inpost-pro'), $date), 'text' => sprintf(/* translators: %s: link to the customer account */ __('The add-on keeps working, but new versions are no longer available. %s.', 'madeit-inpost-pro'), '<a href="'.$renew.'" target="_blank" rel="noopener">'.esc_html__('Renew your license in the customer account', 'madeit-inpost-pro').'</a>'), 'updates' => __('Paused until renewal', 'madeit-inpost-pro')],
            'revoked' => ['tone' => 'error', 'icon' => '×', 'title' => __('License blocked', 'madeit-inpost-pro'), 'text' => __('This license has been blocked. Contact MadeIT Labs support.', 'madeit-inpost-pro'), 'updates' => __('Unavailable', 'madeit-inpost-pro')],
            'active' => ! empty($status['site_active'])
                ? ['tone' => 'ok', 'icon' => '✓', 'title' => __('License active', 'madeit-inpost-pro'), 'text' => sprintf(/* translators: 1: date, 2: sites in use, 3: site limit */ __('Valid until %1$s. Active sites: %2$d of %3$d.', 'madeit-inpost-pro'), $date, $sites['used'], $sites['limit']), 'updates' => sprintf(/* translators: %s: date */ __('Available until %s', 'madeit-inpost-pro'), $date)]
                : ['tone' => 'warn', 'icon' => '!', 'title' => __('Shop not activated', 'madeit-inpost-pro'), 'text' => sprintf(/* translators: 1: date, 2: sites in use, 3: site limit */ __('The license is valid until %1$s, but this shop is not assigned to it (sites: %2$d of %3$d). Click “Save and activate”.', 'madeit-inpost-pro'), $date, $sites['used'], $sites['limit']), 'updates' => __('Available after activation', 'madeit-inpost-pro')],
            default => ['tone' => 'warn', 'icon' => '!', 'title' => __('Unknown license state', 'madeit-inpost-pro'), 'text' => __('The server response was not recognised.', 'madeit-inpost-pro'), 'updates' => __('Could not be determined', 'madeit-inpost-pro')],
        };
    }

    public function menu(): void
    {
        add_submenu_page('madeit-inpost', __('MadeIT InPost Pro license', 'madeit-inpost-pro'), __('License', 'madeit-inpost-pro'), 'manage_woocommerce', self::PAGE, [$this, 'settings']);
    }

    public function settings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-inpost-pro'));
        }
        $key = (string) get_option('madeit_inpost_pro_license_key', '');
        $apiReady = ! str_ends_with($this->apiUrl, '.invalid');
        // The notice key only selects one of the fixed messages below.
        $notice = isset($_GET['madeit_notice']) ? sanitize_key(wp_unslash($_GET['madeit_notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $messages = [
            'active' => __('The license was activated for this shop.', 'madeit-inpost-pro'),
            'failed' => __('The license could not be activated. Check the key and the connection to MadeIT Labs.', 'madeit-inpost-pro'),
            'unavailable' => __('The key was saved. Activation will be available once the public MadeIT Labs address is connected.', 'madeit-inpost-pro'),
            'cleared' => __('The license key was removed.', 'madeit-inpost-pro'),
        ];
        $detail = $notice === 'failed' ? (string) get_transient('madeit_inpost_pro_license_error') : '';
        // The screen is rarely opened and should show the current state, so the server is always asked (no cache).
        $info = $this->describeStatus($this->licenseStatus(true));
        ?>
        <div class="wrap madeit-admin">
            <div class="madeit-hero">
                <div>
                    <span class="madeit-eyebrow"><?php esc_html_e('MADEIT INPOST PRO / ACCESS', 'madeit-inpost-pro'); ?></span>
                    <h1><?php esc_html_e('License', 'madeit-inpost-pro'); ?></h1>
                    <p><?php esc_html_e('Manage the license key and updates of the add-on for this shop.', 'madeit-inpost-pro'); ?></p>
                </div>
                <a class="madeit-button madeit-button-light" href="<?php echo esc_url(admin_url('admin.php?page=madeit-inpost')); ?>">← <?php esc_html_e('Back to settings', 'madeit-inpost-pro'); ?></a>
            </div>
            <?php if (isset($messages[$notice])) { ?>
                <div class="madeit-notice<?php echo esc_attr($notice === 'failed' ? ' madeit-notice-error' : ''); ?>" role="status"><?php echo esc_html($messages[$notice].($detail !== '' ? ' '.$detail : '')); ?></div>
            <?php } ?>
            <div class="madeit-license-layout">
                <section class="madeit-card madeit-license-card">
                    <span class="madeit-eyebrow"><?php esc_html_e('LICENSE KEY', 'madeit-inpost-pro'); ?></span>
                    <h2><?php esc_html_e('Connect this shop to MadeIT Labs', 'madeit-inpost-pro'); ?></h2>
                    <p><?php esc_html_e('You will find the key in your customer account after purchase. One license covers one shop.', 'madeit-inpost-pro'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="madeit_inpost_pro_license">
                        <?php wp_nonce_field('madeit_inpost_pro_license', 'madeit_inpost_pro_license_nonce'); ?>
                        <label><span><?php esc_html_e('License key', 'madeit-inpost-pro'); ?></span><input type="password" name="madeit_inpost_pro_license_key" value="<?php echo esc_attr($key); ?>" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Paste your license key', 'madeit-inpost-pro'); ?>"></label>
                        <button class="madeit-button madeit-button-primary" type="submit"><?php echo esc_html($apiReady ? __('Save and activate', 'madeit-inpost-pro') : __('Save key', 'madeit-inpost-pro')); ?></button>
                    </form>
                </section>
                <aside class="madeit-card madeit-license-info">
                    <span class="madeit-eyebrow"><?php esc_html_e('STATUS', 'madeit-inpost-pro'); ?></span>
                    <div class="madeit-license-state"><span class="madeit-state-icon is-<?php echo esc_attr($info['tone']); ?>"><?php echo esc_html($info['icon']); ?></span><div><strong><?php echo esc_html($info['title']); ?></strong><p><?php echo wp_kses($info['text'], ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></p></div></div>
                    <div class="madeit-license-meta"><span><?php esc_html_e('Shop address', 'madeit-inpost-pro'); ?></span><strong><?php echo esc_html(home_url('/')); ?></strong></div>
                    <div class="madeit-license-meta"><span><?php esc_html_e('Updates', 'madeit-inpost-pro'); ?></span><strong><?php echo esc_html($info['updates']); ?></strong></div>
                </aside>
            </div>
        </div>
        <?php
    }

    public function saveLicense(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-inpost-pro'));
        }
        check_admin_referer('madeit_inpost_pro_license', 'madeit_inpost_pro_license_nonce');
        $key = isset($_POST['madeit_inpost_pro_license_key']) ? sanitize_text_field(wp_unslash($_POST['madeit_inpost_pro_license_key'])) : '';
        if ($key === '' && ! str_ends_with($this->apiUrl, '.invalid')) {
            // Free the license slot on MadeIT Labs before the key is forgotten.
            $this->request('licenses/deactivate');
        }
        $key = preg_replace('/\s+/', '', $key) ?? $key;
        if ($key !== '' && ! preg_match('/^[A-Za-z0-9]{32}$/', $key)) {
            set_transient('madeit_inpost_pro_license_error', sprintf(/* translators: %d: number of characters entered */ __('This does not look like a license key. A key has 32 letters and digits, the value you entered has %d.', 'madeit-inpost-pro'), strlen($key)), 60);
            wp_safe_redirect(admin_url('admin.php?page=madeit-inpost-license&madeit_notice=failed'));
            exit;
        }
        update_option('madeit_inpost_pro_license_key', $key, false);
        delete_transient('madeit_inpost_pro_license_error');
        delete_transient('madeit_inpost_pro_license_status');
        $notice = $key === '' ? 'cleared' : (str_ends_with($this->apiUrl, '.invalid') ? 'unavailable' : (($this->request('licenses/activate')['status'] ?? null) === 'active' ? 'active' : 'failed'));
        if ($notice === 'failed' && $this->lastError !== '') {
            set_transient('madeit_inpost_pro_license_error', $this->lastError, 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=madeit-inpost-license&madeit_notice='.$notice));
        exit;
    }
}
