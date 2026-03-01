<?php
/**
 * LOIQ Agent SEO Endpoints
 *
 * REST API endpoints for SEO management: JSON-LD schema injection,
 * meta title/description, FAQ sections, content creation, and status dashboard.
 *
 * Power mode: 'seo' (fail-closed, must be enabled via wp-admin).
 *
 * @since 3.2.0
 */

if (!defined('ABSPATH')) exit;

class LOIQ_Agent_SEO_Endpoints {

    /**
     * Allowed JSON-LD schema types.
     *
     * @var array
     */
    private static $allowed_schema_types = [
        'LocalBusiness',
        'Organization',
        'WebSite',
        'WebPage',
        'Article',
        'BlogPosting',
        'FAQPage',
        'Product',
        'Service',
        'BreadcrumbList',
        'HowTo',
        'Event',
        'Person',
        'Review',
        'AggregateRating',
        'VideoObject',
        'ImageObject',
        'ItemList',
        'SiteNavigationElement',
        'ContactPage',
        'AboutPage',
        'CollectionPage',
        'ProfilePage',
    ];

    /**
     * Register all SEO REST routes.
     *
     * @param LOIQ_WP_Agent $plugin  Reference to main plugin for permission callbacks
     */
    public static function register_routes(LOIQ_WP_Agent $plugin): void {
        $namespace = 'claude/v3';

        // --- WRITE ENDPOINTS (require seo power mode) ---

        register_rest_route($namespace, '/seo/schema', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_schema'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'post_id'  => ['required' => true,  'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint'],
                'schema'   => ['required' => true,  'type' => ['object', 'array']],
                'mode'     => ['required' => false, 'type' => 'string', 'default' => 'replace', 'enum' => ['replace', 'merge']],
                'dry_run'  => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/seo/meta', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_meta'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'post_id'          => ['required' => true,  'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint'],
                'title'            => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'description'      => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                'focus_keyword'    => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'canonical'        => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_url'],
                'robots'           => ['required' => false, 'type' => 'object'],
                'og_title'         => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'og_description'   => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
                'og_image_id'      => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'dry_run'          => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/seo/faq', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_faq'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'post_id'  => ['required' => true,  'type' => 'integer', 'minimum' => 1, 'sanitize_callback' => 'absint'],
                'items'    => ['required' => true,  'type' => 'array'],
                'mode'     => ['required' => false, 'type' => 'string', 'default' => 'append', 'enum' => ['append', 'replace']],
                'format'   => ['required' => false, 'type' => 'string', 'default' => 'divi', 'enum' => ['divi', 'html']],
                'dry_run'  => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route($namespace, '/seo/content', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_content'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'title'       => ['required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'content'     => ['required' => true,  'type' => 'string'],
                'post_type'   => ['required' => false, 'type' => 'string', 'default' => 'post', 'enum' => ['post', 'page']],
                'status'      => ['required' => false, 'type' => 'string', 'default' => 'draft', 'enum' => ['draft', 'pending']],
                'category'    => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'tags'        => ['required' => false, 'type' => 'array'],
                'meta'        => ['required' => false, 'type' => 'object'],
                'schema'      => ['required' => false, 'type' => ['object', 'array']],
                'dry_run'     => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        // --- READ ENDPOINT (no power mode required) ---

        register_rest_route($namespace, '/seo/status', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_status'],
            'permission_callback' => [$plugin, 'check_permission'],
            'args'                => [
                'post_id' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    // =========================================================================
    // HANDLERS
    // =========================================================================

    /**
     * POST /claude/v3/seo/schema
     *
     * Inject or replace JSON-LD schema markup for a post/page.
     * Schema is stored as post meta and output in wp_head.
     */
    public static function handle_schema(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('seo')) {
            return new WP_Error('power_mode_off', "Power mode voor 'seo' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $post_id = (int) $request->get_param('post_id');
        $schema  = $request->get_param('schema');
        $mode    = $request->get_param('mode');
        $dry_run = (bool) $request->get_param('dry_run');
        $session = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Validate post exists
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', "Post #{$post_id} niet gevonden", ['status' => 404]);
        }

        // Validate schema structure
        $validation = self::validate_schema($schema);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Ensure @context is set
        if (is_array($schema) && !isset($schema['@graph'])) {
            if (!isset($schema['@context'])) {
                $schema['@context'] = 'https://schema.org';
            }
        }

        // Get existing schema for snapshot
        $existing = get_post_meta($post_id, '_loiq_seo_schema', true);
        $before = $existing ?: null;

        // Merge mode: combine with existing schemas
        if ($mode === 'merge' && !empty($existing)) {
            $existing_data = is_string($existing) ? json_decode($existing, true) : $existing;
            if (is_array($existing_data)) {
                $schema = self::merge_schemas($existing_data, $schema);
            }
        }

        $schema_json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Create snapshot
        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('seo_schema', (string) $post_id, $before, $schema_json, false, $session);

        // Audit log
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'seo', 'schema:' . $post_id, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'     => true,
                'dry_run'     => true,
                'snapshot_id' => $snapshot_id,
                'post_id'     => $post_id,
                'mode'        => $mode,
                'schema'      => $schema,
                'had_existing' => !empty($before),
            ];
        }

        // Store schema as post meta
        update_post_meta($post_id, '_loiq_seo_schema', $schema_json);

        // Mark snapshot executed
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $schema_json);

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'mode'         => $mode,
            'schema_types' => self::extract_schema_types($schema),
            'snapshot_id'  => $snapshot_id,
            'rollback_url' => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v3/seo/meta
     *
     * Update SEO meta (title, description, OG, robots) for a post/page.
     * Works with Yoast SEO, Rank Math, or falls back to native post meta.
     */
    public static function handle_meta(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('seo')) {
            return new WP_Error('power_mode_off', "Power mode voor 'seo' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $post_id = (int) $request->get_param('post_id');
        $dry_run = (bool) $request->get_param('dry_run');
        $session = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Validate post exists
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', "Post #{$post_id} niet gevonden", ['status' => 404]);
        }

        // Detect active SEO plugin
        $seo_plugin = self::detect_seo_plugin();

        // Collect fields to update
        $fields = [];
        $meta_map = self::get_meta_map($seo_plugin);

        foreach (['title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'og_image_id'] as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        // Handle robots directives
        $robots = $request->get_param('robots');
        if ($robots !== null && is_array($robots)) {
            $fields['robots'] = $robots;
        }

        if (empty($fields)) {
            return new WP_Error('no_fields', 'Geen SEO velden opgegeven om bij te werken', ['status' => 400]);
        }

        // Build before state for snapshot
        $before = [];
        foreach ($fields as $field => $value) {
            if ($field === 'robots') {
                $before[$field] = self::get_robots_meta($post_id, $seo_plugin);
            } elseif (isset($meta_map[$field])) {
                $before[$field] = get_post_meta($post_id, $meta_map[$field], true);
            }
        }

        // Create snapshot
        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('seo_meta', (string) $post_id, $before, $fields, false, $session);

        // Audit log
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'seo', 'meta:' . $post_id, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'     => true,
                'dry_run'     => true,
                'snapshot_id' => $snapshot_id,
                'post_id'     => $post_id,
                'seo_plugin'  => $seo_plugin,
                'fields'      => $fields,
                'before'      => $before,
            ];
        }

        // Apply changes
        $updated = [];
        foreach ($fields as $field => $value) {
            if ($field === 'robots') {
                self::set_robots_meta($post_id, $value, $seo_plugin);
                $updated[] = 'robots';
            } elseif (isset($meta_map[$field])) {
                update_post_meta($post_id, $meta_map[$field], $value);
                $updated[] = $field;
            }
        }

        // Mark snapshot executed
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $fields);

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'seo_plugin'   => $seo_plugin,
            'updated'      => $updated,
            'snapshot_id'  => $snapshot_id,
            'rollback_url' => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v3/seo/faq
     *
     * Add FAQ section to a post/page with FAQPage schema auto-injection.
     * Supports Divi accordion or plain HTML output.
     */
    public static function handle_faq(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('seo')) {
            return new WP_Error('power_mode_off', "Power mode voor 'seo' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $post_id = (int) $request->get_param('post_id');
        $items   = $request->get_param('items');
        $mode    = $request->get_param('mode');
        $format  = $request->get_param('format');
        $dry_run = (bool) $request->get_param('dry_run');
        $session = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Validate post exists
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', "Post #{$post_id} niet gevonden", ['status' => 404]);
        }

        // Validate FAQ items
        if (empty($items) || !is_array($items)) {
            return new WP_Error('invalid_items', 'FAQ items array is vereist', ['status' => 400]);
        }

        foreach ($items as $i => $item) {
            if (empty($item['question']) || empty($item['answer'])) {
                return new WP_Error('invalid_faq_item',
                    sprintf('FAQ item %d mist question of answer', $i + 1),
                    ['status' => 400]
                );
            }
        }

        // Cap at 50 FAQ items (reasonable limit)
        if (count($items) > 50) {
            return new WP_Error('too_many_items', 'Maximum 50 FAQ items per request', ['status' => 400]);
        }

        // Build FAQ content
        $faq_content = ($format === 'divi')
            ? self::build_divi_faq($items)
            : self::build_html_faq($items);

        // Build FAQPage schema
        $faq_schema = self::build_faq_schema($items);

        // Snapshot before state
        $before = [
            'post_content' => $post->post_content,
            'schema'       => get_post_meta($post_id, '_loiq_seo_schema', true) ?: null,
        ];

        // Build new content
        if ($mode === 'replace') {
            $new_content = $faq_content;
        } else {
            $new_content = $post->post_content . "\n\n" . $faq_content;
        }

        // Create snapshot
        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('seo_faq', (string) $post_id, $before, null, false, $session);

        // Audit log
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'seo', 'faq:' . $post_id, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'      => true,
                'dry_run'      => true,
                'snapshot_id'  => $snapshot_id,
                'post_id'      => $post_id,
                'faq_count'    => count($items),
                'format'       => $format,
                'mode'         => $mode,
                'content_preview' => substr($faq_content, 0, 500),
                'schema'       => $faq_schema,
            ];
        }

        // Update post content
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => $new_content,
        ]);

