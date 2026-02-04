<?php
/**
 * Plugin Name: Ultrax Debug
 * Description: Beveiligde REST API endpoints voor Claude CLI site debugging.
 * Version: 1.6.0
 * Update URI: https://github.com/ultrax-agency/ultrax-debug
 * Author: Ultrax Digital Agency
 * Author URI: https://ultrax.agency
 * License: GPL v2 or later
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('ULTRAX_DEBUG_VERSION', '1.6.0');
define('ULTRAX_DEBUG_GITHUB_REPO', 'sylliemillie/ultrax-debug');
define('ULTRAX_DEBUG_PATH', plugin_dir_path(__FILE__));

/**
 * Main Plugin Class
 */
class Ultrax_Debug {

    private static $instance = null;

    // Rate limits
    const RATE_LIMIT_MINUTE = 10;
    const RATE_LIMIT_HOUR = 100;

    // Default auto-disable hours
    const DEFAULT_HOURS = 24;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Plugin activation - generate token and create tables
     */
    public static function on_activation() {
        // Generate secure token
        $token = bin2hex(random_bytes(32));

        // Store hashed token (never store plain text)
        update_option('ultrax_debug_token_hash', password_hash($token, PASSWORD_DEFAULT));

        // Store plain token temporarily for one-time display
        set_transient('ultrax_debug_new_token', $token, 300); // 5 minutes

        // Enable for default hours
        update_option('ultrax_debug_enabled_until', time() + (self::DEFAULT_HOURS * 3600));

        // Create log table
        self::create_tables();

        // Redirect to settings page to show token
        set_transient('ultrax_debug_activation_redirect', true, 30);
    }

    /**
     * Plugin deactivation
     */
    public static function on_deactivation() {
        // Clear transients
        delete_transient('ultrax_debug_new_token');
        delete_transient('ultrax_debug_activation_redirect');
    }

    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . 'ultrax_debug_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            endpoint VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            response_code SMALLINT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY endpoint (endpoint),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private function __construct() {
        // Activation redirect
        add_action('admin_init', [$this, 'activation_redirect']);

