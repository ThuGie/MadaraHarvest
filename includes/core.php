<?php
declare(strict_types=1);

/**
 * Core functions for MadaraHarvest plugin.
 *
 * Provides HTTP fetching, site health checks, content parsing, and utility functions.
 */

/**
 * Fetches content from a URL with caching, retries, and delay.
 *
 * Handles HTTP requests with configurable options and logs detailed outcomes.
 *
 * @param string $url URL to fetch content from.
 * @param string $method HTTP method ('GET' or 'POST').
 * @param mixed $body POST body data (array or string, default: null).
 * @param int $retry Current retry attempt (default: 0).
 * @return string|bool Content on success, false on failure after retries.
 */
function mh_fetch_content(string $url, string $method = 'GET', $body = null, int $retry = 0): string|bool {
    mh_log("Fetching content from URL: '$url' with method: $method", 'INFO');

    // Validate URL
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        mh_log("Invalid URL provided for fetch: '$url'", 'ERROR');
        return false;
    }

    // Cache handling
    $cache_duration = (int) get_option('mh_cache_duration', 300);
    $transient_key = 'mh_fetch_' . md5($url . (is_array($body) ? serialize($body) : ($body ?? '')));
    $force_fetch = (bool) (get_option('mh_force_fetch', 0) || ($_GET['force_fetch'] ?? '0') === '1');
    $cached = $force_fetch ? false : get_transient($transient_key);

    if ($cached !== false) {
        mh_log("Cache hit for URL: '$url' with key: $transient_key", 'INFO');
        return $cached;
    }

    // Prepare request arguments
    $args = [
        'timeout' => 15,
        'redirection' => 5,
        'headers' => [
            'User-Agent' => get_option('mh_custom_user_agent', 'Mozilla/5.0 (compatible; MadaraHarvest/1.0; +https://your-site.com)'),
            'Referer' => parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept-Encoding' => 'gzip, deflate'
        ],
        'sslverify' => true
    ];

    // Handle POST body if method is POST
    if (strtoupper($method) === 'POST' && !empty($body)) {
        $args['body'] = is_array($body) ? http_build_query($body) : $body;
        mh_log("POST body set: " . (is_array($body) ? print_r($body, true) : $body), 'INFO');
    }

    // Proxy handling
    $proxy_list = get_option('mh_proxy_list', '');
    if (!empty($proxy_list)) {
        $proxies = json_decode($proxy_list, true);
        if (!empty($proxies) && is_array($proxies)) {
            $proxy = $proxies[array_rand($proxies)];
            $proxy_url = $proxy['url'] . ':' . $proxy['port'];
            $args['proxy'] = $proxy_url;
            if (!empty($proxy['username']) && !empty($proxy['password'])) {
                $args['headers']['Proxy-Authorization'] = 'Basic ' . base64_encode($proxy['username'] . ':' . $proxy['password']);
            }
            mh_log("Using proxy: $proxy_url", 'INFO');
        } else {
            mh_log("Proxy list is empty or invalid after decoding", 'WARNING');
        }
    }

    // Apply request delay
    $delay = (int) get_option('mh_request_delay', 1);
    if ($delay > 0) {
        sleep($delay);
    }

    // Perform the HTTP request
    $response = strtoupper($method) === 'POST' ? wp_remote_post($url, $args) : wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        $error_msg = $response->get_error_message();
        $max_retries = (int) get_option('mh_max_retries', 3);
        mh_log("Failed to fetch URL: '$url' - $error_msg (Retry $retry of $max_retries)", 'ERROR');

        // Log error details
        $errors = get_option('mh_error_log', []);
        $errors[] = [
            'task' => ['url' => $url, 'type' => 'fetch'],
            'message' => $error_msg,
            'timestamp' => current_time('mysql')
        ];
        update_option('mh_error_log', $errors);

        // Retry with exponential backoff if within limits
        if ($retry < $max_retries) {
            $backoff_delay = 2 << $retry; // 2, 4, 8, etc.
            sleep($backoff_delay);
            return mh_fetch_content($url, $method, $body, $retry + 1);
        }

        // Send email notification if enabled
        if (get_option('mh_email_notifications', 0)) {
            $notify_email = get_option('mh_notify_email', get_option('admin_email'));
            wp_mail($notify_email, 'MadaraHarvest Fetch Error', "Fetch failed: $url\nError: $error_msg");
            mh_log("Sent error notification email for URL: '$url' to '$notify_email'", 'INFO');
        }

        // Update site status to error
        mh_update_site_status($url, 'error', $error_msg);
        return false;
    }

    // Retrieve response body
    $body_content = wp_remote_retrieve_body($response);
    if (empty($body_content)) {
        mh_log("Empty response body for URL: '$url'", 'WARNING');
    }

    // Cache the result
    set_transient($transient_key, $body_content, $cache_duration);
    mh_log("Fetched and cached content for URL: '$url' with key: $transient_key", 'INFO');

    // Update site status to success
    mh_update_site_status($url, 'success');
    return $body_content;
}