        // Write FAQ items to unified _ultrax_faq_items if ultrax-seo is active
        if (class_exists('Ultrax_SEO_FAQ')) {
            Ultrax_SEO_FAQ::save_items($post_id, $items);
        }

        // Inject FAQPage schema into _loiq_seo_schema (legacy, also used by non-FAQ schema types)
        $existing_schema = get_post_meta($post_id, '_loiq_seo_schema', true);
        if (!empty($existing_schema)) {
            $existing_data = json_decode($existing_schema, true);
            if (is_array($existing_data)) {
                $faq_schema = self::merge_schemas($existing_data, $faq_schema);
            }
        }
        update_post_meta($post_id, '_loiq_seo_schema', wp_json_encode($faq_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Mark snapshot executed
        $after = [
            'post_content' => $new_content,
            'schema'       => $faq_schema,
        ];
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $after);

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'faq_count'    => count($items),
            'format'       => $format,
            'mode'         => $mode,
            'snapshot_id'  => $snapshot_id,
            'rollback_url' => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * POST /claude/v3/seo/content
     *
     * Create SEO-optimized draft content (post or page) with meta and optional schema.
     * Always creates as draft — never publishes directly.
     */
    public static function handle_content(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('seo')) {
            return new WP_Error('power_mode_off', "Power mode voor 'seo' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $title     = $request->get_param('title');
        $content   = $request->get_param('content');
        $post_type = $request->get_param('post_type');
        $status    = $request->get_param('status');
        $category  = $request->get_param('category');
        $tags      = $request->get_param('tags');
        $meta      = $request->get_param('meta');
        $schema    = $request->get_param('schema');
        $dry_run   = (bool) $request->get_param('dry_run');
        $session   = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Security: force draft or pending, never publish directly
        if (!in_array($status, ['draft', 'pending'], true)) {
            $status = 'draft';
        }

        // Sanitize content (allow safe HTML)
        $content = wp_kses_post($content);

        // Create snapshot
        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('seo_content', 'new_' . $post_type, null, null, false, $session);

        // Audit log
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'seo', 'content:' . $title, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'        => true,
                'dry_run'        => true,
                'snapshot_id'    => $snapshot_id,
                'title'          => $title,
                'post_type'      => $post_type,
                'status'         => $status,
                'content_length' => strlen($content),
                'has_category'   => !empty($category),
                'tags_count'     => $tags ? count($tags) : 0,
                'has_meta'       => !empty($meta),
                'has_schema'     => !empty($schema),
            ];
        }

        // Insert the post
        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_type'    => $post_type,
        ];

        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Set category (for posts)
        if (!empty($category) && $post_type === 'post') {
            $cat = get_cat_ID($category);
            if ($cat === 0) {
                // Create category if it doesn't exist
                $cat = wp_create_category($category);
            }
            if ($cat > 0) {
                wp_set_post_categories($post_id, [$cat]);
            }
        }

