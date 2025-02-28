<?php
declare(strict_types=1);

/**
 * Queue management functions for MadaraHarvest plugin.
 *
 * Handles processing and scheduling of manga and chapter queues.
 */

/**
 * Initializes queue processing by scheduling cron events.
 */
function mh_init_queue_processing(): void {
    if (!wp_next_scheduled('mh_process_manga_queue_hook')) {
        $schedule = get_option('mh_queue_schedule', 'minute');
        $interval = $schedule === 'hourly' ? 'hourly' : ($schedule === 'daily' ? 'daily' : 'every_minute');
        wp_schedule_event(time(), $interval, 'mh_process_manga_queue_hook');
        mh_log("Scheduled manga queue processing with interval: $interval", 'INFO');
    }

    if (!wp_next_scheduled('mh_process_chapter_queue_hook')) {
        $schedule = get_option('mh_queue_schedule', 'minute');
        $interval = $schedule === 'hourly' ? 'hourly' : ($schedule === 'daily' ? 'daily' : 'every_minute');
        wp_schedule_event(time(), $interval, 'mh_process_chapter_queue_hook');
        mh_log("Scheduled chapter queue processing with interval: $interval", 'INFO');
    }

    // Define custom cron interval for every minute
    add_filter('cron_schedules', function (array $schedules): array {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute')
        ];
        return $schedules;
    });
}
add_action('init', 'mh_init_queue_processing');

/**
 * Processes the manga queue.
 */
function mh_process_manga_queue(): void {
    if (get_option('mh_process_manga_paused', 0)) {
        mh_log("Manga queue processing paused", 'INFO');
        return;
    }

    $queue = mh_get_manga_queue();
    if (empty($queue)) {
        mh_log("Manga queue is empty", 'INFO');
        return;
    }

    $threads = min((int) get_option('mh_parallel_threads', 1), count($queue));
    $processed = 0;
    $report = ['manga_added' => 0, 'chapters_queued' => 0, 'errors' => 0];

    for ($i = 0; $i < $threads && !empty($queue); $i++) {
        $task = array_shift($queue);
        $result = mh_process_manga_task($task);
        if ($result['error']) {
            $report['errors']++;
        } else {
            $report['manga_added'] += $result['manga_added'] ?? 0;
            $report['chapters_queued'] += $result['chapters_queued'] ?? 0;
        }
        $processed++;
    }

    update_option('mh_manga_queue', $queue);
    update_option('mh_last_run', current_time('mysql'));
    update_option('mh_last_report', [
        'manga_added' => $report['manga_added'],
        'chapters_queued' => $report['chapters_queued'],
        'errors' => $report['errors'],
        'timestamp' => current_time('mysql')
    ]);
    mh_log("Processed $processed manga tasks: {$report['manga_added']} added, {$report['chapters_queued']} chapters queued, {$report['errors']} errors", 'INFO');
}
add_action('mh_process_manga_queue_hook', 'mh_process_manga_queue');

/**
 * Processes a single manga task.
 *
 * Fetches manga data and queues chapters if threshold is met.
 *
 * @param array $task Manga task details.
 * @return array Result of processing ('error', 'manga_added', 'chapters_queued').
 */
