<?php
/**
 * LOIQ WP Agent Updater
 *
 * Handles secure plugin updates with Ed25519 signature verification.
 * Replaces the previous GitHub-based updater with cryptographically signed
 * updates via Vercel for supply-chain security.
 *
 * Security model:
 * - FAIL-CLOSED: Updates blocked if signature missing or invalid
 * - Ed25519 signatures verify update authenticity
 * - SHA-256 checksums verify download integrity
 * - Domain pinning restricts download sources
 *
 * @package LOIQ_WP_Agent
 */

if (!defined('ABSPATH')) exit;

class LOIQ_WP_Agent_Signed_Updater {

    private $update_url = 'https://loiq-wp-agent.vercel.app/update.json';

    private $allowed_download_hosts = [
        'loiq-wp-agent.vercel.app',
        'loiq-wp-agent-*.vercel.app',
    ];

    private $signing_public_keys = [
        'key-2026-01' => 'p+4jCmIz3WnjHR7JUAO4Inm9XA4qD51x+1GKBInr3no=',
    ];

    private $default_key_id = 'key-2026-01';
    private $plugin_file;

    public function __construct() {
        $this->plugin_file = plugin_basename(LOIQ_AGENT_PATH . 'loiq-wp-agent.php');

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_action('upgrader_process_complete', [$this, 'after_update'], 10, 2);
        add_filter('upgrader_pre_download', [$this, 'verify_update_hash'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);

        add_filter('plugin_action_links_' . $this->plugin_file, [$this, 'add_check_update_link']);
        add_action('admin_init', [$this, 'handle_force_update_check']);

        $this->maybe_clear_update_cache();
    }

    private function maybe_clear_update_cache(): void {
        $cached_version = get_option('loiq_agent_installed_version', '');
        if ($cached_version !== LOIQ_AGENT_VERSION) {
            delete_transient('loiq_agent_signed_update_info');
            delete_site_transient('update_plugins');
            update_option('loiq_agent_installed_version', LOIQ_AGENT_VERSION);
        }
    }

    public function add_check_update_link(array $links): array {
        $check_url = wp_nonce_url(
            admin_url('plugins.php?loiq_agent_force_update_check=1'),
            'loiq_agent_force_check'
        );
        $links['check_update'] = '<a href="' . esc_url($check_url) . '">' .
                                  __('Check for updates', 'loiq-wp-agent') . '</a>';
        return $links;
    }

    public function handle_force_update_check(): void {
        if (empty($_GET['loiq_agent_force_update_check'])) return;
        if (!current_user_can('update_plugins')) return;
        if (!wp_verify_nonce($_GET['_wpnonce'], 'loiq_agent_force_check')) return;

        $last_check = get_transient('loiq_agent_last_force_check');
        if ($last_check) {
            wp_redirect(admin_url('plugins.php?loiq_agent_checked=rate_limited'));
            exit;
        }

        set_transient('loiq_agent_last_force_check', time(), 5 * MINUTE_IN_SECONDS);

        delete_transient('loiq_agent_signed_update_info');
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        wp_redirect(admin_url('plugins.php?loiq_agent_checked=1'));
        exit;
    }

    public function check_for_update(object $transient): object {
        if (empty($transient->checked)) return $transient;

        $remote = $this->get_remote_info();
        if (!$remote) return $transient;

        if (version_compare(LOIQ_AGENT_VERSION, $remote->version, '<')) {
            $transient->response[$this->plugin_file] = (object) [
                'slug' => 'loiq-wp-agent',
                'plugin' => $this->plugin_file,
                'new_version' => $remote->version,
                'url' => $remote->homepage ?? 'https://loiq.nl',
                'package' => $remote->download_url,
                'tested' => $remote->tested ?? '6.7',
                'requires_php' => $remote->requires_php ?? '7.4',
            ];
        }

        return $transient;
    }

    public function plugin_info($result, string $action, object $args) {
        if ($action !== 'plugin_information') return $result;
        if (!isset($args->slug) || $args->slug !== 'loiq-wp-agent') return $result;

        $remote = $this->get_remote_info();
        if (!$remote) return $result;

        return (object) [
            'name' => $remote->name ?? 'LOIQ WordPress Agent',
            'slug' => 'loiq-wp-agent',
            'version' => $remote->version,
            'author' => '<a href="https://loiq.nl">LOIQ</a>',
            'homepage' => $remote->homepage ?? 'https://loiq.nl',
            'requires' => $remote->requires ?? '5.8',
            'tested' => $remote->tested ?? '6.7',
            'requires_php' => $remote->requires_php ?? '7.4',
            'sections' => [
                'description' => 'Beveiligde REST API endpoints voor Claude CLI site debugging + write capabilities met safeguards.',
                'changelog' => $remote->changelog ?? '',
            ],
            'download_link' => $remote->download_url,
        ];
    }

    /**
     * Fix folder name after unzip (Vercel/GitHub may add suffix)
     */
    public function fix_source_dir(string $source, string $remote_source, $upgrader, array $hook_extra): string {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $source;
        }

        global $wp_filesystem;

        $plugin_slug = dirname($this->plugin_file);
        $new_source = trailingslashit($remote_source) . $plugin_slug . '/';

        if ($source !== $new_source && $wp_filesystem->move($source, $new_source)) {
            return $new_source;
        }

        return $source;
    }

