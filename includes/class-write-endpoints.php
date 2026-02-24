<?php
/**
 * LOIQ Agent Write Endpoints
 *
 * All v2 write endpoint handlers: css/deploy, option/update, plugin/toggle,
 * content/update, snippet/deploy, plus management endpoints.
 *
 * @since 2.0.0
 */

if (!defined('ABSPATH')) exit;

class LOIQ_Agent_Write_Endpoints {

    /** Whitelisted options (deny-by-default) */
    private static $allowed_options = [
        'blogname',
        'blogdescription',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
        'posts_per_page',
        'permalink_structure',
    ];

    /** Allowed meta key patterns for content updates (allow-list, deny-by-default) */
    private static $allowed_meta_patterns = [
        // Yoast SEO
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_canonical',
        '_yoast_wpseo_opengraph-title',
        '_yoast_wpseo_opengraph-description',
        '_yoast_wpseo_opengraph-image',
        '_yoast_wpseo_twitter-title',
        '_yoast_wpseo_twitter-description',
        // Featured image
        '_thumbnail_id',
        // Divi
        '_et_pb_use_builder',
        '_et_pb_page_layout',
        // RankMath
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
    ];

    /** Allow user-created custom fields (no underscore prefix) */
    const ALLOW_PUBLIC_META = true;

