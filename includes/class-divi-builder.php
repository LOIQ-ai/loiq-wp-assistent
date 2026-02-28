<?php
/**
 * LOIQ Agent Divi Builder
 *
 * Divi shortcode builder, parser, and validator. JSON↔shortcode conversion,
 * module registry, and page template library.
 *
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

class LOIQ_Agent_Divi_Builder {

    /**
     * Divi Module Registry — 20+ modules with metadata
     */
    private static $module_registry = [
        // SECTIONS
        'section' => [
            'tag' => 'et_pb_section',
            'category' => 'structure',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'fullwidth' => 'off',
                'specialty' => 'off',
                'background_color' => '',
                'background_image' => '',
                'inner_shadow' => 'off',
                'parallax' => 'off',
                'transparent_background' => 'off',
                'admin_label' => '',
                'module_class' => '',
                'module_id' => '',
            ],
        ],
        'fullwidth_section' => [
            'tag' => 'et_pb_section',
            'category' => 'structure',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'fullwidth' => 'on',
                'background_color' => '',
                'admin_label' => '',
            ],
        ],
        // ROWS
        'row' => [
            'tag' => 'et_pb_row',
            'category' => 'structure',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'column_structure' => '1_1',
                'use_custom_gutter' => 'off',
                'gutter_width' => '3',
                'make_fullwidth' => 'off',
                'admin_label' => '',
                'module_class' => '',
            ],
        ],
        'row_inner' => [
            'tag' => 'et_pb_row_inner',
            'category' => 'structure',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [],
        ],
        // COLUMNS
        'column' => [
            'tag' => 'et_pb_column',
            'category' => 'structure',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'type' => '1_1',
            ],
        ],
        'column_inner' => [
            'tag' => 'et_pb_column_inner',
            'category' => 'structure',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => ['type' => '1_1'],
        ],
        // MODULES
        'text' => [
            'tag' => 'et_pb_text',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'background_layout' => 'light',
                'text_orientation' => 'left',
                'admin_label' => '',
                'module_class' => '',
            ],
        ],
        'image' => [
            'tag' => 'et_pb_image',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'src' => '',
                'alt' => '',
                'title_text' => '',
                'url' => '',
                'url_new_window' => 'off',
                'show_in_lightbox' => 'off',
                'align' => 'left',
                'force_fullwidth' => 'off',
            ],
        ],
        'button' => [
            'tag' => 'et_pb_button',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'button_text' => '',
                'button_url' => '#',
                'url_new_window' => 'off',
                'button_alignment' => 'left',
                'custom_button' => 'off',
                'button_bg_color' => '',
                'button_text_color' => '',
            ],
        ],
        'blurb' => [
            'tag' => 'et_pb_blurb',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'title' => '',
                'url' => '',
                'image' => '',
                'use_icon' => 'off',
                'font_icon' => '',
                'icon_color' => '',
                'icon_placement' => 'top',
                'content_max_width' => '550px',
            ],
        ],
        'cta' => [
            'tag' => 'et_pb_cta',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'title' => '',
                'button_text' => '',
                'button_url' => '#',
                'url_new_window' => 'off',
                'background_color' => '',
                'use_background_color' => 'on',
            ],
        ],
        'divider' => [
            'tag' => 'et_pb_divider',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'show_divider' => 'on',
                'divider_style' => 'solid',
                'divider_weight' => '1px',
                'color' => '#333333',
            ],
        ],
        'code' => [
            'tag' => 'et_pb_code',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => ['admin_label' => ''],
        ],
        'sidebar' => [
            'tag' => 'et_pb_sidebar',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'area' => '',
                'orientation' => 'left',
                'show_border' => 'on',
            ],
        ],
        'slider' => [
            'tag' => 'et_pb_slider',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'show_arrows' => 'on',
                'show_pagination' => 'on',
                'auto' => 'off',
                'auto_speed' => '7000',
            ],
        ],
        'slide' => [
            'tag' => 'et_pb_slide',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'heading' => '',
                'button_text' => '',
                'button_link' => '#',
                'background_color' => '',
                'background_image' => '',
            ],
        ],
        'testimonial' => [
            'tag' => 'et_pb_testimonial',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'author' => '',
                'job_title' => '',
                'company_name' => '',
                'url' => '',
                'portrait_url' => '',
                'quote_icon' => 'on',
            ],
        ],
        'contact_form' => [
            'tag' => 'et_pb_contact_form',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'email' => '',
                'title' => '',
                'captcha' => 'on',
                'submit_button_text' => 'Verzenden',
            ],
        ],
        'contact_field' => [
            'tag' => 'et_pb_contact_field',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'field_id' => '',
                'field_title' => '',
                'field_type' => 'input',
                'required_mark' => 'on',
                'fullwidth_field' => 'off',
            ],
        ],
        'blog' => [
            'tag' => 'et_pb_blog',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'fullwidth' => 'on',
                'posts_number' => '10',
                'include_categories' => '',
                'show_author' => 'on',
                'show_date' => 'on',
                'show_categories' => 'on',
                'show_excerpt' => 'on',
            ],
        ],
        'map' => [
            'tag' => 'et_pb_map',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'address' => '',
                'zoom_level' => '12',
                'address_lat' => '',
                'address_lng' => '',
                'mouse_wheel' => 'on',
            ],
        ],
        'map_pin' => [
            'tag' => 'et_pb_map_pin',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'title' => '',
                'pin_address' => '',
                'pin_address_lat' => '',
                'pin_address_lng' => '',
            ],
        ],
        'fullwidth_header' => [
            'tag' => 'et_pb_fullwidth_header',
            'category' => 'fullwidth_module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'title' => '',
                'subhead' => '',
                'background_overlay_color' => '',
                'background_color' => '',
                'background_image' => '',
                'text_orientation' => 'center',
                'header_fullscreen' => 'off',
                'header_scroll_down' => 'off',
                'button_one_text' => '',
                'button_one_url' => '#',
                'button_two_text' => '',
                'button_two_url' => '#',
            ],
        ],
        'fullwidth_image' => [
            'tag' => 'et_pb_fullwidth_image',
            'category' => 'fullwidth_module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'src' => '',
                'alt' => '',
                'url' => '',
                'url_new_window' => 'off',
            ],
        ],
        'gallery' => [
            'tag' => 'et_pb_gallery',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'gallery_ids' => '',
                'fullwidth' => 'off',
                'posts_number' => '4',
                'show_title_and_caption' => 'on',
            ],
        ],
        'video' => [
            'tag' => 'et_pb_video',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'src' => '',
                'image_src' => '',
            ],
        ],
        'accordion' => [
            'tag' => 'et_pb_accordion',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => ['open_toggle_background_color' => ''],
        ],
        'accordion_item' => [
            'tag' => 'et_pb_accordion_item',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => ['title' => '', 'open' => 'off'],
        ],
        'toggle' => [
            'tag' => 'et_pb_toggle',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => ['title' => '', 'open' => 'off'],
        ],
        'tabs' => [
            'tag' => 'et_pb_tabs',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [],
        ],
        'tab' => [
            'tag' => 'et_pb_tab',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => ['title' => ''],
        ],
        'counter' => [
            'tag' => 'et_pb_counter',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => ['percent' => '0'],
        ],
        'counters' => [
            'tag' => 'et_pb_counters',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [],
        ],
        'number_counter' => [
            'tag' => 'et_pb_number_counter',
            'category' => 'module',
            'self_closing' => true,
            'has_content' => false,
            'common_attrs' => [
                'title' => '',
                'number' => '0',
                'percent_sign' => 'on',
            ],
        ],
        'social_media_follow' => [
            'tag' => 'et_pb_social_media_follow',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => ['link_shape' => 'rounded_rectangle'],
        ],
        'social_media_follow_network' => [
            'tag' => 'et_pb_social_media_follow_network',
            'category' => 'module',
            'self_closing' => false,
            'has_content' => true,
            'common_attrs' => [
                'social_network' => 'facebook',
                'url' => '',
                'background_color' => '',
            ],
        ],
    ];

    // =========================================================================
    // ROUTE REGISTRATION
    // =========================================================================

    public static function register_routes($plugin) {
        $ns = 'claude/v3';

        // Build: JSON → Divi shortcode (write)
        register_rest_route($ns, '/divi/build', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_build'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'json' => ['required' => true, 'type' => 'object'],
            ],
        ]);

        // Parse: Divi shortcode → JSON (read)
        register_rest_route($ns, '/divi/parse', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_parse'],
            'permission_callback' => [$plugin, 'check_permission'],
            'args'                => [
                'content' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        // Validate shortcode structure (read)
        register_rest_route($ns, '/divi/validate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_validate'],
            'permission_callback' => [$plugin, 'check_permission'],
            'args'                => [
                'content' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        // Module registry (read)
        register_rest_route($ns, '/divi/modules', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_modules'],
            'permission_callback' => [$plugin, 'check_permission'],
        ]);

        // Template list (read)
        register_rest_route($ns, '/divi/templates', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_templates_list'],
            'permission_callback' => [$plugin, 'check_permission'],
        ]);

        // Template detail (read)
        register_rest_route($ns, '/divi/template/(?P<name>[a-z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_template_detail'],
            'permission_callback' => [$plugin, 'check_permission'],
            'args'                => [
                'name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_file_name'],
            ],
        ]);
    }

    // =========================================================================
    // ENDPOINT HANDLERS
    // =========================================================================

    /**
     * POST /claude/v3/divi/build — JSON → Divi shortcode
     */
    public static function handle_build(WP_REST_Request $request) {
        $json = $request->get_param('json');

        if (empty($json) || !is_array($json)) {
            return new WP_Error('invalid_json', 'JSON structuur is ongeldig', ['status' => 400]);
        }

        $shortcode = self::build_shortcode($json);
        if (is_wp_error($shortcode)) return $shortcode;

        // Validate the generated shortcode
        $validation = self::validate_structure($shortcode);

        return [
            'success'    => true,
            'shortcode'  => $shortcode,
            'length'     => strlen($shortcode),
            'valid'      => !is_wp_error($validation),
            'validation' => is_wp_error($validation) ? $validation->get_error_message() : 'OK',
        ];
    }

    /**
     * POST /claude/v3/divi/parse — Divi shortcode → JSON
     */
    public static function handle_parse(WP_REST_Request $request) {
        $content = $request->get_param('content');

        $parsed = self::parse_shortcode($content);
        if (is_wp_error($parsed)) return $parsed;

        return [
            'success' => true,
            'json'    => $parsed,
        ];
    }

    /**
     * POST /claude/v3/divi/validate
     */
    public static function handle_validate(WP_REST_Request $request) {
        $content = $request->get_param('content');

        $result = self::validate_structure($content);

        if (is_wp_error($result)) {
            return [
                'valid'   => false,
                'errors'  => [$result->get_error_message()],
            ];
        }

        return [
            'valid'  => true,
            'errors' => [],
        ];
    }

    /**
     * GET /claude/v3/divi/modules
     */
    public static function handle_modules(WP_REST_Request $request) {
        $modules = [];
        foreach (self::$module_registry as $name => $meta) {
            $modules[] = [
                'name'         => $name,
                'tag'          => $meta['tag'],
                'category'     => $meta['category'],
                'self_closing' => $meta['self_closing'],
                'has_content'  => $meta['has_content'],
                'attributes'   => array_keys($meta['common_attrs']),
            ];
        }

        return [
            'total'   => count($modules),
            'modules' => $modules,
        ];
    }

    /**
     * GET /claude/v3/divi/templates
     */
    public static function handle_templates_list(WP_REST_Request $request) {
        $template_dir = LOIQ_AGENT_PATH . 'templates';
        if (!is_dir($template_dir)) {
            return ['templates' => []];
        }

        $files = glob($template_dir . '/*.json');
        $templates = [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin template file
            if ($data) {
                $templates[] = [
                    'name'        => basename($file, '.json'),
                    'title'       => $data['name'] ?? basename($file, '.json'),
                    'description' => $data['description'] ?? '',
                ];
            }
        }

        return ['templates' => $templates];
    }

    /**
     * GET /claude/v3/divi/template/{name}
     */
    public static function handle_template_detail(WP_REST_Request $request) {
        $name = $request->get_param('name');
        $file = LOIQ_AGENT_PATH . 'templates/' . sanitize_file_name($name) . '.json';

        if (!file_exists($file)) {
            return new WP_Error('template_not_found', "Template '{$name}' niet gevonden", ['status' => 404]);
        }

        $data = json_decode(file_get_contents($file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin template file
        if (!$data) {
            return new WP_Error('invalid_template', 'Template JSON is ongeldig', ['status' => 500]);
        }

        return $data;
    }

    // =========================================================================
    // JSON → SHORTCODE BUILDER
    // =========================================================================

    /**
     * Convert JSON structure to Divi shortcodes.
     *
     * JSON format:
     * {
     *   "sections": [
     *     {
     *       "type": "section",
     *       "settings": {"background_color": "#ffffff"},
     *       "content": "<p>Direct text</p>",
     *       "children": [
     *         {"type": "row", "settings": {}, "children": [...]}
     *       ]
     *     }
     *   ]
     * }
     *
     * @param array $json
     * @return string|WP_Error
     */
    public static function build_shortcode($json) {
        if (empty($json['sections']) || !is_array($json['sections'])) {
            return new WP_Error('no_sections', 'JSON moet een "sections" array bevatten', ['status' => 400]);
        }

        $output = '';
        foreach ($json['sections'] as $section) {
            $result = self::build_node($section);
            if (is_wp_error($result)) return $result;
            $output .= $result . "\n\n";
        }

        return trim($output);
    }

    /**
     * Recursively build a single node into a shortcode string.
     *
     * @param array $node
     * @return string|WP_Error
     */
    private static function build_node($node) {
        if (empty($node['type'])) {
            return new WP_Error('missing_type', 'Node mist "type" property', ['status' => 400]);
        }

        $type = $node['type'];
        $module = self::$module_registry[$type] ?? null;

        if (!$module) {
            return new WP_Error('unknown_module', "Onbekend Divi module type: {$type}", ['status' => 400]);
        }

        $tag = $module['tag'];
        $settings = $node['settings'] ?? [];
        $content = $node['content'] ?? '';
        $children = $node['children'] ?? [];

        // Handle column_structure → separate columns in row
        if ($type === 'row' && !empty($settings['column_structure'])) {
            $structure = $settings['column_structure'];
            unset($settings['column_structure']);
            // If no children specified, auto-generate columns from structure
            if (empty($children)) {
                $col_sizes = explode(',', $structure);
                foreach ($col_sizes as $size) {
                    $children[] = [
                        'type' => 'column',
                        'settings' => ['type' => trim($size)],
                        'children' => [],
                    ];
                }
            }
        }

        // Build attribute string
        $attrs = self::build_attrs($settings);

        // Build inner content
        $inner = '';

        // Process children first
        if (!empty($children)) {
            $parts = [];
            foreach ($children as $child) {
                $child_result = self::build_node($child);
                if (is_wp_error($child_result)) return $child_result;
                $parts[] = $child_result;
            }
            $inner = "\n" . implode("\n", $parts) . "\n";
        } elseif (!empty($content)) {
            $inner = "\n" . $content . "\n";
        }

        // Self-closing modules
        if ($module['self_closing'] && empty($inner)) {
            return "[{$tag}{$attrs}][/{$tag}]";
        }

        return "[{$tag}{$attrs}]{$inner}[/{$tag}]";
    }

    /**
     * Build attribute string from settings array.
     */
    private static function build_attrs(array $settings) {
        if (empty($settings)) return '';

        $parts = [];
        foreach ($settings as $key => $value) {
            if ($value === '' || $value === null) continue;
            $safe_key = sanitize_key($key);
            $safe_value = esc_attr($value);
            $parts[] = "{$safe_key}=\"{$safe_value}\"";
        }

        return empty($parts) ? '' : ' ' . implode(' ', $parts);
    }

    // =========================================================================
    // SHORTCODE → JSON PARSER
    // =========================================================================

    /**
     * Parse Divi shortcodes into JSON structure.
     *
     * @param string $content  Raw Divi shortcode content
     * @return array|WP_Error
     */
    public static function parse_shortcode($content) {
        if (empty($content)) {
            return ['sections' => []];
        }

        $tokens = self::tokenize($content);
        if (is_wp_error($tokens)) return $tokens;

        $tree = self::tokens_to_tree($tokens);
        if (is_wp_error($tree)) return $tree;

        return ['sections' => $tree];
    }

    /**
     * Tokenize Divi shortcodes into an array of tokens.
     *
     * @param string $content
     * @return array|WP_Error
     */
    private static function tokenize($content) {
        $tokens = [];
        $pattern = '/\[(\/?)et_pb_(\w+)([^\]]*)\]/';
        $offset = 0;

        while (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $full_match = $match[0][0];
            $match_pos = $match[0][1];
            $is_closing = !empty($match[1][0]);
            $module_name = $match[2][0];
            $attr_string = trim($match[3][0]);

            // Capture any text content between tags
            if ($match_pos > $offset) {
                $text = substr($content, $offset, $match_pos - $offset);
                $text = trim($text);
                if (!empty($text)) {
                    $tokens[] = ['type' => 'text', 'content' => $text];
                }
            }

            if ($is_closing) {
                $tokens[] = ['type' => 'close', 'tag' => $module_name];
            } else {
                $attrs = self::parse_attrs($attr_string);
                $tokens[] = ['type' => 'open', 'tag' => $module_name, 'attrs' => $attrs];
            }

            $offset = $match_pos + strlen($full_match);
        }

        // Capture trailing text
        if ($offset < strlen($content)) {
            $text = trim(substr($content, $offset));
            if (!empty($text)) {
                $tokens[] = ['type' => 'text', 'content' => $text];
            }
        }

        return $tokens;
    }

    /**
     * Parse shortcode attribute string into key-value pairs.
     */
    private static function parse_attrs($attr_string) {
        $attrs = [];
        if (empty($attr_string)) return $attrs;

        // Match key="value" pairs
        preg_match_all('/(\w+)="([^"]*)"/', $attr_string, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $attrs[$m[1]] = $m[2];
        }

        return $attrs;
    }

    /**
     * Convert token array into a nested tree structure.
     */
    private static function tokens_to_tree(array $tokens) {
        $stack = [];
        $root = [];
        $current = &$root;

        foreach ($tokens as $token) {
            if ($token['type'] === 'open') {
                $node = self::tag_to_node($token['tag'], $token['attrs']);
                $current[] = $node;
                $stack[] = &$current;
                $current = &$current[count($current) - 1]['children'];
            } elseif ($token['type'] === 'close') {
                if (empty($stack)) continue;
                $current = &$stack[count($stack) - 1];
                array_pop($stack);
            } elseif ($token['type'] === 'text') {
                if (!empty($stack)) {
                    // Add text as content to parent node
                    $parent_idx = count($current) > 0 ? count($current) - 1 : null;
                    // Find the parent in the stack
                    $parent_ref = &$stack[count($stack) - 1];
                    $parent_node_idx = count($parent_ref) - 1;
                    if ($parent_node_idx >= 0) {
                        if (empty($parent_ref[$parent_node_idx]['content'])) {
                            $parent_ref[$parent_node_idx]['content'] = $token['content'];
                        } else {
                            $parent_ref[$parent_node_idx]['content'] .= $token['content'];
                        }
                    }
                }
            }
        }

        return $root;
    }

    /**
     * Map a Divi tag name back to a node type.
     */
    private static function tag_to_node($tag, $attrs) {
        // Find the module by tag name
        $type = $tag; // default to tag name without et_pb_ prefix
        foreach (self::$module_registry as $name => $meta) {
            if ($meta['tag'] === 'et_pb_' . $tag) {
                $type = $name;
                break;
            }
        }

        $node = [
            'type'     => $type,
            'settings' => $attrs,
            'children' => [],
        ];

        return $node;
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Validate Divi shortcode structure.
     * Checks: section > row > column > module hierarchy.
     *
     * @param string $content
     * @return true|WP_Error
     */
    public static function validate_structure($content) {
        if (empty($content)) {
            return new WP_Error('empty_content', 'Content is leeg', ['status' => 400]);
        }

        // Must start with a section
        if (strpos($content, '[et_pb_section') === false) {
            return new WP_Error('no_section', 'Divi content moet minimaal één et_pb_section bevatten', ['status' => 400]);
        }

        // Check balanced tags
        preg_match_all('/\[et_pb_(\w+)[^\]]*\]/', $content, $opens);
        preg_match_all('/\[\/et_pb_(\w+)\]/', $content, $closes);

        $open_counts = array_count_values($opens[1]);
        $close_counts = array_count_values($closes[1]);

        foreach ($open_counts as $tag => $count) {
            $close_count = $close_counts[$tag] ?? 0;
            if ($count !== $close_count) {
                return new WP_Error('unbalanced_tags',
                    "Ongebalanceerde tags: et_pb_{$tag} — {$count} open, {$close_count} gesloten",
                    ['status' => 400]
                );
            }
        }

        // Check hierarchy: sections should contain rows (or fullwidth modules)
        // Rows should contain columns, columns should contain modules
        // This is a basic check — not exhaustive
        $has_row = strpos($content, '[et_pb_row') !== false;
        $has_fullwidth_module = preg_match('/\[et_pb_fullwidth_/', $content);

        if (!$has_row && !$has_fullwidth_module) {
            return new WP_Error('no_row', 'Divi sections moeten rows of fullwidth modules bevatten', ['status' => 400]);
        }

        return true;
    }

    // =========================================================================
    // PUBLIC HELPERS
    // =========================================================================

    /**
     * Get module info by name.
     *
     * @param string $name
     * @return array|null
     */
    public static function get_module($name) {
        return self::$module_registry[$name] ?? null;
    }

    /**
     * Check if a module type exists in the registry.
     *
     * @param string $name
     * @return bool
     */
    public static function module_exists($name) {
        return isset(self::$module_registry[$name]);
    }
}