    private function get_remote_info() {
        $cache_key = 'loiq_agent_signed_update_info';
        $remote = get_transient($cache_key);

        if ($remote === false) {
            $response = wp_remote_get($this->update_url, [
                'timeout' => 10,
                'sslverify' => true,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return false;
            }

            $remote = json_decode(wp_remote_retrieve_body($response));
            if (!$remote || !isset($remote->version)) {
                return false;
            }

            // Verify Ed25519 signature FIRST
            $sig_result = $this->verify_update_signature($remote);
            if (is_wp_error($sig_result)) {
                error_log('[LOIQ Agent Security] ' . $sig_result->get_error_message());
                return false;
            }

            // Validate download URL
            if (!empty($remote->download_url) && !$this->is_allowed_download_url($remote->download_url)) {
                error_log('[LOIQ Agent Security] BLOCKED: download_url from unauthorized host');
                return false;
            }

            set_transient($cache_key, $remote, 12 * HOUR_IN_SECONDS);
        }

        return $remote;
    }

    private function verify_update_signature(object $remote) {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return new WP_Error('sodium_unavailable', 'BLOCKED: PHP sodium required for secure updates.');
        }

        if (empty($remote->signature)) {
            return new WP_Error('missing_signature', 'BLOCKED: Update signature missing.');
        }

        if (empty($remote->version) || empty($remote->download_url) ||
            empty($remote->sha256) || empty($remote->released_at)) {
            return new WP_Error('incomplete_metadata', 'BLOCKED: Update metadata incomplete.');
        }

        $key_id = $remote->key_id ?? $this->default_key_id;
        if (!isset($this->signing_public_keys[$key_id])) {
            return new WP_Error('unknown_key', 'BLOCKED: Unknown signing key.');
        }

        $public_key = base64_decode($this->signing_public_keys[$key_id]);
        if (strlen($public_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return new WP_Error('invalid_public_key', 'BLOCKED: Invalid public key.');
        }

        $canonical = sprintf(
            "version=%s\ndownload_url=%s\nsha256=%s\nreleased_at=%s\n",
            trim($remote->version),
            trim($remote->download_url),
            trim($remote->sha256),
            trim($remote->released_at)
        );

        $signature = base64_decode($remote->signature);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return new WP_Error('invalid_signature_format', 'BLOCKED: Invalid signature format.');
        }

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $canonical, $public_key);
        } catch (Exception $e) {
            return new WP_Error('signature_error', 'BLOCKED: Signature verification error.');
        }

        if (!$valid) {
            return new WP_Error('signature_invalid', 'BLOCKED: Update signature invalid.');
        }

        return true;
    }

    private function is_allowed_download_url(string $url): bool {
        if (strpos($url, 'https://') !== 0) return false;

        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed['host'])) return false;

        $host = strtolower($parsed['host']);
        foreach ($this->allowed_download_hosts as $allowed) {
            if (strpos($allowed, '*') !== false) {
                $pattern = '/^' . str_replace('\*', '[a-z0-9-]+', preg_quote($allowed, '/')) . '$/i';
                if (preg_match($pattern, $host)) return true;
            } elseif ($host === strtolower($allowed)) {
                return true;
            }
        }
        return false;
    }

    public function after_update(object $upgrader, array $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            if (isset($options['plugins'])) {
                foreach ($options['plugins'] as $plugin) {
                    if ($plugin === $this->plugin_file) {
                        delete_transient('loiq_agent_signed_update_info');
                    }
                }
            }
        }
    }

    public function verify_update_hash($reply, string $package, object $upgrader) {
        if (strpos($package, 'loiq-wp-agent') === false) {
            return $reply;
        }

        if (!$this->is_allowed_download_url($package)) {
            return new WP_Error('unauthorized_host', 'BLOCKED: Package URL from unauthorized host.');
        }

        $remote = $this->get_remote_info();

        if (!$remote || empty($remote->sha256)) {
            return new WP_Error('missing_checksum', 'BLOCKED: Checksum verification required but missing.');
        }

        $temp_file = download_url($package, 300);
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        $file_hash = hash_file('sha256', $temp_file);
        if (file_exists($temp_file)) {
            wp_delete_file($temp_file);
        }

        if (!hash_equals($remote->sha256, $file_hash)) {
            return new WP_Error('hash_mismatch', 'BLOCKED: Update checksum mismatch.');
        }

        error_log('[LOIQ Agent Security] Checksum verified for v' . $remote->version);
        return $reply;
    }
}