function mh_process_manga_task(array $task): array {
    $result = ['error' => false, 'manga_added' => 0, 'chapters_queued' => 0];

    // Validate task
    if (!isset($task['manga_id']) || !isset($task['site_config']) || !isset($task['manga_link'])) {
        mh_log("Invalid manga task: Missing required fields", 'ERROR');
        return ['error' => true];
    }

    $manga = [
        'id' => $task['manga_id'],
        'title' => $task['manga_title'] ?? 'Untitled',
        'link' => $task['manga_link'],
        'cover' => $task['cover'] ?? '',
        'alternative' => $task['alternative'] ?? '',
        'genre' => $task['genre'] ?? '',
        'status' => $task['status'] ?? ''
    ];
    $site_config = $task['site_config'];

    // Fetch chapter list
    $chapters_html = mh_fetch_content($manga['link'], 'GET');
    if ($chapters_html === false) {
        $task['retry_count'] = ($task['retry_count'] ?? 0) + 1;
        $max_retries = (int) get_option('mh_max_retries', 3);
        if ($task['retry_count'] < $max_retries) {
            $task['status'] = 'retrying';
            mh_add_to_manga_queue($task);
            mh_log("Requeued manga '{$manga['title']}' for retry {$task['retry_count']}", 'INFO');
        } else {
            $task['status'] = 'error';
            $task['error_reason'] = "Failed to fetch chapters after $max_retries retries";
            mh_add_to_manga_queue($task);
            mh_log("Manga '{$manga['title']}' failed after max retries", 'ERROR');
        }
        return ['error' => true];
    }

    $chapters = mh_parse_chapter_list($chapters_html, $site_config);
    if (empty($chapters)) {
        $task['status'] = 'error';
        $task['error_reason'] = "No chapters parsed from '{$manga['link']}'";
        mh_add_to_manga_queue($task);
        mh_log("Manga '{$manga['title']}' failed: No chapters parsed", 'ERROR');
        return ['error' => true];
    }

    // Dry run mode
    if (get_option('mh_dry_run', 0)) {
        $result['chapters_queued'] = count($chapters);
        mh_log("Dry run: Simulated queuing {$result['chapters_queued']} chapters for '{$manga['title']}'", 'INFO');
        return $result;
    }

    // Check existing manga
    $existing = mh_get_manga_by_source($site_config['site_name'], $manga['id']);
    $manga_post_id = $existing ? $existing->ID : null;
    $chapter_threshold = (int) get_option('mh_chapter_threshold', 1);

    // Queue chapters
    $chapter_queue = mh_get_chapter_queue();
    $existing_chapters = $manga_post_id ? get_post_meta($manga_post_id, '_wp_manga_chapters', true) : [];
    $existing_ids = is_array($existing_chapters) ? array_keys($existing_chapters) : [];
    $queued_chapters = 0;

    foreach ($chapters as $chapter) {
        if (!in_array($chapter['id'], $existing_ids)) {
            $chapter_task = [
                'site_name' => $site_config['site_name'],
                'site_config' => $site_config,
                'manga_id' => $manga_post_id ?: $manga['id'],
                'manga_title' => $manga['title'],
                'manga_source_id' => $manga['id'],
                'chapter_title' => $chapter['title'],
                'chapter_link' => $chapter['link'],
                'chapter_source_id' => $chapter['id']
            ];
            mh_add_to_chapter_queue($chapter_task);
            $result['chapters_queued']++;
            $queued_chapters++;
        }
    }

    // Create manga post if threshold met
    if (!$manga_post_id && $queued_chapters >= $chapter_threshold) {
        $manga_post_id = mh_create_manga_post($manga, $site_config);
        if (is_wp_error($manga_post_id)) {
            $task['status'] = 'error';
            $task['error_reason'] = "Failed to create manga post: " . $manga_post_id->get_error_message();
            mh_add_to_manga_queue($task);
            mh_log("Manga '{$manga['title']}' failed: {$task['error_reason']}", 'ERROR');
            return ['error' => true];
        }
        $result['manga_added'] = 1;
        mh_log("Created manga post ID $manga_post_id for '{$manga['title']}'", 'INFO');

        // Update chapter tasks with new post ID
        $chapter_queue = mh_get_chapter_queue();
        foreach ($chapter_queue as &$chapter_task) {
            if ($chapter_task['manga_source_id'] === $manga['id'] && $chapter_task['site_name'] === $site_config['site_name']) {
                $chapter_task['manga_id'] = $manga_post_id;
            }
        }
        update_option('mh_chapter_queue', $chapter_queue);
    }

    if (!$manga_post_id) {
        mh_log("Threshold ($chapter_threshold) not met for '{$manga['title']}'; $queued_chapters chapters queued", 'INFO');
    }

    return $result;
}

/**
 * Processes the chapter queue.
 */
function mh_process_chapter_queue(): void {
    if (get_option('mh_process_chapter_paused', 0)) {
        mh_log("Chapter queue processing paused", 'INFO');
        return;
    }

    $queue = mh_get_chapter_queue();
    if (empty($queue)) {
        mh_log("Chapter queue is empty", 'INFO');
        return;
    }

    $threads = min((int) get_option('mh_parallel_threads', 1), count($queue));
    $processed = 0;

    for ($i = 0; $i < $threads && !empty($queue); $i++) {
        $task = array_shift($queue);
        $result = mh_process_chapter_task($task);
        if ($result['error']) {
            mh_log("Failed to process chapter '{$task['chapter_title']}' for manga '{$task['manga_title']}'", 'ERROR');
        }
        $processed++;
    }

    update_option('mh_chapter_queue', $queue);
    mh_log("Processed $processed chapter tasks", 'INFO');
}
add_action('mh_process_chapter_queue_hook', 'mh_process_chapter_queue');

/**
 * Processes a single chapter task.
 *
 * Fetches and adds chapter images to the manga post.
 *
 * @param array $task Chapter task details.
 * @return array Result of processing ('error').
 */
