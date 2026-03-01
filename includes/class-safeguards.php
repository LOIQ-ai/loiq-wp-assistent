<?php
/**
 * LOIQ Agent Safeguards
 *
 * Power modes, snapshot management, dry-run support, rollback, write rate limiting,
 * and snippet safety scanning.
 *
 * @since 2.0.0
 */

if (!defined('ABSPATH')) exit;

class LOIQ_Agent_Safeguards {

    /** Default power modes — all off (fail-closed) */
    private static $default_modes = [
        'css'          => false,
        'options'      => false,
        'plugins'      => false,
        'content'      => false,
        'snippets'     => false,
        'divi_builder' => false,
        'child_theme'  => false,
        'menus'        => false,
        'media'        => false,
        'forms'        => false,
        'facets'       => false,
        'seo'          => false,
    ];

    /** Write rate limits */
    const WRITE_RATE_LIMIT_MINUTE = 60;
    const WRITE_RATE_LIMIT_HOUR   = 1000;

    /** Snippet constraints */
    const SNIPPET_MAX_LINES   = 500;
    const SNIPPET_MAX_ACTIVE  = 5;

    /** Dangerous PHP patterns blocked in snippets */
    private static $dangerous_patterns = [
        '/\beval\s*\(/i',
        '/\bexec\s*\(/i',
        '/\bsystem\s*\(/i',
        '/\bshell_exec\s*\(/i',
        '/\bpassthru\s*\(/i',
        '/\bproc_open\s*\(/i',
        '/\bpopen\s*\(/i',
        '/\bpcntl_exec\s*\(/i',
        '/\bfile_put_contents\s*\([^)]*wp-config/i',
        '/base64_decode\s*\(.*eval/is',
        '/\bcurl_exec\s*\(/i',
        '/\bfile_get_contents\s*\(\s*[\'"]https?:/i',
        '/\b(unlink|rmdir)\s*\([^)]*\.\.\//i',
    ];

    // =========================================================================
    // POWER MODES
    // =========================================================================

    /**
     * Get all power mode states.
     *
     * @return array<string, bool>
     */
    public static function get_power_modes(): array {
        $stored = get_option('loiq_agent_power_modes', []);
        return wp_parse_args($stored, self::$default_modes);
    }

    /**
     * Check if a specific power mode category is enabled.
     * Also verifies the auto-disable timer is still active.
     *
     * @param string $category  One of: css, options, plugins, content, snippets
     * @return bool
     */
    public static function is_enabled(string $category): bool {
        // Timer must be active
        $enabled_until = (int) get_option('loiq_agent_enabled_until', 0);
        if ($enabled_until > 0 && time() > $enabled_until) {
            return false;
        }

        $modes = self::get_power_modes();
        return !empty($modes[$category]);
    }

    /**
     * Set a single power mode.
     *
     * @param string $category
     * @param bool   $enabled
     */
    public static function set_power_mode(string $category, bool $enabled): void {
        $modes = self::get_power_modes();
        if (!array_key_exists($category, self::$default_modes)) {
            return;
        }
        $modes[$category] = (bool) $enabled;
        update_option('loiq_agent_power_modes', $modes);
    }

    /**
     * Bulk-set power modes (from admin UI).
     *
     * @param array<string, bool> $modes
     */
    public static function set_power_modes(array $modes): void {
        $clean = [];
        foreach (self::$default_modes as $key => $default) {
            $clean[$key] = !empty($modes[$key]);
        }
        update_option('loiq_agent_power_modes', $clean);
    }

    // =========================================================================
    // SNAPSHOTS
    // =========================================================================