    /**
     * Register all v2 REST routes.
     * Called from main plugin class via rest_api_init.
     *
     * @param LOIQ_WP_Agent $plugin  Reference to main plugin for permission callbacks
     */
    public static function register_routes($plugin) {
        $namespace = 'claude/v2';

        // --- WRITE ENDPOINTS ---

        register_rest_route($namespace, '/css/deploy', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_css_deploy'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'css'     => ['required' => true,  'type' => 'string', 'sanitize_callback' => [__CLASS__, 'sanitize_css_input']],
                'target'  => ['required' => false, 'type' => 'string', 'default' => 'child_theme', 'enum' => ['child_theme', 'divi_custom_css', 'customizer']],
                'mode'    => ['required' => false, 'type' => 'string', 'default' => 'append', 'enum' => ['append', 'replace', 'prepend']],
                'dry_run' => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/option/update', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_option_update'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'option'  => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'value'   => ['required' => true],
                'dry_run' => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/plugin/toggle', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_plugin_toggle'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'plugin'  => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'action'  => ['required' => true,  'type' => 'string', 'enum' => ['activate', 'deactivate']],
                'dry_run' => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/content/update', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_content_update'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'post_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint'],
                'fields'  => ['required' => true, 'type' => 'object'],
                'dry_run' => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/snippet/deploy', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_snippet_deploy'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'name'    => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_file_name'],
                'code'    => ['required' => true,  'type' => 'string'],
                'dry_run' => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/snippet/remove', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_snippet_remove'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_file_name'],
            ],
        ]);

        // --- MANAGEMENT ENDPOINTS ---

        register_rest_route($namespace, '/rollback', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_rollback'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'snapshot_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/snapshots', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_snapshots'],
            'permission_callback' => [$plugin, 'check_permission'],
            'args'                => [
                'limit' => ['required' => false, 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route($namespace, '/power-modes', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_power_modes'],
            'permission_callback' => [$plugin, 'check_permission'],
            'args'                => [],
        ]);
    }

    // =========================================================================
    // WRITE HANDLERS
    // =========================================================================

    /**
     * POST /claude/v2/css/deploy
     */
    public static function handle_css_deploy(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('css')) {
            return new WP_Error('power_mode_off', "Power mode voor 'css' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $css      = $request->get_param('css');
        $target   = $request->get_param('target');
        $mode     = $request->get_param('mode');
        $dry_run  = (bool) $request->get_param('dry_run');
        $session  = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Validate CSS syntax + security
        $css_valid = LOIQ_Agent_Safeguards::validate_css($css);
        if (is_wp_error($css_valid)) return $css_valid;

        // Get current CSS (before state)
        $before = self::get_current_css($target);
        if (is_wp_error($before)) return $before;

        // Calculate new CSS
        switch ($mode) {
            case 'replace':
                $after = $css;
                break;
            case 'prepend':
                $after = $css . "\n" . $before;
                break;
            case 'append':
            default:
                $after = $before . "\n" . $css;
                break;
        }

        // Create snapshot
        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('css', $target, $before, null, false, $session);

        // Audit log
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'css', $target, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'       => true,
                'dry_run'       => true,
                'snapshot_id'   => $snapshot_id,
                'target'        => $target,
                'mode'          => $mode,
                'before_length' => strlen($before),
                'after_length'  => strlen($after),
                'diff_preview'  => self::simple_diff($before, $after),
            ];
        }

        // Execute
        $result = self::write_css($target, $after);
        if (is_wp_error($result)) return $result;

        // Mark snapshot as executed
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $after);

        return [
            'success'       => true,
            'snapshot_id'   => $snapshot_id,
            'target'        => $target,
            'mode'          => $mode,
            'before_length' => strlen($before),
            'after_length'  => strlen($after),
            'diff_preview'  => self::simple_diff($before, $after),
            'rollback_url'  => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v2/option/update
     */
    public static function handle_option_update(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('options')) {
            return new WP_Error('power_mode_off', "Power mode voor 'options' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $option  = $request->get_param('option');
        $value   = $request->get_param('value');
        $dry_run = (bool) $request->get_param('dry_run');
        $session = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Whitelist check
        if (!in_array($option, self::$allowed_options, true)) {
            return new WP_Error('option_not_allowed',
                "Option '{$option}' staat niet op de whitelist. Toegestaan: " . implode(', ', self::$allowed_options),
                ['status' => 403]
            );
        }

        $before = get_option($option);

        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('option', $option, $before, null, false, $session);
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'option', $option, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'      => true,
                'dry_run'      => true,
                'snapshot_id'  => $snapshot_id,
                'option'       => $option,
                'before_value' => $before,
                'after_value'  => $value,
            ];
        }

        update_option($option, $value);
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $value);

        return [
            'success'      => true,
            'snapshot_id'  => $snapshot_id,
            'option'       => $option,
            'before_value' => $before,
            'after_value'  => $value,
            'rollback_url' => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v2/plugin/toggle
     */
    public static function handle_plugin_toggle(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('plugins')) {
            return new WP_Error('power_mode_off', "Power mode voor 'plugins' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $plugin_file = $request->get_param('plugin');
        $action      = $request->get_param('action');
        $dry_run     = (bool) $request->get_param('dry_run');
        $session     = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Verify plugin exists
        $all_plugins = get_plugins();
        if (!isset($all_plugins[$plugin_file])) {
            return new WP_Error('plugin_not_found', "Plugin niet gevonden: {$plugin_file}", ['status' => 404]);
        }

        // Cannot toggle self
        $self_file = plugin_basename(LOIQ_AGENT_PATH . 'loiq-wp-agent.php');
        if ($plugin_file === $self_file) {
            return new WP_Error('cannot_toggle_self', 'Kan de LOIQ Agent plugin niet zelf togglen', ['status' => 403]);
        }

        $active_plugins = get_option('active_plugins', []);
        $is_active = in_array($plugin_file, $active_plugins, true);

        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('plugin', $plugin_file, $active_plugins, null, false, $session);
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'plugin', $plugin_file, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'      => true,
                'dry_run'      => true,
                'snapshot_id'  => $snapshot_id,
                'plugin'       => $plugin_file,
                'plugin_name'  => $all_plugins[$plugin_file]['Name'],
                'action'       => $action,
                'currently'    => $is_active ? 'active' : 'inactive',
                'would_become' => $action === 'activate' ? 'active' : 'inactive',
            ];
        }

        // Execute
        if ($action === 'activate' && !$is_active) {
            $result = activate_plugin($plugin_file);
            if (is_wp_error($result)) {
                return new WP_Error('activation_failed', $result->get_error_message(), ['status' => 500]);
            }
        } elseif ($action === 'deactivate' && $is_active) {
            deactivate_plugins($plugin_file);
        }

        $new_active = get_option('active_plugins', []);
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $new_active);

        return [
            'success'      => true,
            'snapshot_id'  => $snapshot_id,
            'plugin'       => $plugin_file,
            'plugin_name'  => $all_plugins[$plugin_file]['Name'],
            'action'       => $action,
            'was'          => $is_active ? 'active' : 'inactive',
            'now'          => in_array($plugin_file, $new_active, true) ? 'active' : 'inactive',
            'rollback_url' => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v2/content/update
     */
    public static function handle_content_update(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('content')) {
            return new WP_Error('power_mode_off', "Power mode voor 'content' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $post_id = $request->get_param('post_id');
        $fields  = $request->get_param('fields');
        $dry_run = (bool) $request->get_param('dry_run');
        $session = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', "Post #{$post_id} niet gevonden", ['status' => 404]);
        }

        // Block trash/hard delete
        if (!empty($fields['post_status']) && $fields['post_status'] === 'trash') {
            return new WP_Error('no_trash', 'post_status "trash" is niet toegestaan. Gebruik wp-admin.', ['status' => 403]);
        }

        // Capture before state
        $before = [
            'post' => [
                'ID'           => $post->ID,
                'post_title'   => $post->post_title,
                'post_content' => $post->post_content,
                'post_status'  => $post->post_status,
                'post_excerpt' => $post->post_excerpt,
            ],
            'meta' => [],
        ];

        // Capture meta before state if meta fields are being updated
        if (!empty($fields['meta']) && is_array($fields['meta'])) {
            foreach ($fields['meta'] as $key => $value) {
                // Block sensitive meta keys
                if (self::is_blocked_meta_key($key)) {
                    return new WP_Error('meta_blocked', "Meta key '{$key}' is niet toegestaan", ['status' => 403]);
                }
                $before['meta'][$key] = get_post_meta($post_id, $key);
            }
        }

        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('content', (string) $post_id, $before, null, false, $session);
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'content', (string) $post_id, $session, $dry_run);

        if ($dry_run) {
            $preview = ['snapshot_id' => $snapshot_id, 'dry_run' => true, 'post_id' => $post_id, 'changes' => []];
            $allowed_fields = ['post_title', 'post_content', 'post_status', 'post_excerpt'];
            foreach ($allowed_fields as $f) {
                if (isset($fields[$f])) {
                    $preview['changes'][$f] = [
                        'before' => $post->$f,
                        'after'  => $fields[$f],
                    ];
                }
            }
            if (!empty($fields['meta'])) {
                $preview['changes']['meta'] = [];
                foreach ($fields['meta'] as $key => $value) {
                    $preview['changes']['meta'][$key] = [
                        'before' => get_post_meta($post_id, $key, true),
                        'after'  => $value,
                    ];
                }
            }
            return $preview;
        }

        // Execute post update
        $update_data = ['ID' => $post_id];
        $allowed_post_fields = ['post_title', 'post_content', 'post_status', 'post_excerpt'];
        foreach ($allowed_post_fields as $f) {
            if (isset($fields[$f])) {
                $update_data[$f] = $fields[$f];
            }
        }

        if (count($update_data) > 1) {
            $result = wp_update_post($update_data, true);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        // Update meta
        if (!empty($fields['meta']) && is_array($fields['meta'])) {
            foreach ($fields['meta'] as $key => $value) {
                update_post_meta($post_id, sanitize_text_field($key), $value);
            }
        }

        // Capture after state
        $updated_post = get_post($post_id);
        $after = [
            'post' => [
                'ID'           => $updated_post->ID,
                'post_title'   => $updated_post->post_title,
                'post_content' => $updated_post->post_content,
                'post_status'  => $updated_post->post_status,
                'post_excerpt' => $updated_post->post_excerpt,
            ],
            'meta' => [],
        ];
        if (!empty($fields['meta'])) {
            foreach ($fields['meta'] as $key => $value) {
                $after['meta'][$key] = get_post_meta($post_id, $key);
            }
        }

        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $after);

        return [
            'success'      => true,
            'snapshot_id'  => $snapshot_id,
            'post_id'      => $post_id,
            'post_title'   => $updated_post->post_title,
            'post_status'  => $updated_post->post_status,
            'rollback_url' => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v2/snippet/deploy
     */
    public static function handle_snippet_deploy(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('snippets')) {
            return new WP_Error('power_mode_off', "Power mode voor 'snippets' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $name    = $request->get_param('name');
        $code    = $request->get_param('code');
        $dry_run = (bool) $request->get_param('dry_run');
        $session = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Safety scan (pass name to allow updates when at max capacity)
        $scan_result = LOIQ_Agent_Safeguards::scan_snippet($code, $name);
        if (is_wp_error($scan_result)) return $scan_result;

        $safe_name = sanitize_file_name($name);
        $mu_dir  = ABSPATH . 'wp-content/mu-plugins';
        $mu_file = $mu_dir . '/loiq-snippet-' . $safe_name . '.php';

        // Capture before state
        $before = file_exists($mu_file) ? file_get_contents($mu_file) : null;

        // Build snippet file content
        $snippet_content = "<?php\n";
        $snippet_content .= "/**\n";
        $snippet_content .= " * LOIQ Agent Snippet: {$safe_name}\n";
        $snippet_content .= " * Deployed: " . current_time('mysql') . "\n";
        $snippet_content .= " * Session: {$session}\n";
        $snippet_content .= " *\n";
        $snippet_content .= " * @generated by LOIQ WordPress Agent — beheerd via /claude/v2/snippet/deploy\n";
        $snippet_content .= " */\n";
        $snippet_content .= "if (!defined('ABSPATH')) exit;\n\n";
        $snippet_content .= $code . "\n";

        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('snippet', $safe_name, $before, null, false, $session);
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'snippet', $safe_name, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'      => true,
                'dry_run'      => true,
                'snapshot_id'  => $snapshot_id,
                'name'         => $safe_name,
                'file'         => 'loiq-snippet-' . $safe_name . '.php',
                'is_update'    => $before !== null,
                'code_lines'   => substr_count($code, "\n") + 1,
                'safety_scan'  => 'passed',
            ];
        }

        // Ensure mu-plugins dir exists
        if (!is_dir($mu_dir)) {
            if (!wp_mkdir_p($mu_dir)) {
                return new WP_Error('dir_create_failed', 'Kan mu-plugins directory niet aanmaken', ['status' => 500]);
            }
        }

        // Write snippet
        if (file_put_contents($mu_file, $snippet_content) === false) {
            return new WP_Error('write_failed', 'Kan snippet niet schrijven naar ' . basename($mu_file), ['status' => 500]);
        }

        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $snippet_content);

        return [
            'success'      => true,
            'snapshot_id'  => $snapshot_id,
            'name'         => $safe_name,
            'file'         => 'loiq-snippet-' . $safe_name . '.php',
            'is_update'    => $before !== null,
            'code_lines'   => substr_count($code, "\n") + 1,
            'safety_scan'  => 'passed',
            'rollback_url' => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v2/snippet/remove
     */
    public static function handle_snippet_remove(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('snippets')) {
            return new WP_Error('power_mode_off', "Power mode voor 'snippets' is uitgeschakeld.", ['status' => 403]);
        }

        $name      = sanitize_file_name($request->get_param('name'));
        $mu_file   = ABSPATH . 'wp-content/mu-plugins/loiq-snippet-' . $name . '.php';

        if (!file_exists($mu_file)) {
            return new WP_Error('snippet_not_found', 'Snippet "' . $name . '" niet gevonden', ['status' => 404]);
        }

        if (!@unlink($mu_file)) {
            return new WP_Error('remove_failed', 'Kan snippet niet verwijderen', ['status' => 500]);
        }

        LOIQ_Agent_Audit::log_write('/claude/v2/snippet/remove', 200, 'snippet_remove', $name);

        return ['success' => true, 'removed' => 'loiq-snippet-' . $name . '.php'];
    }

    // =========================================================================
    // MANAGEMENT HANDLERS
    // =========================================================================

    /**
     * POST /claude/v2/rollback
     */
    public static function handle_rollback(WP_REST_Request $request) {
        $snapshot_id = $request->get_param('snapshot_id');
        $session = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        $result = LOIQ_Agent_Safeguards::rollback_snapshot($snapshot_id);

        if (is_wp_error($result)) {
            LOIQ_Agent_Audit::log_write($request->get_route(), $result->get_error_data()['status'] ?? 400, 'rollback', (string) $snapshot_id, $session);
            return $result;
        }

        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'rollback', (string) $snapshot_id, $session);

        return $result;
    }

    /**
     * GET /claude/v2/snapshots
     */
    public static function handle_snapshots(WP_REST_Request $request) {
        $limit = $request->get_param('limit');
        return [
            'snapshots' => LOIQ_Agent_Safeguards::get_snapshots($limit),
        ];
    }

    /**
     * GET /claude/v2/power-modes
     */
    public static function handle_power_modes(WP_REST_Request $request) {
        $enabled_until = (int) get_option('loiq_agent_enabled_until', 0);
        $timer_active = $enabled_until > time();

        return [
            'timer_active'   => $timer_active,
            'timer_expires'  => $timer_active ? date('Y-m-d H:i:s', $enabled_until) : null,
            'power_modes'    => LOIQ_Agent_Safeguards::get_power_modes(),
            'write_rate_limit' => [
                'per_minute' => LOIQ_Agent_Safeguards::WRITE_RATE_LIMIT_MINUTE,
                'per_hour'   => LOIQ_Agent_Safeguards::WRITE_RATE_LIMIT_HOUR,
            ],
            'snippets' => [
                'active'  => LOIQ_Agent_Safeguards::count_active_snippets(),
                'max'     => LOIQ_Agent_Safeguards::SNIPPET_MAX_ACTIVE,
                'deployed' => LOIQ_Agent_Safeguards::get_deployed_snippets(),
            ],
        ];
    }

    // =========================================================================
    // CSS HELPERS
    // =========================================================================

    /**
     * Sanitize CSS input — strips HTML tags but preserves newlines.
     *
     * WordPress theme headers (Theme Name, Template, etc.) require each field
     * on its own line. wp_strip_all_tags() collapses whitespace including
     * newlines, which breaks child theme detection.
     *
     * @param string $css Raw CSS input.
     * @return string Sanitized CSS with newlines preserved.
     */
    public static function sanitize_css_input($css) {
        // Remove script/style tags and their content
        $css = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $css);
        // Strip remaining HTML tags
        $css = strip_tags($css);
        return trim($css);
    }

    /**
     * Get current CSS for a target.
     *
     * @param string $target
     * @return string|WP_Error
     */
    private static function get_current_css($target) {
        switch ($target) {
            case 'child_theme':
                $css_file = get_stylesheet_directory() . '/style.css';
                if (!file_exists($css_file)) {
                    return new WP_Error('no_child_theme', 'Child theme style.css niet gevonden', ['status' => 404]);
                }
                return file_get_contents($css_file);

            case 'divi_custom_css':
                $divi_options = get_option('et_divi', []);
                return is_array($divi_options) ? ($divi_options['custom_css'] ?? '') : '';

            case 'customizer':
                return wp_get_custom_css();

            default:
                return new WP_Error('invalid_target', "Ongeldig CSS target: {$target}", ['status' => 400]);
        }
    }

    /**
     * Write CSS to a target.
     *
     * @param string $target
     * @param string $css
     * @return true|WP_Error
     */
    private static function write_css($target, $css) {
        switch ($target) {
            case 'child_theme':
                $css_file = get_stylesheet_directory() . '/style.css';
                if (!is_writable($css_file) && !is_writable(dirname($css_file))) {
                    return new WP_Error('not_writable', 'Child theme style.css is niet schrijfbaar', ['status' => 500]);
                }
                if (file_put_contents($css_file, $css) === false) {
                    return new WP_Error('write_failed', 'Kan niet schrijven naar style.css', ['status' => 500]);
                }
                return true;

            case 'divi_custom_css':
                $divi_options = get_option('et_divi', []);
                if (!is_array($divi_options)) $divi_options = [];
                $divi_options['custom_css'] = $css;
                update_option('et_divi', $divi_options);
                return true;

            case 'customizer':
                wp_update_custom_css_post($css);
                return true;

            default:
                return new WP_Error('invalid_target', "Ongeldig CSS target: {$target}", ['status' => 400]);
        }
    }

    /**
     * Simple diff preview (first 500 chars of change).
     *
     * @param string $before
     * @param string $after
     * @return string
     */
    private static function simple_diff($before, $after) {
        $before_lines = explode("\n", $before);
        $after_lines  = explode("\n", $after);

        $diff = [];
        $max = max(count($before_lines), count($after_lines));

        for ($i = 0; $i < $max; $i++) {
            $b = $before_lines[$i] ?? null;
            $a = $after_lines[$i] ?? null;

            if ($b === $a) continue;

            if ($b !== null && $a === null) {
                $diff[] = '- ' . $b;
            } elseif ($b === null && $a !== null) {
                $diff[] = '+ ' . $a;
            } elseif ($b !== $a) {
                $diff[] = '- ' . $b;
                $diff[] = '+ ' . $a;
            }
        }

        $result = implode("\n", $diff);
        return strlen($result) > 500 ? substr($result, 0, 497) . '...' : $result;
    }

    // =========================================================================
    // META KEY VALIDATION
    // =========================================================================

    /**
     * Check if a meta key is allowed (allow-list, deny-by-default).
     *
     * Allowed:
     * - Exact matches in $allowed_meta_patterns
     * - Public meta keys (no underscore prefix) when ALLOW_PUBLIC_META is true
     *
     * Blocked:
     * - Everything else (internal WP keys, unknown plugin keys, sensitive patterns)
     *
     * @param string $key
     * @return bool  true if BLOCKED, false if allowed
     */
    private static function is_blocked_meta_key($key) {
        // Exact match on allow-list
        if (in_array($key, self::$allowed_meta_patterns, true)) {
            return false; // Allowed
        }

        // Public meta keys (no underscore prefix = user-created custom fields)
        if (self::ALLOW_PUBLIC_META && strpos($key, '_') !== 0) {
            // Extra safety: still block sensitive patterns in public keys
            $sensitive = ['password', 'secret', 'token', 'auth_key', 'nonce', 'hash', 'credential'];
            foreach ($sensitive as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    return true; // Blocked
                }
            }
            return false; // Allowed
        }

        // Everything else is blocked (deny-by-default)
        return true;
    }
}