function mh_process_chapter_task(array $task): array {
    $result = ['error' => false];

    // Validate task
    if (!isset($task['manga_id']) || !isset($task['chapter_link']) || !isset($task['site_config'])) {
        mh_log("Invalid chapter task: Missing required fields", 'ERROR');
        return ['error' => true];
    }

    $manga_id = $task['manga_id'];
    $chapter_link = $task['chapter_link'];
    $site_config = $task['site_config'];

    // Fetch chapter content
    $chapter_html = mh_fetch_content($chapter_link, 'GET');
    if ($chapter_html === false) {
        mh_log("Failed to fetch chapter content for '{$task['chapter_title']}'", 'ERROR');
        return ['error' => true];
    }

    // Parse images
    $images = mh_parse_chapter_images($chapter_html, $site_config);
    if (empty($images)) {
        mh_log("No images parsed for chapter '{$task['chapter_title']}'", 'ERROR');
        return ['error' => true];
    }

    // Add chapter to manga post
    $chapter_data = [
        'chapter_name' => $task['chapter_title'],
        'chapter_slug' => sanitize_title($task['chapter_title']),
        'chapter_images' => $images,
        'chapter_source_id' => $task['chapter_source_id']
    ];

    $existing_chapters = get_post_meta($manga_id, '_wp_manga_chapters', true);
    if (!is_array($existing_chapters)) {
        $existing_chapters = [];
    }

    $existing_chapters[$task['chapter_source_id']] = $chapter_data;
    update_post_meta($manga_id, '_wp_manga_chapters', $existing_chapters);
    update_post_meta($manga_id, '_wp_manga_last_updated', current_time('mysql'));

    mh_log("Added chapter '{$task['chapter_title']}' with " . count($images) . " images to manga ID $manga_id", 'INFO');
    return $result;
}

/**
 * Creates a new manga post in WordPress.
 *
 * @param array $manga Manga details.
 * @param array $site_config Site configuration.
 * @return int|WP_Error Post ID on success, WP_Error on failure.
 */
function mh_create_manga_post(array $manga, array $site_config): int|WP_Error {
    $post_data = [
        'post_title' => $manga['title'],
        'post_status' => get_option('mh_post_status', 'publish'),
        'post_type' => 'wp-manga',
        'post_author' => get_current_user_id(),
        'comment_status' => get_option('mh_enable_comments', 0) ? 'open' : 'closed',
        'ping_status' => get_option('mh_enable_pingback', 0) ? 'open' : 'closed'
    ];

    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) {
        mh_log("Failed to create manga post for '{$manga['title']}': " . $post_id->get_error_message(), 'ERROR');
        return $post_id;
    }

    // Add metadata
    update_post_meta($post_id, '_wp_manga_source', 'MadaraHarvest');
    update_post_meta($post_id, '_wp_manga_source_id', $manga['id']);
    update_post_meta($post_id, '_wp_manga_link', $manga['link']);
    update_post_meta($post_id, '_wp_manga_site_name', $site_config['site_name']);
    update_post_meta($post_id, '_wp_manga_poster', $manga['cover']);
    update_post_meta($post_id, '_wp_manga_alternative', $manga['alternative']);
    update_post_meta($post_id, '_wp_manga_genre', $manga['genre']);
    update_post_meta($post_id, '_wp_manga_status', $manga['status']);
    update_post_meta($post_id, '_wp_manga_last_updated', current_time('mysql'));

    return $post_id;
}

/**
 * Retrieves a manga post by source ID and site name.
 *
 * @param string $site_name Site name.
 * @param string $source_id Source ID of the manga.
 * @return WP_Post|null Post object if found, null if not.
 */
function mh_get_manga_by_source(string $site_name, string $source_id): ?WP_Post {
    $query = new WP_Query([
        'post_type' => 'wp-manga',
        'post_status' => 'any',
        'posts_per_page' => 1,
        'meta_query' => [
            'relation' => 'AND',
            ['key' => '_wp_manga_source', 'value' => 'MadaraHarvest', 'compare' => '='],
            ['key' => '_wp_manga_source_id', 'value' => $source_id, 'compare' => '='],
            ['key' => '_wp_manga_site_name', 'value' => $site_name, 'compare' => '=']
        ]
    ]);

    if ($query->have_posts()) {
        $query->the_post();
        $post = get_post();
        wp_reset_postdata();
        return $post;
    }

    return null;
}

/**
 * Adds a task to the manga queue.
 *
 * @param array $task Manga task details.
 */
function mh_add_to_manga_queue(array $task): void {
    $queue = mh_get_manga_queue();
    $task['queued_timestamp'] = current_time('mysql');
    $task['status'] = $task['status'] ?? 'queued';
    $task['retry_count'] = $task['retry_count'] ?? 0;
    $queue[] = $task;
    update_option('mh_manga_queue', $queue);
    mh_log("Added manga task to queue: '{$task['manga_title']}' from '{$task['site_name']}'", 'INFO');
}

/**
 * Adds a task to the chapter queue.
 *
 * @param array $task Chapter task details.
 */
function mh_add_to_chapter_queue(array $task): void {
    $queue = mh_get_chapter_queue();
    $task['queued_timestamp'] = current_time('mysql');
    $task['status'] = $task['status'] ?? 'queued';
    $queue[] = $task;
    update_option('mh_chapter_queue', $queue);
    mh_log("Added chapter task to queue: '{$task['chapter_title']}' for manga '{$task['manga_title']}'", 'INFO');
}