/**
 * Updates the site status based on fetch or health check results.
 *
 * @param string $url URL of the site being checked.
 * @param string $status Status to set ('success' or 'error').
 * @param string $reason Optional reason for the status (default: '').
 */
function mh_update_site_status(string $url, string $status, string $reason = ''): void {
    $site_status = get_option('mh_site_status', []);
    $site_name = mh_get_site_name($url);

    if ($site_name) {
        $site_status[$site_name] = [
            'status' => $status,
            'last_check' => current_time('mysql'),
            'reason' => $reason
        ];
        update_option('mh_site_status', $site_status);
        mh_log("Updated site status to '$status' for: '$site_name'" . ($reason ? " - Reason: $reason" : ''), 'INFO');
    }
}

/**
 * Retrieves the site name based on the URL.
 *
 * Matches the URL against configured site base URLs to identify the site.
 *
 * @param string $url URL to match against site configurations.
 * @return string|null Site name if matched, null if not found.
 */
function mh_get_site_name(string $url): ?string {
    $sites = json_decode(get_option('mh_sites_config'), true);
    if (empty($sites) || !is_array($sites)) {
        return null;
    }

    foreach ($sites as $site) {
        if (!isset($site['base_url']) || !isset($site['site_name'])) {
            continue;
        }
        if (strpos($url, $site['base_url']) === 0) {
            return $site['site_name'];
        }
    }
    return null;
}

/**
 * Checks the health of configured sites.
 *
 * Verifies that each site’s base URL or AJAX endpoint is reachable and contains valid data.
 */
function mh_check_site_health(): void {
    $sites = json_decode(get_option('mh_sites_config'), true);
    if (empty($sites) || !is_array($sites)) {
        mh_log("No sites configured for health check", 'WARNING');
        return;
    }

    $site_status = get_option('mh_site_status', []);
    foreach ($sites as $site) {
        // Validate required fields
        if (!isset($site['site_name']) || !isset($site['base_url']) || !isset($site['manga_list_method'])) {
            $site_name = $site['site_name'] ?? 'unknown';
            $site_status[$site_name] = [
                'status' => 'error',
                'last_check' => current_time('mysql'),
                'reason' => "Missing required configuration fields"
            ];
            mh_log("Health check failed for '$site_name': Missing required fields", 'ERROR');
            continue;
        }

        // Determine fetch URL and method
        $manga_list_method = strtoupper($site['manga_list_method']);
        $url = ($manga_list_method === 'AJAX' || $manga_list_method === 'POST')
            ? rtrim($site['base_url'], '/') . '/' . ltrim($site['manga_list_ajax'] ?? '', '/')
            : $site['base_url'];

        // Prepare fetch parameters
        $method = ($manga_list_method === 'POST') ? 'POST' : 'GET';
        $body = ($manga_list_method === 'POST') ? mh_build_params($site['manga_list_ajax_params'] ?? '', 1) : null;

        // Fetch content
        $html = mh_fetch_content($url, $method, $body);
        if ($html === false) {
            $site_status[$site['site_name']] = [
                'status' => 'error',
                'last_check' => current_time('mysql'),
                'reason' => "Failed to fetch content from $url"
            ];
            mh_log("Health check failed for '{$site['site_name']}': Failed to fetch content", 'ERROR');
            continue;
        }

        // Validate content with XPath
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query($site['manga_item_xpath'] ?? '//div');

        if ($nodes === false || $nodes->length === 0) {
            $site_status[$site['site_name']] = [
                'status' => 'error',
                'last_check' => current_time('mysql'),
                'reason' => "Invalid manga_item_xpath '" . ($site['manga_item_xpath'] ?? 'not set') . "'"
            ];
            mh_log("Health check failed for '{$site['site_name']}': Invalid manga_item_xpath '" . ($site['manga_item_xpath'] ?? 'not set') . "'", 'ERROR');
        } else {
            $site_status[$site['site_name']] = [
                'status' => 'success',
                'last_check' => current_time('mysql'),
                'reason' => ''
            ];
            mh_log("Health check passed for '{$site['site_name']}'", 'INFO');
        }
    }

    update_option('mh_site_status', $site_status);
}