        // Set tags (for posts)
        if (!empty($tags) && is_array($tags) && $post_type === 'post') {
            $clean_tags = array_map('sanitize_text_field', $tags);
            wp_set_post_tags($post_id, $clean_tags);
        }

        // Set SEO meta if provided
        if (!empty($meta) && is_array($meta)) {
            $seo_plugin = self::detect_seo_plugin();
            $meta_map = self::get_meta_map($seo_plugin);
            foreach ($meta as $key => $value) {
                if (isset($meta_map[$key])) {
                    update_post_meta($post_id, $meta_map[$key], sanitize_text_field($value));
                }
            }
        }

        // Inject schema if provided
        if (!empty($schema)) {
            $validation = self::validate_schema($schema);
            if (!is_wp_error($validation)) {
                if (is_array($schema) && !isset($schema['@context']) && !isset($schema['@graph'])) {
                    $schema['@context'] = 'https://schema.org';
                }
                update_post_meta($post_id, '_loiq_seo_schema', wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                // Extract FAQ items from schema and write to unified meta if ultrax-seo active
                if (class_exists('Ultrax_SEO_FAQ')) {
                    $faq_items = self::extract_faq_from_schema($schema);
                    if (!empty($faq_items)) {
                        Ultrax_SEO_FAQ::save_items($post_id, $faq_items);
                    }
                }
            }
        }

        // Mark snapshot executed
        $after = [
            'post_id' => $post_id,
            'title'   => $title,
            'status'  => $status,
        ];
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, $after);

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'title'        => $title,
            'status'       => $status,
            'post_type'    => $post_type,
            'permalink'    => get_permalink($post_id),
            'edit_url'     => admin_url('post.php?post=' . $post_id . '&action=edit'),
            'snapshot_id'  => $snapshot_id,
            'rollback_url' => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    /**
     * GET /claude/v3/seo/status
     *
     * SEO dashboard: site-wide overview or per-page SEO status.
     */
    public static function handle_status(WP_REST_Request $request) {
        $post_id = (int) $request->get_param('post_id');

        // Single post status
        if ($post_id > 0) {
            return self::get_post_seo_status($post_id);
        }

        // Site-wide SEO overview
        return self::get_site_seo_status();
    }

