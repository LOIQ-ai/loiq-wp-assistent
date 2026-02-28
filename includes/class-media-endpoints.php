<?php
/**
 * LOIQ Agent Media Endpoints
 *
 * REST API endpoints for WordPress media library management:
 * upload images (base64 or URL) and search the media library.
 *
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

class LOIQ_Agent_Media_Endpoints {

    /** Maximum upload size in bytes (5MB) */
    const MAX_UPLOAD_SIZE = 5 * 1024 * 1024;

    /** Allowed MIME types for upload */
    private static $allowed_mime_types = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    /**
     * Register all v3 media REST routes.
     *
     * @param LOIQ_WP_Agent $plugin  Reference to main plugin for permission callbacks
     */
    public static function register_routes($plugin) {
        $namespace = 'claude/v3';

        // --- WRITE ENDPOINTS ---

        register_rest_route($namespace, '/media/upload', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_media_upload'],
            'permission_callback' => [$plugin, 'check_write_permission'],
            'args'                => [
                'source'   => ['required' => true, 'type' => 'string'],
                'filename' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_file_name'],
                'alt_text' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'title'    => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'dry_run'  => ['required' => false, 'type' => 'boolean', 'default' => false],
            ],
        ]);

        // --- READ ENDPOINTS ---

        register_rest_route($namespace, '/media/search', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_media_search'],
            'permission_callback' => [$plugin, 'check_permission'],
            'args'                => [
                'search'    => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'mime_type' => ['required' => false, 'type' => 'string', 'default' => 'image', 'sanitize_callback' => 'sanitize_text_field'],
                'per_page'  => ['required' => false, 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint'],
                'page'      => ['required' => false, 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    // =========================================================================
    // WRITE HANDLERS
    // =========================================================================

    /**
     * POST /claude/v3/media/upload
     *
     * Uploads an image to the WordPress media library.
     * Accepts either a base64-encoded string or a URL as the source.
     */
    public static function handle_media_upload(WP_REST_Request $request) {
        if (!LOIQ_Agent_Safeguards::is_enabled('media')) {
            return new WP_Error('power_mode_off', "Power mode voor 'media' is uitgeschakeld. Enable via wp-admin.", ['status' => 403]);
        }

        $source   = $request->get_param('source');
        $filename = $request->get_param('filename');
        $alt_text = $request->get_param('alt_text');
        $title    = $request->get_param('title');
        $dry_run  = (bool) $request->get_param('dry_run');
        $session  = sanitize_text_field($request->get_header('X-Claude-Session') ?? '');

        // Validate filename extension
        $filetype = wp_check_filetype($filename);
        if (empty($filetype['type'])) {
            return new WP_Error('invalid_filetype', "Bestandstype niet herkend voor '{$filename}'", ['status' => 400]);
        }

        // Validate MIME type is image
        if (strpos($filetype['type'], 'image/') !== 0) {
            return new WP_Error('not_image', "Alleen image/* MIME types toegestaan. Ontvangen: {$filetype['type']}", ['status' => 400]);
        }

        if (!in_array($filetype['type'], self::$allowed_mime_types, true)) {
            return new WP_Error('mime_not_allowed',
                "MIME type '{$filetype['type']}' is niet toegestaan. Toegestaan: " . implode(', ', self::$allowed_mime_types),
                ['status' => 400]
            );
        }

        // Determine if source is URL or base64
        $is_url = (filter_var($source, FILTER_VALIDATE_URL) !== false);
        $is_base64 = !$is_url && preg_match('/^[A-Za-z0-9+\/=\s]+$/', substr($source, 0, 100));

        // Also handle data URI format: data:image/png;base64,xxxxx
        if (!$is_url && !$is_base64 && preg_match('/^data:image\/[^;]+;base64,/', $source)) {
            $source = preg_replace('/^data:image\/[^;]+;base64,/', '', $source);
            $is_base64 = true;
        }

        if (!$is_url && !$is_base64) {
            return new WP_Error('invalid_source', 'Source moet een geldige URL of base64-encoded string zijn', ['status' => 400]);
        }

        // Size validation for base64
        if ($is_base64) {
            $decoded_size = (int) (strlen($source) * 3 / 4);
            if ($decoded_size > self::MAX_UPLOAD_SIZE) {
                return new WP_Error('file_too_large',
                    'Bestand is te groot. Maximum: ' . size_format(self::MAX_UPLOAD_SIZE) . ', ontvangen: ~' . size_format($decoded_size),
                    ['status' => 400]
                );
            }
        }

        // Snapshot: before_value = null (new upload)
        $snapshot_id = LOIQ_Agent_Safeguards::create_snapshot('media', $filename, null, null, false, $session);
        LOIQ_Agent_Audit::log_write($request->get_route(), 200, 'media', $filename, $session, $dry_run);

        if ($dry_run) {
            return [
                'success'     => true,
                'dry_run'     => true,
                'snapshot_id' => $snapshot_id,
                'filename'    => $filename,
                'mime_type'   => $filetype['type'],
                'source_type' => $is_url ? 'url' : 'base64',
                'alt_text'    => $alt_text,
                'title'       => $title,
            ];
        }

        // Require necessary files
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attachment_id = null;

        if ($is_url) {
            // Download from URL
            $tmp_file = download_url($source, 30);
            if (is_wp_error($tmp_file)) {
                return new WP_Error('download_failed', 'Download mislukt: ' . $tmp_file->get_error_message(), ['status' => 500]);
            }

            // Validate size after download
            $file_size = filesize($tmp_file);
            if ($file_size > self::MAX_UPLOAD_SIZE) {
                @unlink($tmp_file);
                return new WP_Error('file_too_large',
                    'Gedownload bestand is te groot. Maximum: ' . size_format(self::MAX_UPLOAD_SIZE) . ', ontvangen: ' . size_format($file_size),
                    ['status' => 400]
                );
            }

            // Validate actual MIME type
            $actual_type = mime_content_type($tmp_file);
            if (strpos($actual_type, 'image/') !== 0) {
                @unlink($tmp_file);
                return new WP_Error('not_image', "Gedownload bestand is geen afbeelding. MIME type: {$actual_type}", ['status' => 400]);
            }

            $file_array = [
                'name'     => $filename,
                'tmp_name' => $tmp_file,
            ];

            $attachment_id = media_handle_sideload($file_array, 0, $title ?: '');

            // Cleanup temp file if sideload failed
            if (is_wp_error($attachment_id)) {
                @unlink($tmp_file);
                return new WP_Error('upload_failed', 'Upload mislukt: ' . $attachment_id->get_error_message(), ['status' => 500]);
            }
        } else {
            // Base64 decode and write to temp file
            $decoded = base64_decode($source, true);
            if ($decoded === false) {
                return new WP_Error('base64_invalid', 'Ongeldige base64 data', ['status' => 400]);
            }

            if (strlen($decoded) > self::MAX_UPLOAD_SIZE) {
                return new WP_Error('file_too_large',
                    'Bestand is te groot. Maximum: ' . size_format(self::MAX_UPLOAD_SIZE),
                    ['status' => 400]
                );
            }

            $tmp_file = wp_tempnam($filename);
            if (!$tmp_file) {
                return new WP_Error('temp_failed', 'Kan tijdelijk bestand niet aanmaken', ['status' => 500]);
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file for base64 decode, used with wp_tempnam()
            file_put_contents($tmp_file, $decoded);

            // Validate actual MIME type
            $actual_type = mime_content_type($tmp_file);
            if (strpos($actual_type, 'image/') !== 0) {
                @unlink($tmp_file);
                return new WP_Error('not_image', "Gedecodeerd bestand is geen afbeelding. MIME type: {$actual_type}", ['status' => 400]);
            }

            $file_array = [
                'name'     => $filename,
                'tmp_name' => $tmp_file,
            ];

            $attachment_id = media_handle_sideload($file_array, 0, $title ?: '');

            if (is_wp_error($attachment_id)) {
                @unlink($tmp_file);
                return new WP_Error('upload_failed', 'Upload mislukt: ' . $attachment_id->get_error_message(), ['status' => 500]);
            }
        }

        // Set alt text
        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        }

        // Set title
        if (!empty($title)) {
            wp_update_post([
                'ID'         => $attachment_id,
                'post_title' => $title,
            ]);
        }

        // Get attachment details
        $url       = wp_get_attachment_url($attachment_id);
        $thumbnail = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        $metadata  = wp_get_attachment_metadata($attachment_id);

        // Update snapshot with attachment ID as target_key for rollback
        LOIQ_Agent_Safeguards::mark_executed($snapshot_id, [
            'attachment_id' => $attachment_id,
            'url'           => $url,
        ]);

        return [
            'success'       => true,
            'snapshot_id'   => $snapshot_id,
            'attachment_id' => $attachment_id,
            'url'           => $url,
            'thumbnail'     => $thumbnail ?: $url,
            'width'         => $metadata['width'] ?? null,
            'height'        => $metadata['height'] ?? null,
            'mime_type'     => get_post_mime_type($attachment_id),
            'rollback_url'  => rest_url('claude/v2/rollback?snapshot_id=' . $snapshot_id),
        ];
    }

    // =========================================================================
    // READ HANDLERS
    // =========================================================================

    /**
     * GET /claude/v3/media/search
     *
     * Searches the WordPress media library.
     */
    public static function handle_media_search(WP_REST_Request $request) {
        $search    = $request->get_param('search');
        $mime_type = $request->get_param('mime_type');
        $per_page  = $request->get_param('per_page');
        $page      = $request->get_param('page');

        $query_args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        // Search term
        if (!empty($search)) {
            $query_args['s'] = $search;
        }

        // MIME type filter
        if (!empty($mime_type)) {
            // Allow short names like 'image', 'image/jpeg', etc.
            if (strpos($mime_type, '/') === false) {
                $query_args['post_mime_type'] = $mime_type;
            } else {
                $query_args['post_mime_type'] = $mime_type;
            }
        }

        $query = new WP_Query($query_args);

        $items = [];
        foreach ($query->posts as $post) {
            $metadata  = wp_get_attachment_metadata($post->ID);
            $thumbnail = wp_get_attachment_image_url($post->ID, 'thumbnail');
            $url       = wp_get_attachment_url($post->ID);
            $alt       = get_post_meta($post->ID, '_wp_attachment_image_alt', true);

            $items[] = [
                'id'        => (int) $post->ID,
                'url'       => $url,
                'thumbnail' => $thumbnail ?: $url,
                'title'     => $post->post_title,
                'alt'       => $alt ?: '',
                'width'     => $metadata['width'] ?? null,
                'height'    => $metadata['height'] ?? null,
                'mime_type' => $post->post_mime_type,
                'date'      => $post->post_date,
            ];
        }

        return [
            'total'    => (int) $query->found_posts,
            'pages'    => (int) $query->max_num_pages,
            'page'     => $page,
            'per_page' => $per_page,
            'items'    => $items,
        ];
    }
}