/**
 * Builds parameters for POST requests from a raw string.
 *
 * Parses a parameter string into an array or returns the string as-is if not parseable.
 *
 * @param string $params Raw parameter string (e.g., "key=value&page={page}").
 * @param int $page Page number for pagination replacement.
 * @return array|string Parsed parameters or original string if invalid.
 */
function mh_build_params(string $params, int $page): array|string {
    if (empty($params)) {
        return [];
    }

    // Replace {page} placeholder with actual page number
    $params = str_replace('{page}', (string) $page, $params);

    // Parse into array if it resembles a query string
    parse_str($params, $parsed_params);
    if (!empty($parsed_params) && is_array($parsed_params)) {
        return $parsed_params;
    }

    // Return original string if parsing fails
    return $params;
}

/**
 * Parses a manga list from HTML content using site configuration.
 *
 * @param string $html HTML content to parse.
 * @param array $site_config Site configuration with XPath settings.
 * @return array Parsed manga items.
 */
function mh_parse_manga_list(string $html, array $site_config): array {
    if (empty($html) || !isset($site_config['manga_item_xpath'])) {
        mh_log("Invalid HTML or missing manga_item_xpath for parsing manga list", 'ERROR');
        return [];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $manga_items = $xpath->query($site_config['manga_item_xpath']);
    if ($manga_items === false || $manga_items->length === 0) {
        mh_log("No manga items found with XPath: '{$site_config['manga_item_xpath']}'", 'WARNING');
        return [];
    }

    $parsed_items = [];
    foreach ($manga_items as $item) {
        $manga = [
            'title' => '',
            'link' => '',
            'id' => '',
            'cover' => ''
        ];

        // Extract title and link
        $title_nodes = $xpath->query('.//a[contains(@class, "manga-title") or contains(@class, "post-title")]', $item);
        if ($title_nodes->length > 0) {
            $manga['title'] = trim($title_nodes->item(0)->textContent);
            $manga['link'] = $title_nodes->item(0)->getAttribute('href');
            $manga['id'] = md5($manga['link']); // Simple ID generation
        }

        // Extract cover image
        $cover_nodes = $xpath->query('.//img[contains(@class, "manga-cover") or contains(@class, "thumbnail")]', $item);
        if ($cover_nodes->length > 0) {
            $manga['cover'] = $cover_nodes->item(0)->getAttribute('src');
        }

        if (!empty($manga['title']) && !empty($manga['link'])) {
            $parsed_items[] = $manga;
        }
    }

    mh_log("Parsed " . count($parsed_items) . " manga items from HTML", 'INFO');
    return $parsed_items;
}

/**
 * Parses a chapter list from HTML content using site configuration.
 *
 * @param string $html HTML content to parse.
 * @param array $site_config Site configuration with XPath settings.
 * @return array Parsed chapter items.
 */
function mh_parse_chapter_list(string $html, array $site_config): array {
    if (empty($html) || !isset($site_config['chapter_list_xpath'])) {
        mh_log("Invalid HTML or missing chapter_list_xpath for parsing chapter list", 'ERROR');
        return [];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $chapter_items = $xpath->query($site_config['chapter_list_xpath']);
    if ($chapter_items === false || $chapter_items->length === 0) {
        mh_log("No chapters found with XPath: '{$site_config['chapter_list_xpath']}'", 'WARNING');
        return [];
    }

    $parsed_chapters = [];
    foreach ($chapter_items as $item) {
        $chapter = [
            'title' => '',
            'link' => '',
            'id' => ''
        ];

        // Extract chapter title and link
        $link_nodes = $xpath->query('.//a', $item);
        if ($link_nodes->length > 0) {
            $chapter['title'] = trim($link_nodes->item(0)->textContent);
            $chapter['link'] = $link_nodes->item(0)->getAttribute('href');
            $chapter['id'] = md5($chapter['link']); // Simple ID generation
        }

        if (!empty($chapter['title']) && !empty($chapter['link'])) {
            $parsed_chapters[] = $chapter;
        }
    }

    mh_log("Parsed " . count($parsed_chapters) . " chapters from HTML", 'INFO');
    return $parsed_chapters;
}

/**
 * Parses chapter images from HTML content using site configuration.
 *
 * @param string $html HTML content to parse.
 * @param array $site_config Site configuration with XPath settings.
 * @return array Parsed image URLs.
 */
function mh_parse_chapter_images(string $html, array $site_config): array {
    if (empty($html) || !isset($site_config['chapter_images_xpath'])) {
        mh_log("Invalid HTML or missing chapter_images_xpath for parsing images", 'ERROR');
        return [];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $image_nodes = $xpath->query($site_config['chapter_images_xpath']);
    if ($image_nodes === false || $image_nodes->length === 0) {
        mh_log("No images found with XPath: '{$site_config['chapter_images_xpath']}'", 'WARNING');
        return [];
    }

    $images = [];
    foreach ($image_nodes as $node) {
        $src = $node->getAttribute('src');
        if (!empty($src) && filter_var($src, FILTER_VALIDATE_URL)) {
            $images[] = $src;
        }
    }

    mh_log("Parsed " . count($images) . " images from chapter HTML", 'INFO');
    return $images;
}

/**
 * Logs messages to the WordPress option-based log system.
 *
 * Stores logs with retention based on settings.
 *
 * @param string $message Message to log.
 * @param string $level Log level ('INFO', 'WARNING', 'ERROR').
 */
function mh_log(string $message, string $level): void {
    if ($level === 'INFO' && !get_option('mh_debug_mode', 0)) {
        return;
    }

    $timestamp = current_time('mysql');
    $log_entry = "[$timestamp] $level: $message";
    $logs = get_option('mh_log', '');
    $logs .= $log_entry . PHP_EOL;

    // Trim logs based on retention period
    $retention_days = (int) get_option('mh_log_retention_days', 7);
    $logs_array = explode(PHP_EOL, trim($logs));
    $cutoff_time = strtotime("-$retention_days days");
    $filtered_logs = array_filter($logs_array, function ($line) use ($cutoff_time) {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            $log_time = strtotime($matches[1]);
            return $log_time >= $cutoff_time;
        }
        return true;
    });

    update_option('mh_log', implode(PHP_EOL, $filtered_logs));
}

/**
 * Retrieves the manga queue from WordPress options.
 *
 * @return array Manga queue items.
 */
function mh_get_manga_queue(): array {
    $queue = get_option('mh_manga_queue', []);
    return is_array($queue) ? $queue : [];
}

/**
 * Retrieves the chapter queue from WordPress options.
 *
 * @return array Chapter queue items.
 */
function mh_get_chapter_queue(): array {
    $queue = get_option('mh_chapter_queue', []);
    return is_array($queue) ? $queue : [];
}