    // =========================================================================
    // SCHEMA HELPERS
    // =========================================================================

    /**
     * Validate JSON-LD schema structure.
     *
     * @param mixed $schema
     * @return true|WP_Error
     */
    private static function validate_schema($schema) {
        if (!is_array($schema)) {
            return new WP_Error('invalid_schema', 'Schema moet een JSON object of array zijn', ['status' => 400]);
        }

        // Check for @graph (multi-entity schema)
        if (isset($schema['@graph']) && is_array($schema['@graph'])) {
            foreach ($schema['@graph'] as $entity) {
                if (!empty($entity['@type'])) {
                    $type = is_array($entity['@type']) ? $entity['@type'][0] : $entity['@type'];
                    if (!in_array($type, self::$allowed_schema_types, true)) {
                        return new WP_Error('invalid_schema_type',
                            sprintf("Schema type '%s' niet toegestaan", $type),
                            ['status' => 400]
                        );
                    }
                }
            }
            return true;
        }

        // Single entity schema
        if (!empty($schema['@type'])) {
            $type = is_array($schema['@type']) ? $schema['@type'][0] : $schema['@type'];
            if (!in_array($type, self::$allowed_schema_types, true)) {
                return new WP_Error('invalid_schema_type',
                    sprintf("Schema type '%s' niet toegestaan. Beschikbaar: %s", $type, implode(', ', self::$allowed_schema_types)),
                    ['status' => 400]
                );
            }
        }

        // Block script injection in schema values
        $json = wp_json_encode($schema);
        if (stripos($json, '<script') !== false || stripos($json, 'javascript:') !== false) {
            return new WP_Error('schema_xss', 'Script injection geblokkeerd in schema', ['status' => 403]);
        }

        return true;
    }