        // Register REST routes
        add_action('rest_api_init', [$this, 'register_routes']);

        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_ultrax_debug_regenerate_token', [$this, 'ajax_regenerate_token']);
        add_action('wp_ajax_ultrax_debug_enable', [$this, 'ajax_enable']);
        add_action('wp_ajax_ultrax_debug_disable', [$this, 'ajax_disable']);
        add_action('wp_ajax_ultrax_debug_clear_log', [$this, 'ajax_clear_log']);
    }

    /**
     * Redirect to settings after activation
     */
    public function activation_redirect() {
        if (get_transient('ultrax_debug_activation_redirect')) {
            delete_transient('ultrax_debug_activation_redirect');
            wp_redirect(admin_url('tools.php?page=ultrax-debug'));
            exit;
        }
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        $namespace = 'claude/v1';

        $endpoints = [
            'status'       => 'get_status',
            'errors'       => 'get_errors',
            'plugins'      => 'get_plugins',
            'theme'        => 'get_theme',
            'database'     => 'get_database',
            'code-context' => 'get_code_context',
        ];

        foreach ($endpoints as $route => $callback) {
            register_rest_route($namespace, '/' . $route, [
                'methods'             => 'GET',
                'callback'            => [$this, $callback],
                'permission_callback' => [$this, 'check_permission'],
            ]);
        }
    }

    /**
     * Security permission callback - ALL checks here
     */
    public function check_permission(WP_REST_Request $request) {
        // 1. HTTPS check (allow localhost for dev)
        $is_localhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
        if (!is_ssl() && !$is_localhost) {
            $this->log_request($request->get_route(), 403);
            return new WP_Error('https_required', 'HTTPS is verplicht', ['status' => 403]);
        }

        // 2. Auto-disable check
        $enabled_until = (int) get_option('ultrax_debug_enabled_until', 0);
        if ($enabled_until > 0 && time() > $enabled_until) {
            $this->log_request($request->get_route(), 503);
            return new WP_Error('disabled', 'Debug endpoint is uitgeschakeld (timer verlopen)', ['status' => 503]);
        }

        // 3. Token verification
        $token = $request->get_header('X-Claude-Token');
        if (empty($token)) {
            $this->log_request($request->get_route(), 401);
            return new WP_Error('missing_token', 'X-Claude-Token header ontbreekt', ['status' => 401]);
        }

        $hash = get_option('ultrax_debug_token_hash', '');
        if (empty($hash) || !password_verify($token, $hash)) {
            $this->log_request($request->get_route(), 401);
            return new WP_Error('invalid_token', 'Ongeldig token', ['status' => 401]);
        }

        // 4. IP whitelist (if configured)
        $whitelist = get_option('ultrax_debug_ip_whitelist', '');
        if (!empty($whitelist)) {
            $allowed_ips = array_map('trim', explode("\n", $whitelist));
            $client_ip = $this->get_client_ip();
            if (!in_array($client_ip, $allowed_ips)) {
                $this->log_request($request->get_route(), 403);
                return new WP_Error('ip_blocked', 'IP niet toegestaan', ['status' => 403]);
            }
        }

        // 5. Rate limiting
        $client_ip = $this->get_client_ip();
        $ip_hash = md5($client_ip);

        // Per minute
        $minute_key = 'ultrax_debug_rate_m_' . $ip_hash;
        $minute_count = (int) get_transient($minute_key);
        if ($minute_count >= self::RATE_LIMIT_MINUTE) {
            $this->log_request($request->get_route(), 429);
            return new WP_Error('rate_limit', 'Te veel requests (max ' . self::RATE_LIMIT_MINUTE . '/min)', ['status' => 429]);
        }

        // Per hour
        $hour_key = 'ultrax_debug_rate_h_' . $ip_hash;
        $hour_count = (int) get_transient($hour_key);
        if ($hour_count >= self::RATE_LIMIT_HOUR) {
            $this->log_request($request->get_route(), 429);
            return new WP_Error('rate_limit', 'Te veel requests (max ' . self::RATE_LIMIT_HOUR . '/uur)', ['status' => 429]);
        }

        // Increment counters
        set_transient($minute_key, $minute_count + 1, 60);
        set_transient($hour_key, $hour_count + 1, 3600);

        // Log successful request
        $this->log_request($request->get_route(), 200);

        return true;
    }

    /**
     * Get client IP (handles proxies)
     */
    private function get_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Check for proxy headers
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                break;
            }
        }

        return trim($ip);
    }

    /**
     * Log request to database
     */
    private function log_request($endpoint, $response_code) {
        global $wpdb;
        $table = $wpdb->prefix . 'ultrax_debug_log';

        // Anonymize IP for GDPR (last octet = 0)
        $ip = $this->get_client_ip();
        $ip = preg_replace('/\.\d+$/', '.0', $ip);

        $wpdb->insert($table, [
            'endpoint'      => sanitize_text_field($endpoint),
            'ip_address'    => $ip,
            'response_code' => (int) $response_code,
            'created_at'    => current_time('mysql'),
        ], ['%s', '%s', '%d', '%s']);
    }

    // =========================================================================
    // REST ENDPOINTS
    // =========================================================================

    /**
     * GET /claude/v1/status - Site health info
     */
    public function get_status() {
        global $wpdb;

        return [
            'site_url'       => get_site_url(),
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'mysql_version'  => $wpdb->db_version(),
            'memory_limit'   => WP_MEMORY_LIMIT,
            'debug_mode'     => WP_DEBUG,
            'debug_log'      => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'multisite'      => is_multisite(),
            'ssl'            => is_ssl(),
            'timezone'       => wp_timezone_string(),
            'active_plugins' => count(get_option('active_plugins', [])),
            'post_count'     => wp_count_posts()->publish ?? 0,
            'page_count'     => wp_count_posts('page')->publish ?? 0,
            'user_count'     => count_users()['total_users'] ?? 0,
        ];
    }

    /**
     * GET /claude/v1/errors - Last N lines from debug.log
     */
    public function get_errors(WP_REST_Request $request) {
        $lines = (int) $request->get_param('lines') ?: 50;
        $lines = min($lines, 500); // Max 500 lines

        $log_file = WP_CONTENT_DIR . '/debug.log';

        if (!file_exists($log_file)) {
            return [
                'exists'  => false,
                'message' => 'debug.log niet gevonden. Zet WP_DEBUG_LOG aan in wp-config.php',
            ];
        }

        // Read last N lines efficiently
        $file = new SplFileObject($log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();

        $start = max(0, $total_lines - $lines);
        $file->seek($start);

        $log_lines = [];
        while (!$file->eof()) {
            $line = trim($file->current());
            if (!empty($line)) {
                $log_lines[] = $line;
            }
            $file->next();
        }

        return [
            'exists'      => true,
            'total_lines' => $total_lines,
            'returned'    => count($log_lines),
            'lines'       => $log_lines,
        ];
    }

    /**
     * GET /claude/v1/plugins - Plugin list with update status
     */
    public function get_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        // Get update info
        $updates = get_site_transient('update_plugins');
        $update_list = $updates->response ?? [];

        $result = [];
        foreach ($all_plugins as $file => $data) {
            $result[] = [
                'file'        => $file,
                'name'        => $data['Name'],
                'version'     => $data['Version'],
                'active'      => in_array($file, $active_plugins),
                'update'      => isset($update_list[$file]) ? $update_list[$file]->new_version : null,
                'author'      => strip_tags($data['Author']),
                'requires_wp' => $data['RequiresWP'] ?? null,
                'requires_php'=> $data['RequiresPHP'] ?? null,
            ];
        }

        return [
            'total'  => count($result),
            'active' => count($active_plugins),
            'updates'=> count($update_list),
            'plugins'=> $result,
        ];
    }

    /**
     * GET /claude/v1/theme - Active theme info
     */
    public function get_theme() {
        $theme = wp_get_theme();
        $parent = $theme->parent();

        // Get update info
        $updates = get_site_transient('update_themes');
        $update_available = isset($updates->response[$theme->get_stylesheet()]);

        return [
            'name'        => $theme->get('Name'),
            'version'     => $theme->get('Version'),
            'author'      => $theme->get('Author'),
            'template'    => $theme->get_template(),
            'stylesheet'  => $theme->get_stylesheet(),
            'is_child'    => $theme->parent() !== false,
            'parent'      => $parent ? [
                'name'    => $parent->get('Name'),
                'version' => $parent->get('Version'),
            ] : null,
            'update'      => $update_available ? $updates->response[$theme->get_stylesheet()]['new_version'] : null,
        ];
    }

    /**
     * GET /claude/v1/database - Database schema info (NO data!)
     */
    public function get_database() {
        global $wpdb;

        // Get all tables with prefix
        $tables = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
                DB_NAME,
                $wpdb->prefix . '%'
            )
        );

        $result = [];
        $total_size = 0;

        foreach ($tables as $table) {
            $size = (int) $table->DATA_LENGTH + (int) $table->INDEX_LENGTH;
            $total_size += $size;

            $result[] = [
                'name'  => $table->TABLE_NAME,
                'rows'  => (int) $table->TABLE_ROWS,
                'size'  => $this->format_bytes($size),
                'bytes' => $size,
            ];
        }

        return [
            'prefix'      => $wpdb->prefix,
            'total_tables'=> count($result),
            'total_size'  => $this->format_bytes($total_size),
            'total_bytes' => $total_size,
            'tables'      => $result,
        ];
    }

    /**
     * GET /claude/v1/code-context - Code context for specific topics
     */
    public function get_code_context(WP_REST_Request $request) {
        $topic = sanitize_text_field($request->get_param('topic') ?: 'general');

        // Topic configurations
        $topics = [
            'gravity-forms' => [
                'patterns' => ['gform_', 'GF_', 'gravityforms'],
                'plugin'   => 'gravityforms/gravityforms.php',
                'hooks'    => ['gform_after_submission', 'gform_pre_render', 'gform_field_validation', 'gform_pre_submission'],
            ],
            'woocommerce' => [
                'patterns' => ['wc_', 'woocommerce_', 'WC_'],
                'plugin'   => 'woocommerce/woocommerce.php',
                'hooks'    => ['woocommerce_checkout_process', 'woocommerce_payment_complete', 'woocommerce_add_to_cart'],
            ],
            'divi' => [
                'patterns' => ['et_', 'divi_', 'ET_Builder'],
                'theme'    => 'Divi',
                'hooks'    => ['et_after_main_content', 'et_before_main_content', 'et_pb_module_shortcode_attributes'],
            ],
            'acf' => [
                'patterns' => ['acf/', 'acf_', 'get_field', 'the_field'],
                'plugin'   => 'advanced-custom-fields-pro/acf.php',
                'hooks'    => ['acf/save_post', 'acf/load_field', 'acf/update_value'],
            ],
            'custom-post-types' => [
                'patterns' => ['register_post_type', 'register_taxonomy'],
                'hooks'    => [],
            ],
            'rest-api' => [
                'patterns' => ['register_rest_route', 'rest_api_init'],
                'hooks'    => ['rest_api_init', 'rest_pre_dispatch'],
            ],
            'cron' => [
                'patterns' => ['wp_schedule_event', 'wp_cron'],
                'hooks'    => [],
            ],
            'general' => [
                'patterns' => ['add_action', 'add_filter', 'do_action', 'apply_filters'],
                'hooks'    => [],
            ],
        ];

        $config = $topics[$topic] ?? $topics['general'];
        $result = [
            'topic'     => $topic,
            'available_topics' => array_keys($topics),
        ];

        // Check if related plugin is active
        if (!empty($config['plugin'])) {
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $result['plugin_active'] = is_plugin_active($config['plugin']);

            // Get plugin version
            if ($result['plugin_active']) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $config['plugin']);
                $result['plugin_version'] = $plugin_data['Version'] ?? 'unknown';
            }
        }

        // Check if related theme is active
        if (!empty($config['theme'])) {
            $theme = wp_get_theme();
            $result['theme_match'] = (
                stripos($theme->get('Name'), $config['theme']) !== false ||
                stripos($theme->get_template(), strtolower($config['theme'])) !== false
            );
        }

        // Scan functions.php for hooks
        $theme_dir = get_stylesheet_directory();
        $functions_file = $theme_dir . '/functions.php';
        $result['theme_hooks'] = [];

        if (file_exists($functions_file)) {
            $result['theme_hooks'] = $this->scan_file_for_patterns($functions_file, $config['patterns']);
        }

        // Also check child theme if exists
        $parent_dir = get_template_directory();
        if ($parent_dir !== $theme_dir) {
            $parent_functions = $parent_dir . '/functions.php';
            if (file_exists($parent_functions)) {
                $parent_hooks = $this->scan_file_for_patterns($parent_functions, $config['patterns']);
                foreach ($parent_hooks as $hook) {
                    $hook['source'] = 'parent_theme';
                    $result['theme_hooks'][] = $hook;
                }
            }
        }

        // Get custom functions related to topic
        $result['custom_functions'] = $this->extract_function_names($result['theme_hooks']);

        // Topic-specific data
        if ($topic === 'gravity-forms' && class_exists('GFAPI')) {
            $forms = GFAPI::get_forms();
            $result['forms_count'] = count($forms);
            $result['forms_summary'] = array_map(function($f) {
                return [
                    'id'    => $f['id'],
                    'title' => $f['title'],
                ];
            }, array_slice($forms, 0, 10));
        }

        if ($topic === 'woocommerce' && function_exists('wc_get_product')) {
            $result['product_count'] = wp_count_posts('product')->publish ?? 0;
            $result['order_count'] = wp_count_posts('shop_order')->publish ?? 0;
        }

        if ($topic === 'acf' && function_exists('acf_get_field_groups')) {
            $groups = acf_get_field_groups();
            $result['field_groups'] = count($groups);
            $result['field_group_titles'] = array_map(function($g) {
                return $g['title'];
            }, array_slice($groups, 0, 10));
        }

        if ($topic === 'custom-post-types') {
            $post_types = get_post_types(['_builtin' => false], 'objects');
            $result['custom_post_types'] = [];
            foreach ($post_types as $pt) {
                $result['custom_post_types'][] = [
                    'name'   => $pt->name,
                    'label'  => $pt->label,
                    'count'  => wp_count_posts($pt->name)->publish ?? 0,
                ];
            }
        }

        // Relevant documentation links
        $docs = [
            'gravity-forms' => 'https://docs.gravityforms.com/category/developers/',
            'woocommerce'   => 'https://woocommerce.github.io/code-reference/',
            'divi'          => 'https://www.elegantthemes.com/documentation/developers/',
            'acf'           => 'https://www.advancedcustomfields.com/resources/',
        ];
        $result['docs_url'] = $docs[$topic] ?? null;

        return $result;
    }

    /**
     * Scan a file for patterns and return matches with context
     */
    private function scan_file_for_patterns($file, $patterns) {
        $matches = [];
        $lines = file($file, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line_num => $line) {
            foreach ($patterns as $pattern) {
                if (stripos($line, $pattern) !== false) {
                    // Security: filter out sensitive data
                    if ($this->contains_sensitive_data($line)) {
                        continue;
                    }

                    // Extract hook name if it's an add_action/add_filter
                    $hook_name = $this->extract_hook_name($line);

                    $matches[] = [
                        'file'    => basename($file),
                        'line'    => $line_num + 1,
                        'hook'    => $hook_name,
                        'snippet' => $this->sanitize_snippet($line),
                        'source'  => 'child_theme',
                    ];
                    break; // Don't match same line multiple times
                }
            }
        }

        return array_slice($matches, 0, 50); // Max 50 matches
    }

    /**
     * Check if line contains sensitive data
     */
    private function contains_sensitive_data($line) {
        $sensitive_patterns = [
            '/api[_-]?key/i',
            '/secret/i',
            '/password/i',
            '/credential/i',
            '/token\s*=/i',
            '/auth/i',
            '/private[_-]?key/i',
            '/DB_PASSWORD/i',
            '/DB_USER/i',
        ];

        foreach ($sensitive_patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract hook name from add_action/add_filter call
     */
    private function extract_hook_name($line) {
        if (preg_match('/add_(action|filter)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $matches)) {
            return $matches[2];
        }
        return null;
    }

    /**
     * Sanitize snippet for safe output
     */
    private function sanitize_snippet($line) {
        $line = trim($line);
        // Truncate long lines
        if (strlen($line) > 150) {
            $line = substr($line, 0, 147) . '...';
        }
        return $line;
    }

    /**
     * Extract function names from hook matches
     */
    private function extract_function_names($hooks) {
        $functions = [];
        foreach ($hooks as $hook) {
            // Match function name in callback
            if (preg_match('/[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]/', $hook['snippet'], $matches)) {
                $func = $matches[1];
                // Skip common WordPress functions
                if (!in_array($func, ['__return_true', '__return_false', '__return_empty_array'])) {
                    $functions[] = $func . '()';
                }
            }
        }
        return array_unique($functions);
    }

    /**
     * Format bytes to human readable
     */
    private function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // =========================================================================
    // ADMIN
    // =========================================================================

    /**
     * Add admin menu under Tools
     */
    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Ultrax Debug',
            'Ultrax Debug',
            'manage_options',
            'ultrax-debug',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'tools_page_ultrax-debug') {
            return;
        }

        wp_enqueue_style('ultrax-debug-admin', false);
        wp_add_inline_style('ultrax-debug-admin', $this->get_admin_css());
    }

    /**
     * Get admin CSS
     */
    private function get_admin_css() {
        return '
            .ultrax-debug-wrap { max-width: 800px; }
            .ultrax-debug-wrap h1 { color: #1d2327; margin-bottom: 20px; }
            .ultrax-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
            .ultrax-card h2 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
            .ultrax-token-display { background: #f0f0f1; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 14px; word-break: break-all; margin: 15px 0; }
            .ultrax-token-new { background: #d4edda; border: 1px solid #28a745; }
            .ultrax-token-new::before { content: "NIEUW - Kopieer nu! "; font-weight: bold; color: #155724; }
            .ultrax-status { display: inline-block; padding: 5px 12px; border-radius: 20px; font-weight: 500; }
            .ultrax-status-active { background: #d4edda; color: #155724; }
            .ultrax-status-inactive { background: #f8d7da; color: #721c24; }
            .ultrax-btn { margin-right: 10px !important; }
            .ultrax-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            .ultrax-table th, .ultrax-table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
            .ultrax-table th { background: #f6f7f7; font-weight: 500; }
            .ultrax-code { font-family: monospace; font-size: 12px; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
            .ultrax-log-200 { color: #155724; }
            .ultrax-log-401, .ultrax-log-403, .ultrax-log-429 { color: #721c24; }
            .ultrax-log-503 { color: #856404; }
            .ultrax-help { background: #e7f3fe; border-left: 4px solid #2271b1; padding: 12px 15px; margin: 15px 0; }
            .ultrax-help code { background: #d4e5f7; }
        ';
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Get status
        $enabled_until = (int) get_option('ultrax_debug_enabled_until', 0);
        $is_active = $enabled_until > time();
        $new_token = get_transient('ultrax_debug_new_token');

        // Get request log
        global $wpdb;
        $log_table = $wpdb->prefix . 'ultrax_debug_log';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$log_table'") === $log_table;
        $recent_requests = $table_exists ? $wpdb->get_results(
            "SELECT * FROM $log_table ORDER BY created_at DESC LIMIT 20"
        ) : [];

        ?>
        <div class="wrap ultrax-debug-wrap">
            <h1>Ultrax Debug</h1>

            <?php if ($new_token): ?>
            <div class="ultrax-card">
                <h2>Je Access Token</h2>
                <p><strong>Kopieer dit token nu!</strong> Het wordt niet meer getoond na het verlaten van deze pagina.</p>
                <div class="ultrax-token-display ultrax-token-new" id="new-token"><?php echo esc_html($new_token); ?></div>
                <button type="button" class="button button-primary" onclick="copyToken()">Kopieer Token</button>

                <h3 style="margin-top: 20px;">Terminal Prompt (copy-paste ready)</h3>
                <pre id="terminal-prompt" style="margin: 10px 0; padding: 15px; background: #1d2327; color: #50fa7b; font-family: monospace; font-size: 13px; border-radius: 4px; overflow-x: auto; cursor: pointer;" onclick="copyPrompt()">curl -s -H "X-Claude-Token: <?php echo esc_attr($new_token); ?>" <?php echo esc_url(rest_url('claude/v1/status')); ?> | python3 -m json.tool</pre>
                <button type="button" class="button" onclick="copyPrompt()">Kopieer Terminal Prompt</button>
            </div>
            <?php delete_transient('ultrax_debug_new_token'); ?>
            <?php endif; ?>

            <div class="ultrax-card">
                <h2>Status</h2>
                <p>
                    <span class="ultrax-status <?php echo $is_active ? 'ultrax-status-active' : 'ultrax-status-inactive'; ?>">
                        <?php echo $is_active ? 'Actief' : 'Uitgeschakeld'; ?>
                    </span>
                    <?php if ($is_active): ?>
                        &nbsp; tot <?php echo date_i18n('d-m-Y H:i', $enabled_until); ?>
                        (<?php echo human_time_diff(time(), $enabled_until); ?> resterend)
                    <?php endif; ?>
                </p>

                <p>
                    <?php if ($is_active): ?>
                        <button type="button" class="button ultrax-btn" onclick="disableDebug()">Uitschakelen</button>
                    <?php endif; ?>
                    <button type="button" class="button ultrax-btn" onclick="enableDebug(1)">Activeer 1 uur</button>
                    <button type="button" class="button ultrax-btn" onclick="enableDebug(24)">Activeer 24 uur</button>
                    <button type="button" class="button ultrax-btn" onclick="enableDebug(168)">Activeer 1 week</button>
                </p>
            </div>

            <div class="ultrax-card">
                <h2>Token Beheer</h2>
                <p>
                    <button type="button" class="button" onclick="regenerateToken()">Regenereer Token</button>
                    <span class="description">Dit maakt het huidige token ongeldig.</span>
                </p>
            </div>

            <div class="ultrax-card">
                <h2>Gebruik</h2>
                <div class="ultrax-help">
                    <p><strong>Endpoint:</strong> <code><?php echo esc_url(rest_url('claude/v1/status')); ?></code></p>
                    <p><strong>Header:</strong> <code>X-Claude-Token: &lt;jouw-token&gt;</code></p>
                    <p><strong>Voorbeeld:</strong></p>
                    <pre style="margin: 10px 0; padding: 10px; background: #f0f0f1; overflow-x: auto;">curl -H "X-Claude-Token: TOKEN" <?php echo esc_url(rest_url('claude/v1/status')); ?></pre>
                </div>

                <h3>Beschikbare Endpoints</h3>
                <table class="ultrax-table">
                    <tr><th>Endpoint</th><th>Beschrijving</th></tr>
                    <tr><td><code>/claude/v1/status</code></td><td>WP versie, PHP, memory, debug mode</td></tr>
                    <tr><td><code>/claude/v1/errors</code></td><td>Laatste regels debug.log (?lines=50)</td></tr>
                    <tr><td><code>/claude/v1/plugins</code></td><td>Plugins lijst met update status</td></tr>
                    <tr><td><code>/claude/v1/theme</code></td><td>Actief thema info</td></tr>
                    <tr><td><code>/claude/v1/database</code></td><td>Database tabellen info (geen data)</td></tr>
                    <tr><td><code>/claude/v1/code-context</code></td><td>Code context (?topic=gravity-forms|woocommerce|divi|acf|...)</td></tr>
                </table>
            </div>

            <div class="ultrax-card">
                <h2>IP Whitelist (optioneel)</h2>
                <p>Laat leeg om alle IPs toe te staan. Eén IP per regel.</p>
                <form method="post" action="options.php">
                    <?php settings_fields('ultrax_debug_settings'); ?>
                    <textarea name="ultrax_debug_ip_whitelist" rows="4" style="width: 100%; font-family: monospace;"><?php echo esc_textarea(get_option('ultrax_debug_ip_whitelist', '')); ?></textarea>
                    <?php submit_button('Opslaan', 'secondary'); ?>
                </form>
            </div>

            <?php if (!empty($recent_requests)): ?>
            <div class="ultrax-card">
                <h2>Request Log (laatste 20)</h2>
                <p><button type="button" class="button" onclick="clearLog()">Log Wissen</button></p>
                <table class="ultrax-table">
                    <tr><th>Tijd</th><th>Endpoint</th><th>IP</th><th>Status</th></tr>
                    <?php foreach ($recent_requests as $req): ?>
                    <tr>
                        <td><?php echo esc_html($req->created_at); ?></td>
                        <td><code><?php echo esc_html($req->endpoint); ?></code></td>
                        <td class="ultrax-code"><?php echo esc_html($req->ip_address); ?></td>
                        <td class="ultrax-log-<?php echo esc_attr($req->response_code); ?>"><?php echo esc_html($req->response_code); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

            <div class="ultrax-card">
                <h2>Security Features</h2>
                <ul>
                    <li>Token verificatie (password_hash)</li>
                    <li>HTTPS verplicht (behalve localhost)</li>
                    <li>Rate limiting: <?php echo self::RATE_LIMIT_MINUTE; ?>/min, <?php echo self::RATE_LIMIT_HOUR; ?>/uur</li>
                    <li>Auto-disable timer</li>
                    <li>IP whitelist (optioneel)</li>
                    <li>Request logging met GDPR IP anonimisatie</li>
                    <li>Alleen read-only endpoints (geen data wijzigen)</li>
                </ul>
            </div>
        </div>

        <script>
        function copyToken() {
            var token = document.getElementById('new-token').textContent.replace('NIEUW - Kopieer nu! ', '');
            navigator.clipboard.writeText(token).then(function() {
                alert('Token gekopieerd!');
            });
        }

        function copyPrompt() {
            var prompt = document.getElementById('terminal-prompt').textContent;
            navigator.clipboard.writeText(prompt).then(function() {
                alert('Terminal prompt gekopieerd! Plak in je terminal.');
            });
        }

        function regenerateToken() {
            if (!confirm('Weet je zeker dat je een nieuw token wilt genereren? Het huidige token wordt ongeldig.')) return;

            jQuery.post(ajaxurl, {
                action: 'ultrax_debug_regenerate_token',
                _wpnonce: '<?php echo wp_create_nonce('ultrax_debug_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Fout: ' + response.data);
                }
            });
        }

        function enableDebug(hours) {
            jQuery.post(ajaxurl, {
                action: 'ultrax_debug_enable',
                hours: hours,
                _wpnonce: '<?php echo wp_create_nonce('ultrax_debug_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Fout: ' + response.data);
                }
            });
        }

        function disableDebug() {
            jQuery.post(ajaxurl, {
                action: 'ultrax_debug_disable',
                _wpnonce: '<?php echo wp_create_nonce('ultrax_debug_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Fout: ' + response.data);
                }
            });
        }

        function clearLog() {
            if (!confirm('Weet je zeker dat je de log wilt wissen?')) return;

            jQuery.post(ajaxurl, {
                action: 'ultrax_debug_clear_log',
                _wpnonce: '<?php echo wp_create_nonce('ultrax_debug_nonce'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Fout: ' + response.data);
                }
            });
        }
        </script>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * Regenerate token
     */
    public function ajax_regenerate_token() {
        check_ajax_referer('ultrax_debug_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang');
        }

        $token = bin2hex(random_bytes(32));
        update_option('ultrax_debug_token_hash', password_hash($token, PASSWORD_DEFAULT));
        set_transient('ultrax_debug_new_token', $token, 300);

        wp_send_json_success();
    }

    /**
     * Enable debug for X hours
     */
    public function ajax_enable() {
        check_ajax_referer('ultrax_debug_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang');
        }

        $hours = (int) ($_POST['hours'] ?? self::DEFAULT_HOURS);
        $hours = max(1, min(168, $hours)); // 1 hour to 1 week

        update_option('ultrax_debug_enabled_until', time() + ($hours * 3600));

        wp_send_json_success();
    }

    /**
     * Disable debug
     */
    public function ajax_disable() {
        check_ajax_referer('ultrax_debug_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang');
        }

        update_option('ultrax_debug_enabled_until', 0);

        wp_send_json_success();
    }

    /**
     * Clear request log
     */
    public function ajax_clear_log() {
        check_ajax_referer('ultrax_debug_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toegang');
        }

        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}ultrax_debug_log");

        wp_send_json_success();
    }
}

/**
 * GitHub Auto-Updater
 */
class Ultrax_Debug_Updater {

    private $plugin_file;
    private $plugin_slug;
    private $repo;
    private $cache_key = 'ultrax_debug_update_check';
    private $cache_expiry = 43200; // 12 hours

    public function __construct() {
        $this->plugin_file = plugin_basename(ULTRAX_DEBUG_PATH . 'ultrax-debug.php');
        $this->plugin_slug = dirname($this->plugin_file);
        $this->repo = ULTRAX_DEBUG_GITHUB_REPO;

        // Hook into WP update system
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
    }

    /**
     * Check GitHub for new release
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_version();

        if ($remote && version_compare(ULTRAX_DEBUG_VERSION, $remote['version'], '<')) {
            $transient->response[$this->plugin_file] = (object) [
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $remote['version'],
                'url'         => "https://github.com/{$this->repo}",
                'package'     => $remote['download_url'],
                'icons'       => [],
                'banners'     => [],
                'tested'      => get_bloginfo('version'),
                'requires_php'=> '7.4',
            ];
        }

        return $transient;
    }

    /**
     * Provide plugin info for the update modal
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->plugin_slug) {
            return $result;
        }

        $remote = $this->get_remote_version();

        if (!$remote) {
            return $result;
        }

        return (object) [
            'name'            => 'Ultrax Debug',
            'slug'            => $this->plugin_slug,
            'version'         => $remote['version'],
            'author'          => '<a href="https://ultrax.agency">Ultrax Digital Agency</a>',
            'author_profile'  => 'https://ultrax.agency',
            'homepage'        => "https://github.com/{$this->repo}",
            'requires'        => '5.8',
            'requires_php'    => '7.4',
            'tested'          => get_bloginfo('version'),
            'download_link'   => $remote['download_url'],
            'sections'        => [
                'description'  => 'Beveiligde REST API endpoints voor Claude CLI site debugging.',
                'changelog'    => $remote['changelog'] ?? 'Zie GitHub releases voor changelog.',
            ],
        ];
    }

    /**
     * Fix folder name after unzip (GitHub adds -tag suffix)
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $source;
        }

        global $wp_filesystem;

        $new_source = trailingslashit($remote_source) . $this->plugin_slug . '/';

        if ($source !== $new_source && $wp_filesystem->move($source, $new_source)) {
            return $new_source;
        }

        return $source;
    }

    /**
     * Get latest release info from GitHub
     */
    private function get_remote_version() {
        // Check cache first
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Fetch from GitHub API
        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->repo}/releases/latest",
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version'),
                ],
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($release['tag_name'])) {
            return null;
        }

        // Remove 'v' prefix from version tag
        $version = ltrim($release['tag_name'], 'v');

        // Find zip asset or use zipball_url
        $download_url = $release['zipball_url'];
        foreach ($release['assets'] ?? [] as $asset) {
            if (str_ends_with($asset['name'], '.zip')) {
                $download_url = $asset['browser_download_url'];
                break;
            }
        }

        $data = [
            'version'      => $version,
            'download_url' => $download_url,
            'changelog'    => $release['body'] ?? '',
            'published_at' => $release['published_at'] ?? '',
        ];

        // Cache result
        set_transient($this->cache_key, $data, $this->cache_expiry);

        return $data;
    }
}

// Initialize updater
new Ultrax_Debug_Updater();

// Register settings
add_action('admin_init', function() {
    register_setting('ultrax_debug_settings', 'ultrax_debug_ip_whitelist', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
    ]);
});

// Initialize
register_activation_hook(__FILE__, ['Ultrax_Debug', 'on_activation']);
register_deactivation_hook(__FILE__, ['Ultrax_Debug', 'on_deactivation']);
add_action('plugins_loaded', ['Ultrax_Debug', 'get_instance']);