    /**
     * Create a pre-edit snapshot.
     *
     * @param string      $action_type  css|option|plugin|content|snippet
     * @param string      $target_key   Identifier (option name, post ID, plugin file, etc.)
     * @param mixed       $before       Previous state (will be JSON-encoded)
     * @param mixed|null  $after        New state (null for dry-run)
     * @param bool        $executed     Was the change actually applied?
     * @param string      $session_id   Claude session identifier
     * @return int|false  Snapshot ID on success, false on failure
     */
    public static function create_snapshot(string $action_type, string $target_key, $before, $after = null, bool $executed = false, string $session_id = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'loiq_agent_snapshots';

        // Anonymize IP (GDPR)
        $ip = self::get_anonymized_ip();

        $inserted = $wpdb->insert($table, [
            'action_type'  => sanitize_text_field($action_type),
            'target_key'   => sanitize_text_field(substr($target_key, 0, 255)),
            'before_value' => wp_json_encode($before),
            'after_value'  => $after !== null ? wp_json_encode($after) : null,
            'executed'     => $executed ? 1 : 0,
            'rolled_back'  => 0,
            'session_id'   => sanitize_text_field(substr($session_id, 0, 64)),
            'ip_address'   => $ip,
            'created_at'   => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);

        return $inserted ? $wpdb->insert_id : false;
    }

    /**
     * Mark a snapshot as executed and store the after_value.
     *
     * @param int   $snapshot_id
     * @param mixed $after_value
     */
    public static function mark_executed(int $snapshot_id, $after_value): void {
        global $wpdb;
        $table = $wpdb->prefix . 'loiq_agent_snapshots';

        $wpdb->update($table, [
            'after_value' => wp_json_encode($after_value),
            'executed'    => 1,
        ], ['id' => (int) $snapshot_id], ['%s', '%d'], ['%d']);
    }

    /**
     * Rollback a snapshot by restoring the before_value.
     *
     * @param int $snapshot_id
     * @return array|WP_Error  Result with details or error
     */
    public static function rollback_snapshot(int $snapshot_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'loiq_agent_snapshots';

        $snapshot = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            (int) $snapshot_id
        ));

        if (!$snapshot) {
            return new WP_Error('snapshot_not_found', __('Snapshot niet gevonden', 'loiq-wp-agent'), ['status' => 404]);
        }

        if (!$snapshot->executed) {
            return new WP_Error('not_executed', __('Snapshot was een dry-run, niets om terug te draaien', 'loiq-wp-agent'), ['status' => 400]);
        }

        if ($snapshot->rolled_back) {
            return new WP_Error('already_rolled_back', __('Snapshot is al teruggedraaid', 'loiq-wp-agent'), ['status' => 400]);
        }

        $before = json_decode($snapshot->before_value, true);

        // Dispatch rollback based on action type
        $result = self::execute_rollback($snapshot->action_type, $snapshot->target_key, $before);

        if (is_wp_error($result)) {
            return $result;
        }

        // Mark as rolled back
        $wpdb->update($table, ['rolled_back' => 1], ['id' => (int) $snapshot_id], ['%d'], ['%d']);