    /**
     * Extract @type values from schema.
     *
     * @param array $schema
     * @return array
     */
    private static function extract_schema_types(array $schema): array {
        $types = [];

        if (isset($schema['@graph'])) {
            foreach ($schema['@graph'] as $entity) {
                if (!empty($entity['@type'])) {
                    $types[] = is_array($entity['@type']) ? implode(', ', $entity['@type']) : $entity['@type'];
                }
            }
        } elseif (!empty($schema['@type'])) {
            $types[] = is_array($schema['@type']) ? implode(', ', $schema['@type']) : $schema['@type'];
        }

        return $types;
    }

    /**
     * Merge two schemas. If both have @graph, combine entities. Otherwise wrap in @graph.
     *
     * @param array $existing
     * @param array $new_schema
     * @return array
     */
    private static function merge_schemas(array $existing, array $new_schema): array {
        $existing_graph = $existing['@graph'] ?? [$existing];
        $new_graph = $new_schema['@graph'] ?? [$new_schema];

        // Remove @context from individual entities before merging
        foreach ($existing_graph as &$entity) {
            unset($entity['@context']);
        }
        foreach ($new_graph as &$entity) {
            unset($entity['@context']);
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => array_merge($existing_graph, $new_graph),
        ];
    }

    // =========================================================================
    // FAQ BUILDERS
    // =========================================================================

    /**
     * Build Divi accordion shortcode for FAQ items.
     *
     * @param array $items  Array of ['question' => ..., 'answer' => ...]
     * @return string       Divi shortcode content
     */
    private static function build_divi_faq(array $items): string {
        $output = '[et_pb_section fb_built="1" _builder_version="4.27.5"][et_pb_row _builder_version="4.27.5"][et_pb_column type="4_4" _builder_version="4.27.5"]';
        $output .= '[et_pb_text _builder_version="4.27.5"]<h2>Veelgestelde vragen</h2>[/et_pb_text]';
        $output .= '[et_pb_accordion _builder_version="4.27.5"]';

        foreach ($items as $i => $item) {
            $q = esc_html($item['question']);
            $a = wp_kses_post($item['answer']);
            $open = $i === 0 ? ' open="on"' : '';
            $output .= sprintf('[et_pb_accordion_item title="%s"%s _builder_version="4.27.5"]%s[/et_pb_accordion_item]', $q, $open, $a);
        }

        $output .= '[/et_pb_accordion][/et_pb_column][/et_pb_row][/et_pb_section]';
        return $output;
    }

    /**
     * Build plain HTML FAQ section.
     *
     * @param array $items
     * @return string
     */
    private static function build_html_faq(array $items): string {
        $output = '<section class="loiq-faq-section"><h2>Veelgestelde vragen</h2>';
        foreach ($items as $item) {
            $output .= sprintf(
                '<div class="loiq-faq-item"><h3>%s</h3><div class="loiq-faq-answer">%s</div></div>',
                esc_html($item['question']),
                wp_kses_post($item['answer'])
            );
        }
        $output .= '</section>';
        return $output;
    }

