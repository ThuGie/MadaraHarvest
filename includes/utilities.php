<?php
declare(strict_types=1);

/**
 * Utility functions for MadaraHarvest plugin.
 *
 * Provides helper methods for image processing, string manipulation, and initialization.
 */

/**
 * Initializes plugin options with default values.
 */
function mh_initialize_options(): void {
    // Set default options if not already set
    $defaults = [
        'mh_debug_mode' => 0,
        'mh_custom_user_agent' => 'Mozilla/5.0 (compatible; MadaraHarvest/1.0; +https://your-site.com)',
        'mh_proxy_list' => '',
        'mh_cache_duration' => 300,
        'mh_email_notifications' => 0,
        'mh_notify_email' => get_option('admin_email'),
        'mh_log_retention_days' => 7,
        'mh_max_retries' => 3,
        'mh_request_delay' => 1,
        'mh_parallel_threads' => 1,
        'mh_dry_run' => 0,
        'mh_post_status' => 'publish',
        'mh_force_fetch' => 0,
        'mh_enable_comments' => 0,
        'mh_enable_pingback' => 0,
        'mh_chapter_threshold' => 1,
        'mh_queue_schedule' => 'minute',
        'mh_merge_images' => 0,
        'mh_image_merge_direction' => 'vertical',
        'mh_image_merge_quality' => 75,
        'mh_image_merge_format' => 'avif',
        'mh_image_merge_bg_color' => 'white',
        'mh_setup_complete' => 0,
        'mh_process_manga_paused' => 0,
        'mh_process_chapter_paused' => 0,
        'mh_last_run' => 'Never',
        'mh_error_log' => [],
        'mh_manga_queue' => [],
        'mh_chapter_queue' => [],
        'mh_site_status' => [],
        'mh_last_report' => ['manga_added' => 0, 'chapters_queued' => 0, 'errors' => 0, 'timestamp' => 'N/A']
    ];

    foreach ($defaults as $option => $value) {
        if (get_option($option) === false) {
            update_option($option, $value);
            mh_log("Initialized option '$option' with default value", 'INFO');
        }
    }

    // Ensure sites config is set
    if (get_option('mh_sites_config') === false) {
        update_option('mh_sites_config', mh_get_default_config());
        mh_log("Initialized sites configuration with default value", 'INFO');
    }
}
add_action('init', 'mh_initialize_options');

/**
 * Merges multiple images into a single image based on settings.
 *
 * @param array $image_urls Array of image URLs to merge.
 * @param string $direction Merge direction ('vertical' or 'horizontal').
 * @param int $quality Output quality (0-100).
 * @param string $format Output format ('avif', 'webp', 'jpeg').
 * @param string $bg_color Background color (e.g., 'white', '#FFFFFF').
 * @return string|null Path to merged image on success, null on failure.
 */
