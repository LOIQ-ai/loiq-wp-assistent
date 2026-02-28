<?php
/**
 * LOIQ Agent Page Endpoints
 *
 * Page creation, cloning, and listing via REST API.
 * Supports Divi Builder content and template-based page generation.
 *
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

class LOIQ_Agent_Page_Endpoints {

    /**
     * Allowed page layouts for Divi.
     *
     * @var array
     */
    private static $allowed_layouts = [
        'et_full_width_page',
        'et_no_sidebar',
        'et_left_sidebar',
        'et_right_sidebar',
    ];

    /**
     * Register all v3 page REST routes.
     * Called from main plugin class via rest_api_init.
     *
     * @param LOIQ_WP_Agent $plugin  Reference to main plugin for permission callbacks
     */
    public static function register_routes($plugin) {
        $namespace = 'claude/v3';

        // --- PAGE ENDPOINTS ---

        register_rest_route($namespace, '/page/create', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_page_create'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'title'       => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'content'     => ['required' => false, 'type' => 'string', 'default' => ''],
                'status'      => ['required' => false, 'type' => 'string', 'default' => 'draft', 'enum' => ['draft', 'publish', 'pending', 'private']],
                'template'    => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_file_name'],
                'page_layout' => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'parent_id'   => ['required' => false, 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'sanitize_callback' => 'absint'],
                'dry_run'     => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/page/clone', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_page_clone'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'source_id' => ['required' => true,  'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint'],
                'title'     => ['required' => false, 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'dry_run'   => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/page/list', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_page_list'],
            'permission_callback' => [$plugin, 'check_permission'],
            'args'                => [
                'status'   => ['required' => false, 'type' => 'string', 'default' => 'any', 'sanitize_callback' => 'sanitize_text_field'],
                'per_page' => ['required' => false, 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint'],
                'page'     => ['required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    // =========================================================================
    // HANDLERS
    // =========================================================================

    /**
     * POST /claude/v3/page/create
     *
     * Create a new page with optional Divi content or template.
     */
    public static function handle_page_create(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('content')) {
            return new WP_Error('power_mode_off', "Power mode voor 'content' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $title       = $request->get_param('title');
        $content     = $request->get_param('content');
        $status      = $request->get_param('status');
        $template    = $request->get_param('template');
        $page_layout = $request->get_param('page_layout');
        $parent_id   = (int) $request->get_param('parent_id');
        $dry_run     = (bool) $request->get_param('dry_run');
        $session     = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Validate parent page exists if provided
        if ($parent_id > 0) {
            $parent = get_post($parent_id);
            if (!$parent || $parent->post_type !== 'page') {
                return new WP_Error('invalid_parent', "Parent pagina #{$parent_id} niet gevonden", ['status' => 404]);
            }
        }

        // Validate page_layout if provided
        if (!empty($page_layout) && !in_array($page_layout, self::$allowed_layouts, true)) {
            return new WP_Error('invalid_layout',
                "Ongeldige page_layout '{$page_layout}'. Toegestaan: " . implode(', ', self::$allowed_layouts),
                ['status' => 400]
            );
        }

        // If template provided, load and build Divi content from JSON
        if (!empty($template)) {
            $template_result = self::load_template($template);
            if (is_wp_error($template_result)) {
                return $template_result;
            }
            $content = $template_result;
        }

        // Detect Divi content
        $has_divi = self::is_divi_content($content);

        // Create snapshot before insert
        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('content', 'new_page', null, null, false, $session);

        // Audit log
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'content', $title, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'      => true,
                'dry_run'      => true,
                'snapshot_id'  => $snapshot_id,
                'title'        => $title,
                'status'       => $status,
                'has_divi'     => $has_divi,
                'content_length' => strlen($content),
                'template'     => $template ?: null,
                'page_layout'  => $page_layout ?: null,
                'parent_id'    => $parent_id,
            ];
        }

        // Insert the page
        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => 'page',
            'post_parent'  => $parent_id,
        ];

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Set Divi builder meta if Divi content detected
        if ($has_divi) {
            update_post_meta($post_id, '_et_pb_use_builder', 'on');
        }

        // Set page layout if provided
        if (!empty($page_layout)) {
            update_post_meta($post_id, '_et_pb_page_layout', $page_layout);
        }

        // Update snapshot target_key to new post ID
        self::update_snapshot_target($snapshot_id, (string) $post_id);

        // Mark snapshot as executed
        $after = [
            'post_id'   => $post_id,
            'title'     => $title,
            'status'    => $status,
            'has_divi'  => $has_divi,
        ];
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $after);

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'title'        => $title,
            'status'       => get_post_status($post_id),
            'permalink'    => get_permalink($post_id),
            'snapshot_id'  => $snapshot_id,
            'rollback_url' => rest_url('claude/v3/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v3/page/clone
     *
     * Clone an existing page as a draft.
     */
    public static function handle_page_clone(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('content')) {
            return new WP_Error('power_mode_off', "Power mode voor 'content' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $source_id = (int) $request->get_param('source_id');
        $title     = $request->get_param('title');
        $dry_run   = (bool) $request->get_param('dry_run');
        $session   = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Validate source page exists
        $source = get_post($source_id);
        if (!$source) {
            return new WP_Error('source_not_found', "Bronpagina #{$source_id} niet gevonden", ['status' => 404]);
        }

        // Default title
        if (empty($title)) {
            $title = 'Kopie van ' . $source->post_title;
        }

        // Create snapshot
        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('content', 'clone_of_' . $source_id, null, null, false, $session);

        // Audit log
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'content', 'clone:' . $source_id, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'      => true,
                'dry_run'      => true,
                'snapshot_id'  => $snapshot_id,
                'source_id'    => $source_id,
                'source_title' => $source->post_title,
                'new_title'    => $title,
                'would_clone'  => [
                    'content_length' => strlen($source->post_content),
                    'excerpt_length' => strlen($source->post_excerpt),
                    'meta_count'     => count(get_post_meta($source_id)),
                    'has_thumbnail'  => has_post_thumbnail($source_id),
                ],
            ];
        }

        // Clone: insert new page as draft
        $new_post_data = [
            'post_title'   => $title,
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_status'  => 'draft',
            'post_type'    => $source->post_type,
            'post_parent'  => $source->post_parent,
        ];

        $new_post_id = wp_insert_post($new_post_data, true);
        if (is_wp_error($new_post_id)) {
            return $new_post_id;
        }

        // Clone all post meta
        $all_meta = get_post_meta($source_id);
        foreach ($all_meta as $meta_key => $meta_values) {
            foreach ($meta_values as $meta_value) {
                add_post_meta($new_post_id, $meta_key, maybe_unserialize($meta_value));
            }
        }

        // Clone featured image
        if (has_post_thumbnail($source_id)) {
            $thumbnail_id = get_post_thumbnail_id($source_id);
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }

        // Update snapshot target_key to new post ID
        self::update_snapshot_target($snapshot_id, (string) $new_post_id);

        // Mark snapshot as executed
        $after = [
            'post_id'      => $new_post_id,
            'source_id'    => $source_id,
            'title'        => $title,
            'status'       => 'draft',
        ];
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $after);

        return [
            'success'      => true,
            'post_id'      => $new_post_id,
            'source_id'    => $source_id,
            'title'        => $title,
            'status'       => 'draft',
            'permalink'    => get_permalink($new_post_id),
            'snapshot_id'  => $snapshot_id,
            'rollback_url' => rest_url('claude/v3/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * GET /claude/v3/page/list
     *
     * List pages with status, template, and Divi info.
     */
    public static function handle_page_list(WP_REST_Request $request) {
        $status   = $request->get_param('status');
        $per_page = (int) $request->get_param('per_page');
        $page     = (int) $request->get_param('page');

        // Cap per_page
        $per_page = min(max($per_page, 1), 100);

        $args = [
            'post_type'      => 'page',
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ];

        $query = new WP_Query($args);
        $pages = [];

        foreach ($query->posts as $post) {
            $pages[] = [
                'id'        => $post->ID,
                'title'     => $post->post_title,
                'status'    => $post->post_status,
                'template'  => get_page_template_slug($post->ID) ?: 'default',
                'slug'      => $post->post_name,
                'parent_id' => (int) $post->post_parent,
                'has_divi'  => self::is_divi_content($post->post_content),
                'modified'  => $post->post_modified,
            ];
        }

        return [
            'pages'      => $pages,
            'total'      => (int) $query->found_posts,
            'total_pages'=> (int) $query->max_num_pages,
            'page'       => $page,
            'per_page'   => $per_page,
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Detect if content contains Divi Builder shortcodes.
     *
     * @param string $content
     * @return bool
     */
    private static function is_divi_content($content) {
        return !empty($content) && strpos($content, '[et_pb_') !== false;
    }

    /**
     * Load a template JSON and convert to Divi shortcode content.
     *
     * @param string $template_name  Template name (without .json extension)
     * @return string|WP_Error       Divi shortcode content or error
     */
    private static function load_template($template_name) {
        $safe_name = sanitize_file_name($template_name);
        $template_file = LOIQ_AGENT_PATH . 'templates/' . $safe_name . '.json';

        if (!file_exists($template_file)) {
            return new WP_Error('template_not_found',
                "Template '{$safe_name}' niet gevonden in templates/",
                ['status' => 404]
            );
        }

        $json = file_get_contents($template_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local template file within plugin directory
        if ($json === false) {
            return new WP_Error('template_read_failed',
                "Kan template '{$safe_name}' niet lezen",
                ['status' => 500]
            );
        }

        $template_data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('template_invalid_json',
                'Template JSON ongeldig: ' . json_last_error_msg(),
                ['status' => 400]
            );
        }

        // Use Divi Builder class to convert template JSON to shortcodes
        if (class_exists('LOIQ_Agent_Divi_Builder')) {
            return LOIQ_Agent_Divi_Builder::build_shortcode($template_data);
        }

        // Fallback: if template has 'content' key, use it directly
        if (!empty($template_data['content'])) {
            return $template_data['content'];
        }

        return new WP_Error('builder_not_available',
            'LOIQ_Agent_Divi_Builder class niet beschikbaar en template bevat geen directe content',
            ['status' => 500]
        );
    }

    /**
     * Update snapshot target_key after post insertion.
     *
     * @param int    $snapshot_id
     * @param string $target_key
     */
    private static function update_snapshot_target($snapshot_id, $target_key) {
        global $wpdb;
        $table = $wpdb->prefix . 'loiq_agent_snapshots';

        $wpdb->update(
            $table,
            ['target_key' => sanitize_text_field(substr($target_key, 0, 255))],
            ['id' => (int) $snapshot_id],
            ['%s'],
            ['%d']
        );
    }
}