    /**
     * Build FAQPage JSON-LD schema from FAQ items.
     *
     * @param array $items
     * @return array
     */
    private static function build_faq_schema(array $items): array {
        $entities = [];
        foreach ($items as $item) {
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => wp_strip_all_tags($item['answer']),
                ],
            ];
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    /**
     * Extract FAQ items from a schema array (top-level FAQPage or @graph).
     *
     * @param array $schema Schema data.
     * @return array Array of ['question' => ..., 'answer' => ...].
     */
    private static function extract_faq_from_schema(array $schema): array {
        $entities = [];

        // Check top-level FAQPage
        if (isset($schema['@type']) && $schema['@type'] === 'FAQPage' && !empty($schema['mainEntity'])) {
            $entities = $schema['mainEntity'];
        }

        // Check @graph for FAQPage
        if (empty($entities) && !empty($schema['@graph']) && is_array($schema['@graph'])) {
            foreach ($schema['@graph'] as $node) {
                if (isset($node['@type']) && $node['@type'] === 'FAQPage' && !empty($node['mainEntity'])) {
                    $entities = $node['mainEntity'];
                    break;
                }
            }
        }

        if (empty($entities)) {
            return [];
        }

        $items = [];
        foreach ($entities as $entity) {
            if (!isset($entity['@type']) || $entity['@type'] !== 'Question') continue;
            $question = $entity['name'] ?? '';
            $answer = $entity['acceptedAnswer']['text'] ?? '';
            if (!empty($question) && !empty($answer)) {
                $items[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $items;
    }

    // =========================================================================
    // SEO PLUGIN DETECTION + META MAPPING
    // =========================================================================

    /**
     * Detect which SEO plugin is active.
     *
     * @return string  'yoast' | 'rankmath' | 'loiq_seo' | 'native'
     */
    private static function detect_seo_plugin(): string {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION')) {
            return 'rankmath';
        }
        if (defined('LOIQ_SEO_VERSION')) {
            return 'loiq_seo';
        }
        return 'native';
    }

    /**
     * Get meta key mapping for the active SEO plugin (public, for rollback).
     *
     * @param string $plugin
     * @return array<string, string>
     */
    public static function get_meta_map_static(string $plugin): array {
        return self::get_meta_map($plugin);
    }

    /**
     * Get meta key mapping for the active SEO plugin.
     *
     * @param string $plugin
     * @return array<string, string>
     */
    private static function get_meta_map(string $plugin): array {
        switch ($plugin) {
            case 'yoast':
                return [
                    'title'         => '_yoast_wpseo_title',
                    'description'   => '_yoast_wpseo_metadesc',
                    'focus_keyword' => '_yoast_wpseo_focuskw',
                    'canonical'     => '_yoast_wpseo_canonical',
                    'og_title'      => '_yoast_wpseo_opengraph-title',
                    'og_description'=> '_yoast_wpseo_opengraph-description',
                    'og_image_id'   => '_yoast_wpseo_opengraph-image-id',
                ];

            case 'rankmath':
                return [
                    'title'         => 'rank_math_title',
                    'description'   => 'rank_math_description',
                    'focus_keyword' => 'rank_math_focus_keyword',
                    'canonical'     => 'rank_math_canonical_url',
                    'og_title'      => 'rank_math_facebook_title',
                    'og_description'=> 'rank_math_facebook_description',
                    'og_image_id'   => 'rank_math_facebook_image_id',
                ];

            case 'loiq_seo':
                return [
                    'title'         => '_loiq_seo_title',
                    'description'   => '_loiq_seo_description',
                    'focus_keyword' => '_loiq_seo_focus_keyword',
                    'canonical'     => '_loiq_seo_canonical',
                    'og_title'      => '_loiq_seo_og_title',
                    'og_description'=> '_loiq_seo_og_description',
                    'og_image_id'   => '_loiq_seo_og_image_id',
                ];

            default: // native
                return [
                    'title'         => '_loiq_seo_title',
                    'description'   => '_loiq_seo_description',
                    'focus_keyword' => '_loiq_seo_focus_keyword',
                    'canonical'     => '_loiq_seo_canonical',
                    'og_title'      => '_loiq_seo_og_title',
                    'og_description'=> '_loiq_seo_og_description',
                    'og_image_id'   => '_loiq_seo_og_image_id',
                ];
        }
    }

    /**
     * Get current robots meta directives for a post.
     *
     * @param int    $post_id
     * @param string $seo_plugin
     * @return array
     */
    private static function get_robots_meta(int $post_id, string $seo_plugin): array {
        switch ($seo_plugin) {
            case 'yoast':
                $noindex = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
                $nofollow = get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true);
                return [
                    'noindex'  => $noindex === '1',
                    'nofollow' => $nofollow === '1',
                ];

            case 'rankmath':
                $robots = get_post_meta($post_id, 'rank_math_robots', true);
                $robots_arr = is_array($robots) ? $robots : [];
                return [
                    'noindex'  => in_array('noindex', $robots_arr, true),
                    'nofollow' => in_array('nofollow', $robots_arr, true),
                ];

            default:
                return [
                    'noindex'  => (bool) get_post_meta($post_id, '_loiq_seo_noindex', true),
                    'nofollow' => (bool) get_post_meta($post_id, '_loiq_seo_nofollow', true),
                ];
        }
    }

    /**
     * Set robots meta directives for a post.
     *
     * @param int    $post_id
     * @param array  $robots
     * @param string $seo_plugin
     */
    private static function set_robots_meta(int $post_id, array $robots, string $seo_plugin): void {
        switch ($seo_plugin) {
            case 'yoast':
                if (isset($robots['noindex'])) {
                    update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $robots['noindex'] ? '1' : '0');
                }
                if (isset($robots['nofollow'])) {
                    update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', $robots['nofollow'] ? '1' : '0');
                }
                break;

            case 'rankmath':
                $current = get_post_meta($post_id, 'rank_math_robots', true);
                $current = is_array($current) ? $current : ['index', 'follow'];
                if (isset($robots['noindex'])) {
                    $current = array_diff($current, ['index', 'noindex']);
                    $current[] = $robots['noindex'] ? 'noindex' : 'index';
                }
                if (isset($robots['nofollow'])) {
                    $current = array_diff($current, ['follow', 'nofollow']);
                    $current[] = $robots['nofollow'] ? 'nofollow' : 'follow';
                }
                update_post_meta($post_id, 'rank_math_robots', array_values($current));
                break;

            default:
                if (isset($robots['noindex'])) {
                    update_post_meta($post_id, '_loiq_seo_noindex', $robots['noindex'] ? '1' : '');
                }
                if (isset($robots['nofollow'])) {
                    update_post_meta($post_id, '_loiq_seo_nofollow', $robots['nofollow'] ? '1' : '');
                }
                break;
        }
    }