function mh_merge_images(array $image_urls, string $direction = 'vertical', int $quality = 75, string $format = 'avif', string $bg_color = 'white'): ?string {
    if (empty($image_urls) || !in_array($direction, ['vertical', 'horizontal']) || !in_array($format, ['avif', 'webp', 'jpeg'])) {
        mh_log("Invalid parameters for image merge: " . print_r(['urls' => $image_urls, 'direction' => $direction, 'format' => $format], true), 'ERROR');
        return null;
    }

    // Check if GD or Imagick is available
    if (!extension_loaded('gd') && !extension_loaded('imagick')) {
        mh_log("Image merging requires GD or Imagick extension", 'ERROR');
        return null;
    }

    $images = [];
    foreach ($image_urls as $url) {
        $file = mh_download_image($url);
        if ($file) {
            $images[] = $file;
        } else {
            mh_log("Failed to download image: $url", 'ERROR');
            foreach ($images as $img) {
                @unlink($img); // Clean up downloaded files
            }
            return null;
        }
    }

    if (empty($images)) {
        mh_log("No valid images to merge", 'ERROR');
        return null;
    }

    try {
        // Use GD for simplicity
        $total_width = 0;
        $total_height = 0;
        $max_width = 0;
        $max_height = 0;
        $image_resources = [];

        foreach ($images as $file) {
            $info = getimagesize($file);
            if (!$info) {
                throw new Exception("Invalid image file: $file");
            }
            $width = $info[0];
            $height = $info[1];
            $type = $info[2];

            switch ($type) {
                case IMAGETYPE_JPEG:
                    $img = imagecreatefromjpeg($file);
                    break;
                case IMAGETYPE_PNG:
                    $img = imagecreatefrompng($file);
                    break;
                case IMAGETYPE_WEBP:
                    $img = imagecreatefromwebp($file);
                    break;
                default:
                    throw new Exception("Unsupported image type for $file");
            }

            if (!$img) {
                throw new Exception("Failed to create image resource from $file");
            }

            $image_resources[] = $img;
            if ($direction === 'vertical') {
                $total_height += $height;
                $max_width = max($max_width, $width);
            } else {
                $total_width += $width;
                $max_height = max($max_height, $height);
            }
        }

        $total_width = $direction === 'vertical' ? $max_width : $total_width;
        $total_height = $direction === 'horizontal' ? $max_height : $total_height;

        $canvas = imagecreatetruecolor($total_width, $total_height);
        if (!$canvas) {
            throw new Exception("Failed to create canvas for merged image");
        }

        // Set background color
        $bg_rgb = mh_hex_to_rgb($bg_color);
        $bg_color_resource = imagecolorallocate($canvas, $bg_rgb['r'], $bg_rgb['g'], $bg_rgb['b']);
        imagefill($canvas, 0, 0, $bg_color_resource);

        $current_x = 0;
        $current_y = 0;
        foreach ($image_resources as $img) {
            $width = imagesx($img);
            $height = imagesy($img);
            imagecopy($canvas, $img, $current_x, $current_y, 0, 0, $width, $height);
            if ($direction === 'vertical') {
                $current_y += $height;
            } else {
                $current_x += $width;
            }
            imagedestroy($img);
        }

        $upload_dir = wp_upload_dir();
        $output_path = $upload_dir['path'] . '/merged_' . uniqid() . '.' . $format;
        switch ($format) {
            case 'avif':
                if (function_exists('imageavif')) {
                    imageavif($canvas, $output_path, $quality);
                } else {
                    throw new Exception("AVIF format not supported");
                }
                break;
            case 'webp':
                imagewebp($canvas, $output_path, $quality);
                break;
            case 'jpeg':
                imagejpeg($canvas, $output_path, $quality);
                break;
        }

        imagedestroy($canvas);
        foreach ($images as $file) {
            @unlink($file); // Clean up temporary files
        }

        mh_log("Merged " . count($images) . " images into $output_path", 'INFO');
        return $output_path;
    } catch (Exception $e) {
        mh_log("Image merge failed: " . $e->getMessage(), 'ERROR');
        foreach ($image_resources as $img) {
            imagedestroy($img);
        }
        if (isset($canvas)) {
            imagedestroy($canvas);
        }
        foreach ($images as $file) {
            @unlink($file);
        }
        return null;
    }
}

/**
 * Downloads an image from a URL to a temporary file.
 *
 * @param string $url Image URL to download.
 * @return string|null Path to downloaded file on success, null on failure.
 */
function mh_download_image(string $url): ?string {
    $response = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => [
            'User-Agent' => get_option('mh_custom_user_agent', 'Mozilla/5.0 (compatible; MadaraHarvest/1.0; +https://your-site.com)')
        ]
    ]);

    if (is_wp_error($response)) {
        mh_log("Failed to download image $url: " . $response->get_error_message(), 'ERROR');
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        mh_log("Empty response body for image $url", 'ERROR');
        return null;
    }

    $content_type = wp_remote_retrieve_header($response, 'content-type');
    $ext = '';
    switch ($content_type) {
        case 'image/jpeg':
            $ext = 'jpg';
            break;
        case 'image/png':
            $ext = 'png';
            break;
        case 'image/webp':
            $ext = 'webp';
            break;
        default:
            mh_log("Unsupported content type for image $url: $content_type", 'ERROR');
            return null;
    }

    $temp_file = wp_tempnam('mh_image_' . uniqid() . '.' . $ext);
    file_put_contents($temp_file, $body);
    return $temp_file;
}

/**
 * Converts a hex color code to RGB values.
 *
 * @param string $hex Hex color code (e.g., '#FFFFFF' or 'white').
 * @return array RGB values ['r' => int, 'g' => int, 'b' => int].
 */
function mh_hex_to_rgb(string $hex): array {
    $hex = trim($hex, '#');
    if (strtolower($hex) === 'white') {
        return ['r' => 255, 'g' => 255, 'b' => 255];
    } elseif (strtolower($hex) === 'black') {
        return ['r' => 0, 'g' => 0, 'b' => 0];
    }

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        mh_log("Invalid hex color code: $hex, defaulting to white", 'WARNING');
        return ['r' => 255, 'g' => 255, 'b' => 255];
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return ['r' => $r, 'g' => $g, 'b' => $b];
}