        return [
            'snapshot_id'  => (int) $snapshot->id,
            'action_type'  => $snapshot->action_type,
            'target_key'   => $snapshot->target_key,
            'rolled_back'  => true,
            'restored_at'  => current_time('mysql'),
        ];
    }

    /**
     * Execute the actual rollback for a given action type.
     *
     * @param string $action_type
     * @param string $target_key
     * @param mixed  $before_value
     * @return true|WP_Error
     */
    private static function execute_rollback(string $action_type, string $target_key, $before_value) {
        switch ($action_type) {
            case 'css':
                return self::rollback_css($target_key, $before_value);

            case 'option':
                update_option($target_key, $before_value);
                return true;

            case 'plugin':
                // before_value = list of active plugins before the toggle
                update_option('active_plugins', $before_value);
                return true;

            case 'content':
                $post_id = (int) $target_key;
                if ($post_id <= 0) {
                    return new WP_Error('invalid_post', __('Ongeldige post ID', 'loiq-wp-agent'), ['status' => 400]);
                }
                // Restore post data
                if (!empty($before_value['post'])) {
                    wp_update_post($before_value['post']);
                }
                // Restore meta
                if (!empty($before_value['meta'])) {
                    foreach ($before_value['meta'] as $key => $values) {
                        delete_post_meta($post_id, $key);
                        foreach ((array) $values as $val) {
                            add_post_meta($post_id, $key, maybe_unserialize($val));
                        }
                    }
                }
                return true;

            case 'snippet':
                // before_value = null (new snippet) or previous file content
                $mu_file = ABSPATH . 'wp-content/mu-plugins/loiq-snippet-' . sanitize_file_name($target_key) . '.php';
                if ($before_value === null) {
                    // Snippet didn't exist before — remove it
                    if (file_exists($mu_file)) {
                        @unlink($mu_file);
                    }
                } else {
                    // Restore previous content
                    global $wp_filesystem;
                    if (empty($wp_filesystem)) {
                        require_once ABSPATH . '/wp-admin/includes/file.php';
                        WP_Filesystem();
                    }
                    $wp_filesystem->put_contents($mu_file, $before_value, FS_CHMOD_FILE);
                }
                return true;

            case 'divi':
            case 'theme_builder':
                // before_value = post data array with post_content, meta, etc.
                $post_id = (int) $target_key;
                if ($post_id <= 0) {
                    return new WP_Error('invalid_post', __('Ongeldige post ID voor Divi rollback', 'loiq-wp-agent'), ['status' => 400]);
                }
                if (!empty($before_value['post'])) {
                    wp_update_post($before_value['post']);
                }
                if (!empty($before_value['meta'])) {
                    foreach ($before_value['meta'] as $key => $val) {
                        update_post_meta($post_id, $key, $val);
                    }
                }
                return true;

            case 'child_theme':
                // before_value = full functions.php content
                $functions_file = get_stylesheet_directory() . '/functions.php';
                if (!is_writable($functions_file) && !is_writable(dirname($functions_file))) {
                    return new WP_Error('not_writable', __('functions.php is niet schrijfbaar', 'loiq-wp-agent'), ['status' => 500]);
                }
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once ABSPATH . '/wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                $wp_filesystem->put_contents($functions_file, $before_value, FS_CHMOD_FILE);
                return true;

            case 'menu':
                // before_value = menu items array or menu term data
                if (!empty($before_value['items']) && is_array($before_value['items'])) {
                    foreach ($before_value['items'] as $item) {
                        if (!empty($item['ID'])) {
                            wp_update_post($item);
                        }
                    }
                }
                if (!empty($before_value['locations'])) {
                    set_theme_mod('nav_menu_locations', $before_value['locations']);
                }
                return true;

            case 'media':
                // before_value = null (new upload) — delete the attachment
                $attachment_id = (int) $target_key;
                if ($before_value === null && $attachment_id > 0) {
                    wp_delete_attachment($attachment_id, true);
                }
                return true;

            case 'form':
                // before_value = form array or null
                if (class_exists('GFAPI')) {
                    $form_id = (int) $target_key;
                    if ($before_value === null) {
                        GFAPI::delete_form($form_id);
                    } else {
                        GFAPI::update_form($before_value, $form_id);
                    }
                }
                return true;

            case 'facet':
                // before_value = FacetWP options array
                if ($before_value !== null) {
                    update_option('facetwp_settings', $before_value);
                }
                return true;

            case 'taxonomy':
                // before_value = null (new term) — delete the term
                if ($before_value === null) {
                    $parts = explode(':', $target_key);
                    if (count($parts) === 2) {
                        wp_delete_term((int) $parts[1], $parts[0]);
                    }
                }
                return true;

            case 'seo_schema':
                $post_id = (int) $target_key;
                if ($before_value === null) {
                    delete_post_meta($post_id, '_loiq_seo_schema');
                } else {
                    update_post_meta($post_id, '_loiq_seo_schema', $before_value);
                }
                return true;

            case 'seo_meta':
                $post_id = (int) $target_key;
                if (is_array($before_value)) {
                    $seo_plugin = 'native';
                    if (defined('WPSEO_VERSION')) $seo_plugin = 'yoast';
                    elseif (defined('RANK_MATH_VERSION')) $seo_plugin = 'rankmath';
                    elseif (defined('LOIQ_SEO_VERSION')) $seo_plugin = 'loiq_seo';
                    $meta_map = class_exists('LOIQ_Agent_SEO_Endpoints')
                        ? LOIQ_Agent_SEO_Endpoints::get_meta_map_static($seo_plugin)
                        : [];
                    foreach ($before_value as $field => $val) {
                        if ($field === 'robots') continue;
                        if (isset($meta_map[$field])) {
                            if ($val === '' || $val === null) {
                                delete_post_meta($post_id, $meta_map[$field]);
                            } else {
                                update_post_meta($post_id, $meta_map[$field], $val);
                            }
                        }
                    }
                }
                return true;

            case 'seo_faq':
                $post_id = (int) $target_key;
                if (is_array($before_value)) {
                    if (isset($before_value['post_content'])) {
                        wp_update_post(['ID' => $post_id, 'post_content' => $before_value['post_content']]);
                    }
                    if (array_key_exists('schema', $before_value)) {
                        if ($before_value['schema'] === null) {
                            delete_post_meta($post_id, '_loiq_seo_schema');
                        } else {
                            update_post_meta($post_id, '_loiq_seo_schema', $before_value['schema']);
                        }
                    }
                }
                return true;

            case 'seo_content':
                // before_value = null (new post) — trash it
                $post_id = (int) $target_key;
                if ($before_value === null && $post_id > 0) {
                    wp_trash_post($post_id);
                }
                return true;

            default:
                return new WP_Error('unknown_type', sprintf(__('Onbekend snapshot type: %s', 'loiq-wp-agent'), $action_type), ['status' => 400]);
        }
    }

    /**
     * Rollback a CSS change.
     *
     * @param string $target_key  "child_theme" | "divi_custom_css" | "customizer"
     * @param mixed  $before_value
     * @return true|WP_Error
     */
    private static function rollback_css(string $target_key, $before_value) {
        switch ($target_key) {
            case 'child_theme':
                $css_file = get_stylesheet_directory() . '/style.css';
                if (!is_writable($css_file) && !is_writable(dirname($css_file))) {
                    return new WP_Error('not_writable', __('Child theme style.css is niet schrijfbaar', 'loiq-wp-agent'), ['status' => 500]);
                }
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once ABSPATH . '/wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                $wp_filesystem->put_contents($css_file, $before_value, FS_CHMOD_FILE);
                return true;

            case 'divi_custom_css':
                $divi_options = get_option('et_divi', []);
                if (!is_array($divi_options)) $divi_options = [];
                $divi_options['custom_css'] = $before_value;
                update_option('et_divi', $divi_options);
                return true;

            case 'customizer':
                $post = wp_get_custom_css_post();
                if ($post) {
                    wp_update_post([
                        'ID'           => $post->ID,
                        'post_content' => $before_value,
                    ]);
                }
                return true;

            default:
                return new WP_Error('unknown_css_target', sprintf(__('Onbekend CSS target: %s', 'loiq-wp-agent'), $target_key), ['status' => 400]);
        }
    }

    /**
     * List recent snapshots.
     *
     * @param int $limit
     * @return array
     */
    public static function get_snapshots(int $limit = 20): array {
        global $wpdb;
        $table = $wpdb->prefix . 'loiq_agent_snapshots';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, action_type, target_key, executed, rolled_back, session_id, created_at
             FROM {$table} ORDER BY created_at DESC LIMIT %d",
            (int) $limit
        ));

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'          => (int) $row->id,
                'action_type' => $row->action_type,
                'target_key'  => $row->target_key,
                'executed'    => (bool) $row->executed,
                'rolled_back' => (bool) $row->rolled_back,
                'session_id'  => $row->session_id,
                'created_at'  => $row->created_at,
            ];
        }

        return $result;
    }

    // =========================================================================
    // WRITE RATE LIMITING
    // =========================================================================

    /**
     * Check write-specific rate limits (stricter than read).
     *
     * @param string $client_ip
     * @return true|WP_Error
     */
    public static function check_write_rate_limit(string $client_ip) {
        $ip_hash = md5($client_ip);

        // Per minute
        $minute_key = 'loiq_agent_wrl_m_' . $ip_hash;
        $minute_count = (int) get_transient($minute_key);
        if ($minute_count >= self::WRITE_RATE_LIMIT_MINUTE) {
            return new WP_Error('write_rate_limit',
                sprintf(__('Te veel write requests (max %d/min)', 'loiq-wp-agent'), self::WRITE_RATE_LIMIT_MINUTE),
                ['status' => 429]
            );
        }

        // Per hour
        $hour_key = 'loiq_agent_wrl_h_' . $ip_hash;
        $hour_count = (int) get_transient($hour_key);
        if ($hour_count >= self::WRITE_RATE_LIMIT_HOUR) {
            return new WP_Error('write_rate_limit',
                sprintf(__('Te veel write requests (max %d/uur)', 'loiq-wp-agent'), self::WRITE_RATE_LIMIT_HOUR),
                ['status' => 429]
            );
        }

        // Increment counters
        set_transient($minute_key, $minute_count + 1, 60);
        set_transient($hour_key, $hour_count + 1, 3600);

        return true;
    }

    // =========================================================================
    // SNIPPET SAFETY
    // =========================================================================

    /**
     * Scan PHP code for dangerous patterns using both regex AND tokenizer.
     * Tokenizer catches obfuscation that regex misses (string concat, variable functions).
     *
     * @param string $code
     * @param string|null $name Optional snippet name — if set and snippet already exists, skip count check (update).
     * @return true|WP_Error  true if safe, WP_Error with details if dangerous
     */
    public static function scan_snippet(string $code, ?string $name = null) {
        // Line count check
        $lines = substr_count($code, "\n") + 1;
        if ($lines > self::SNIPPET_MAX_LINES) {
            return new WP_Error('snippet_too_long',
                sprintf(__('Snippet overschrijdt maximum van %1$d regels (%2$d gegeven)', 'loiq-wp-agent'), self::SNIPPET_MAX_LINES, $lines),
                ['status' => 400]
            );
        }

        // Active snippet count check (skip for updates to existing snippets)
        $is_update = false;
        if ($name) {
            $safe_name = sanitize_file_name($name);
            $mu_file   = ABSPATH . 'wp-content/mu-plugins/loiq-snippet-' . $safe_name . '.php';
            $is_update = file_exists($mu_file);
        }
        if (!$is_update) {
            $active = self::count_active_snippets();
            if ($active >= self::SNIPPET_MAX_ACTIVE) {
                return new WP_Error('too_many_snippets',
                    sprintf(__('Maximum %1$d actieve snippets bereikt (%2$d actief)', 'loiq-wp-agent'), self::SNIPPET_MAX_ACTIVE, $active),
                    ['status' => 400]
                );
            }
        }

        // Layer 1: Regex pattern scan (fast, catches obvious patterns)
        foreach (self::$dangerous_patterns as $pattern) {
            if (preg_match($pattern, $code, $matches)) {
                return new WP_Error('dangerous_code',
                    sprintf(__('Geblokkeerde code patroon gevonden: %s', 'loiq-wp-agent'), $matches[0]),
                    ['status' => 403]
                );
            }
        }

        // Layer 2: PHP tokenizer scan (catches obfuscation)
        $token_result = self::scan_snippet_tokens($code);
        if (is_wp_error($token_result)) return $token_result;

        // Layer 3: Syntax check — must be valid PHP
        $syntax = self::check_php_syntax($code);
        if (is_wp_error($syntax)) return $syntax;

        return true;
    }

    /**
     * Tokenizer-based scan. Catches variable functions, string-built calls,
     * call_user_func with dangerous targets, dynamic includes.
     *
     * @param string $code
     * @return true|WP_Error
     */
    private static function scan_snippet_tokens(string $code) {
        $full_code = '<?php ' . $code;
        $tokens = @token_get_all($full_code);
        if ($tokens === false) {
            return new WP_Error('parse_error', __('PHP code kan niet geparsed worden', 'loiq-wp-agent'), ['status' => 400]);
        }

        // Direct dangerous functions
        $dangerous_functions = [
            'eval', 'exec', 'system', 'shell_exec', 'passthru', 'proc_open',
            'popen', 'pcntl_exec', 'dl', 'putenv', 'ini_set', 'ini_alter',
            'apache_setenv', 'call_user_func', 'call_user_func_array',
            'create_function', 'assert', 'preg_replace_callback',
        ];

        // Functions that accept callable args — bypass vector: array_map('system', ['whoami'])
        $dangerous_callback_functions = [
            'array_map', 'array_filter', 'array_walk', 'array_walk_recursive',
            'array_reduce', 'usort', 'uasort', 'uksort',
            'register_shutdown_function', 'register_tick_function',
            'ob_start', 'set_error_handler', 'set_exception_handler',
            'spl_autoload_register', 'iterator_apply',
        ];

        // Reflection API — bypass vector: (new ReflectionFunction('system'))->invoke('whoami')
        $dangerous_classes = [
            'reflectionfunction', 'reflectionmethod', 'reflectionclass',
        ];

        // Superglobals — bypass vector: $_GET['cmd']($arg)
        $dangerous_superglobals = [
            '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES', '$_ENV',
        ];

        $token_count = count($tokens);
        for ($i = 0; $i < $token_count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) continue;

            $type  = $token[0];
            $value = strtolower($token[1]);

            // Block dangerous function calls
            if ($type === T_STRING && in_array($value, $dangerous_functions, true)) {
                $next = self::next_non_whitespace($tokens, $i);
                if ($next === '(') {
                    return new WP_Error('dangerous_function',
                        sprintf(__('Geblokkeerde functie-aanroep: %s()', 'loiq-wp-agent'), $token[1]),
                        ['status' => 403]
                    );
                }
            }

            // Block callback-accepting functions (array_map('system', ...) bypass)
            if ($type === T_STRING && in_array($value, $dangerous_callback_functions, true)) {
                $next = self::next_non_whitespace($tokens, $i);
                if ($next === '(') {
                    return new WP_Error('dangerous_callback',
                        sprintf(__('Functie met callback niet toegestaan: %s() — kan gebruikt worden om code execution te omzeilen', 'loiq-wp-agent'), $token[1]),
                        ['status' => 403]
                    );
                }
            }

            // Block Reflection API (new ReflectionFunction('system')->invoke())
            if ($type === T_STRING && in_array($value, $dangerous_classes, true)) {
                return new WP_Error('dangerous_reflection',
                    sprintf(__('Reflection API niet toegestaan: %s', 'loiq-wp-agent'), $token[1]),
                    ['status' => 403]
                );
            }

            // Block variable function calls: $var()
            if ($type === T_VARIABLE) {
                $next = self::next_non_whitespace($tokens, $i);
                if ($next === '(') {
                    return new WP_Error('variable_function',
                        sprintf(__('Variabele functie-aanroep niet toegestaan: %s()', 'loiq-wp-agent'), $token[1]),
                        ['status' => 403]
                    );
                }

                // Block superglobal array access used as function: $_GET['cmd']()
                if (in_array($token[1], $dangerous_superglobals, true)) {
                    $j = $i + 1;
                    while ($j < $token_count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                    if ($j < $token_count && !is_array($tokens[$j]) && $tokens[$j] === '[') {
                        // Find matching ]
                        $depth = 1;
                        $j++;
                        while ($j < $token_count && $depth > 0) {
                            if (!is_array($tokens[$j])) {
                                if ($tokens[$j] === '[') $depth++;
                                if ($tokens[$j] === ']') $depth--;
                            }
                            $j++;
                        }
                        // Check if ] is followed by (
                        while ($j < $token_count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                        if ($j < $token_count && !is_array($tokens[$j]) && $tokens[$j] === '(') {
                            return new WP_Error('superglobal_function',
                                sprintf(__('Superglobal als functie niet toegestaan: %s[...]() — code injection vector', 'loiq-wp-agent'), $token[1]),
                                ['status' => 403]
                            );
                        }
                    }
                }
            }

            // Block dynamic includes with variables: include $var
            if (in_array($type, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
                $next_token = self::next_non_whitespace_token($tokens, $i);
                if ($next_token && is_array($next_token) && $next_token[0] === T_VARIABLE) {
                    return new WP_Error('dynamic_include',
                        sprintf(__('Dynamische include niet toegestaan: %1$s %2$s', 'loiq-wp-agent'), $token[1], $next_token[1]),
                        ['status' => 403]
                    );
                }
            }

            // Block backtick execution operator
            if ($type === T_ENCAPSED_AND_WHITESPACE || $value === '`') {
                // Check for backtick operator by looking at raw code
            }
        }

        // Block backtick operator (shell execution) — not always tokenized cleanly
        if (preg_match('/(?<![\\\\])`[^`]+`/', $code)) {
            return new WP_Error('backtick_execution',
                __('Backtick shell execution niet toegestaan', 'loiq-wp-agent'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Get next non-whitespace token value (char or string).
     */
    private static function next_non_whitespace(array $tokens, int $from): ?string {
        for ($j = $from + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            return is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        return null;
    }

    /**
     * Get next non-whitespace token (full token array).
     */
    private static function next_non_whitespace_token(array $tokens, int $from) {
        for ($j = $from + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            return $tokens[$j];
        }
        return null;
    }

    /**
     * Check PHP syntax using token_get_all (pure PHP, no exec).
     *
     * @param string $code
     * @return true|WP_Error
     */
    private static function check_php_syntax(string $code) {
        $full = "<?php\nif (!defined('ABSPATH')) exit;\n" . $code;

        try {
            $tokens = @token_get_all($full, TOKEN_PARSE);
            // token_get_all with TOKEN_PARSE throws ParseError on syntax errors
            if ($tokens === false) {
                return new WP_Error('syntax_error',
                    __('PHP syntax fout: code kan niet geparsed worden', 'loiq-wp-agent'),
                    ['status' => 400]
                );
            }
        } catch (\ParseError $e) {
            $error_msg = $e->getMessage();
            return new WP_Error('syntax_error',
                sprintf(__('PHP syntax fout: %s', 'loiq-wp-agent'), $error_msg),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Security-only code scan (dangerous patterns + tokenizer). No snippet count or syntax check.
     * Used for child theme functions.php where snippet limits don't apply.
     *
     * @param string $code
     * @return true|WP_Error
     */
    public static function scan_code_security(string $code) {
        foreach (self::$dangerous_patterns as $pattern) {
            if (preg_match($pattern, $code, $matches)) {
                return new WP_Error('dangerous_code',
                    sprintf(__('Geblokkeerde code patroon gevonden: %s', 'loiq-wp-agent'), $matches[0]),
                    ['status' => 403]
                );
            }
        }
        $token_result = self::scan_snippet_tokens($code);
        if (is_wp_error($token_result)) return $token_result;
        return true;
    }

    /**
     * Count currently deployed LOIQ snippets.
     *
     * @return int
     */
    public static function count_active_snippets(): int {
        $mu_dir = ABSPATH . 'wp-content/mu-plugins';
        if (!is_dir($mu_dir)) return 0;

        $files = glob($mu_dir . '/loiq-snippet-*.php');
        return $files ? count($files) : 0;
    }

    /**
     * List deployed snippets.
     *
     * @return array
     */
    public static function get_deployed_snippets(): array {
        $mu_dir = ABSPATH . 'wp-content/mu-plugins';
        if (!is_dir($mu_dir)) return [];

        $files = glob($mu_dir . '/loiq-snippet-*.php');
        if (!$files) return [];

        $snippets = [];
        foreach ($files as $file) {
            $name = str_replace(['loiq-snippet-', '.php'], '', basename($file));
            $snippets[] = [
                'name'       => $name,
                'file'       => basename($file),
                'size'       => filesize($file),
                'modified'   => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        return $snippets;
    }

    /**
     * Remove a deployed snippet.
     *
     * @param string $name
     * @return true|WP_Error
     */
    public static function remove_snippet(string $name) {
        $safe_name = sanitize_file_name($name);
        $mu_file = ABSPATH . 'wp-content/mu-plugins/loiq-snippet-' . $safe_name . '.php';

        if (!file_exists($mu_file)) {
            return new WP_Error('snippet_not_found', sprintf(__('Snippet niet gevonden: %s', 'loiq-wp-agent'), $safe_name), ['status' => 404]);
        }

        if (!@unlink($mu_file)) {
            return new WP_Error('snippet_delete_failed', __('Kan snippet niet verwijderen', 'loiq-wp-agent'), ['status' => 500]);
        }

        return true;
    }

    // =========================================================================
    // CSS VALIDATION
    // =========================================================================

    /**
     * Validate CSS for syntax and security.
     * Blocks XSS vectors, injection patterns, and malformed CSS.
     *
     * @param string $css
     * @return true|WP_Error
     */
    public static function validate_css(string $css) {
        // Block HTML tags (XSS: <script>, <style>, <iframe>, <img onerror>)
        if (preg_match('/<\s*(script|style|iframe|img|svg|object|embed|link|meta|base)\b/i', $css)) {
            return new WP_Error('css_html_injection',
                __('HTML tags niet toegestaan in CSS', 'loiq-wp-agent'),
                ['status' => 403]
            );
        }

        // Block CSS expression() — IE XSS vector
        if (preg_match('/expression\s*\(/i', $css)) {
            return new WP_Error('css_expression',
                __('CSS expression() niet toegestaan (XSS vector)', 'loiq-wp-agent'),
                ['status' => 403]
            );
        }

        // Block javascript: protocol in url()
        if (preg_match('/url\s*\(\s*[\'"]?\s*javascript\s*:/i', $css)) {
            return new WP_Error('css_javascript_url',
                __('javascript: protocol in url() niet toegestaan', 'loiq-wp-agent'),
                ['status' => 403]
            );
        }

        // Block data: URIs (can embed scripts)
        if (preg_match('/url\s*\(\s*[\'"]?\s*data\s*:/i', $css)) {
            return new WP_Error('css_data_uri',
                __('data: URI in url() niet toegestaan', 'loiq-wp-agent'),
                ['status' => 403]
            );
        }

        // Block -moz-binding (XBL exploit, old Firefox)
        if (preg_match('/-moz-binding\s*:/i', $css)) {
            return new WP_Error('css_moz_binding',
                __('-moz-binding niet toegestaan (XBL exploit)', 'loiq-wp-agent'),
                ['status' => 403]
            );
        }

        // Block @import with external URLs (can load malicious stylesheets)
        if (preg_match('/@import\s+(url\s*\()?\s*[\'"]?\s*https?:/i', $css)) {
            return new WP_Error('css_external_import',
                __('@import met externe URL niet toegestaan', 'loiq-wp-agent'),
                ['status' => 403]
            );
        }

        // Check balanced braces
        $open = substr_count($css, '{');
        $close = substr_count($css, '}');
        if ($open !== $close) {
            return new WP_Error('css_unbalanced_braces',
                sprintf(__('Ongebalanceerde braces: %1$d open, %2$d gesloten', 'loiq-wp-agent'), $open, $close),
                ['status' => 400]
            );
        }

        return true;
    }

    // =========================================================================
    // SNAPSHOT RETENTION
    // =========================================================================

    /**
     * Prune snapshots older than $days. Called daily via WP cron.
     *
     * @param int $days  Days to retain (default 30)
     * @return int       Number of rows deleted
     * @since 3.1.0
     */
    public static function prune_snapshots(int $days = 30): int {
        global $wpdb;
        $table = $wpdb->prefix . 'loiq_agent_snapshots';
        $days = max(1, (int) $days);

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        return (int) $deleted;
    }

    /**
     * Get snapshot table stats (for admin UI / auditing).
     *
     * @return array{total: int, oldest: string|null, size_bytes: int}
     * @since 3.1.0
     */
    public static function get_snapshot_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'loiq_agent_snapshots';

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $oldest = $wpdb->get_var("SELECT MIN(created_at) FROM {$table}");
        $size = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ));

        return [
            'total'      => $total,
            'oldest'     => $oldest,
            'size_bytes' => $size,
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get GDPR-anonymized client IP.
     * Only trusts REMOTE_ADDR — proxy headers are spoofable.
     *
     * @return string
     */
    private static function get_anonymized_ip(): string {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

        // Only trust proxy headers when request comes from configured trusted proxy
        $trusted_proxy = get_option('loiq_agent_trusted_proxy', '');
        if (!empty($trusted_proxy) && trim($ip) === trim($trusted_proxy)) {
            $proxy_headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
            foreach ($proxy_headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $forwarded = sanitize_text_field(wp_unslash(explode(',', $_SERVER[$header])[0]));
                    if (filter_var(trim($forwarded), FILTER_VALIDATE_IP)) {
                        $ip = $forwarded;
                    }
                    break;
                }
            }
        }

        // Anonymize last octet
        return preg_replace('/\.\d+$/', '.0', trim($ip));
    }
}