    // =========================================================================
    // STATUS / DASHBOARD
    // =========================================================================

    /**
     * Get SEO status for a single post.
     *
     * @param int $post_id
     * @return array|WP_Error
     */
    private static function get_post_seo_status(int $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', "Post #{$post_id} niet gevonden", ['status' => 404]);
        }

        $seo_plugin = self::detect_seo_plugin();
        $meta_map = self::get_meta_map($seo_plugin);

        // Gather SEO data
        $title = !empty($meta_map['title']) ? get_post_meta($post_id, $meta_map['title'], true) : '';
        $desc  = !empty($meta_map['description']) ? get_post_meta($post_id, $meta_map['description'], true) : '';
        $focus = !empty($meta_map['focus_keyword']) ? get_post_meta($post_id, $meta_map['focus_keyword'], true) : '';
        $canonical = !empty($meta_map['canonical']) ? get_post_meta($post_id, $meta_map['canonical'], true) : '';
        $schema = get_post_meta($post_id, '_loiq_seo_schema', true);
        $robots = self::get_robots_meta($post_id, $seo_plugin);

        // Content analysis
        $content = $post->post_content;
        $word_count = str_word_count(wp_strip_all_tags($content));
        $has_h1 = (bool) preg_match('/<h1\b/i', $content);
        $has_images = (bool) preg_match('/<img\b/i', $content);
        $has_internal_links = (bool) preg_match('/href=["\'][^"\']*' . preg_quote(wp_parse_url(home_url(), PHP_URL_HOST), '/') . '/i', $content);