/**
 * Sanitizes a string for safe use in URLs or filenames.
 *
 * @param string $string Input string to sanitize.
 * @return string Sanitized string.
 */
function mh_sanitize_string(string $string): string {
    $string = wp_strip_all_tags($string);
    $string = preg_replace('/[^a-zA-Z0-9\s\-\_]/', '', $string);
    $string = preg_replace('/\s+/', '-', trim($string));
    return strtolower($string);
}

/**
 * Generates a unique ID based on a string input.
 *
 * @param string $input Input string to hash.
 * @return string Unique ID (MD5 hash).
 */
function mh_generate_unique_id(string $input): string {
    return md5($input . microtime());
}

/**
 * Checks if a URL is reachable.
 *
 * @param string $url URL to check.
 * @return bool True if reachable, false otherwise.
 */
function mh_is_url_reachable(string $url): bool {
    $response = wp_remote_head($url, [
        'timeout' => 5,
        'redirection' => 5,
        'headers' => [
            'User-Agent' => get_option('mh_custom_user_agent', 'Mozilla/5.0 (compatible; MadaraHarvest/1.0; +https://your-site.com)')
        ]
    ]);

    if (is_wp_error($response)) {
        mh_log("URL $url not reachable: " . $response->get_error_message(), 'WARNING');
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    return $status_code >= 200 && $status_code < 400;
}

/**
 * Formats a timestamp into a human-readable string.
 *
 * @param string $timestamp MySQL timestamp (e.g., '2025-02-24 12:00:00').
 * @return string Formatted date/time string.
 */
function mh_format_timestamp(string $timestamp): string {
    try {
        $date = new DateTime($timestamp);
        return $date->format('F j, Y, g:i a');
    } catch (Exception $e) {
        mh_log("Invalid timestamp format: $timestamp - " . $e->getMessage(), 'ERROR');
        return $timestamp;
    }
}

/**
 * Truncates a string to a specified length with an ellipsis.
 *
 * @param string $string Input string to truncate.
 * @param int $length Maximum length.
 * @return string Truncated string.
 */
function mh_truncate_string(string $string, int $length = 50): string {
    if (strlen($string) <= $length) {
        return $string;
    }
    return substr($string, 0, $length - 3) . '...';
}

/**
 * Retrieves the default site configuration as a JSON string (moved from admin.php for utility).
 *
 * Provides a comprehensive default setup for a sample site.
 *
 * @return string Default configuration in JSON format.
 */
function mh_get_default_config(): string {
    $default_config = [
        [
            'site_name' => 'Example Manga Site',
            'base_url' => 'https://example.com',
            'manga_list_ajax' => '/wp-admin/admin-ajax.php',
            'manga_list_ajax_params' => 'action=madara_load_more&page={page}',
            'manga_list_method' => 'POST',
            'manga_item_xpath' => '//div[contains(@class, "c-tabs-item__content")]',
            'chapter_list_xpath' => '//ul[contains(@class, "chapter-list")]//li',
            'chapter_images_xpath' => '//img[contains(@class, "chapter-image")]',
            'manga_description_xpath' => '//div[contains(@class, "manga-summary")]',
            'manga_genre_xpath' => '//div[contains(@class, "manga-genres")]//a',
            'manga_author_xpath' => '//div[contains(@class, "manga-authors")]//a',
            'manga_status_xpath' => '//div[contains(@class, "manga-status")]',
            'manga_alternative_titles_xpath' => '//div[contains(@class, "manga-alt-titles")]',
            'manga_tags_xpath' => '//div[contains(@class, "manga-tags")]//a',
            'manga_views_xpath' => '//span[contains(@class, "manga-views")]',
            'manga_rating_xpath' => '//span[contains(@class, "manga-rating")]',
            'manga_artist_xpath' => '//div[contains(@class, "manga-artists")]//a',
            'manga_release_xpath' => '//div[contains(@class, "manga-release")]',
            'manga_type_xpath' => '//div[contains(@class, "manga-type")]',
            'manga_publisher_xpath' => '//div[contains(@class, "manga-publisher")]',
            'manga_serialization_xpath' => '//div[contains(@class, "manga-serialization")]',
            'manga_volumes_xpath' => '//div[contains(@class, "manga-volumes")]'
        ]
    ];
    return json_encode($default_config, JSON_PRETTY_PRINT);
}