        // Score components
        $checks = [
            'has_seo_title'      => !empty($title),
            'has_meta_desc'      => !empty($desc),
            'desc_length_ok'     => strlen($desc) >= 120 && strlen($desc) <= 160,
            'has_focus_keyword'  => !empty($focus),
            'has_schema'         => !empty($schema),
            'word_count_ok'      => $word_count >= 300,
            'has_images'         => $has_images,
            'has_internal_links' => $has_internal_links,
            'is_indexable'       => empty($robots['noindex']),
        ];

        $score = (int) round((array_sum(array_map('intval', $checks)) / count($checks)) * 100);

        return [
            'post_id'       => $post_id,
            'title'         => $post->post_title,
            'seo_plugin'    => $seo_plugin,
            'seo_title'     => $title ?: null,
            'meta_desc'     => $desc ?: null,
            'focus_keyword' => $focus ?: null,
            'canonical'     => $canonical ?: null,
            'robots'        => $robots,
            'schema_types'  => $schema ? self::extract_schema_types(json_decode($schema, true) ?: []) : [],
            'content_stats' => [
                'word_count'    => $word_count,
                'has_h1'        => $has_h1,
                'has_images'    => $has_images,
                'has_links'     => $has_internal_links,
            ],
            'checks'        => $checks,
            'score'         => $score,
        ];
    }

    /**
     * Get site-wide SEO overview.
     *
     * @return array
     */
    private static function get_site_seo_status(): array {
        global $wpdb;

        $seo_plugin = self::detect_seo_plugin();
        $meta_map = self::get_meta_map($seo_plugin);

        // Count published pages/posts
        $total_pages = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page'");
        $total_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'");

        // Count pages with SEO meta
        $meta_key = $meta_map['title'] ?? '_loiq_seo_title';
        $with_title = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
             AND pm.meta_key = %s AND pm.meta_value != ''",
            $meta_key
        ));

        $desc_key = $meta_map['description'] ?? '_loiq_seo_description';
        $with_desc = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
             AND pm.meta_key = %s AND pm.meta_value != ''",
            $desc_key
        ));

        // Count pages with schema
        $with_schema = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
             AND pm.meta_key = '_loiq_seo_schema' AND pm.meta_value != ''"
        );

        $total = $total_pages + $total_posts;

        // Find pages missing SEO title (top priority)
        $missing_title = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
             AND (pm.meta_value IS NULL OR pm.meta_value = '')
             ORDER BY p.post_type ASC, p.menu_order ASC
             LIMIT 20",
            $meta_key
        ));

        $missing = [];
        foreach ($missing_title as $row) {
            $missing[] = [
                'id'    => (int) $row->ID,
                'title' => $row->post_title,
                'type'  => $row->post_type,
                'url'   => get_permalink($row->ID),
            ];
        }

        return [
            'seo_plugin'   => $seo_plugin,
            'totals'       => [
                'pages'      => $total_pages,
                'posts'      => $total_posts,
                'total'      => $total,
            ],
            'coverage'     => [
                'with_seo_title' => $with_title,
                'with_meta_desc' => $with_desc,
                'with_schema'    => $with_schema,
                'title_pct'      => $total > 0 ? round(($with_title / $total) * 100) : 0,
                'desc_pct'       => $total > 0 ? round(($with_desc / $total) * 100) : 0,
                'schema_pct'     => $total > 0 ? round(($with_schema / $total) * 100) : 0,
            ],
            'missing_title' => $missing,
            'power_mode'    => LOIQ_Agent_Safeguards::is_enabled('seo'),
        ];
    }

    // =========================================================================
    // SCHEMA OUTPUT (wp_head)
    // =========================================================================

    /**
     * Output JSON-LD schema in wp_head for the current post.
     * Called via add_action('wp_head', ...).
     */
    public static function output_schema(): void {
        if (!is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        $schema_json = get_post_meta($post_id, '_loiq_seo_schema', true);
        if (empty($schema_json)) {
            return;
        }

        // Validate JSON
        $schema = json_decode($schema_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($schema)) {
            return;
        }

        printf(
            '<script type="application/ld+json">%s</script>' . "\n",
            wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
}
