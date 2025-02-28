<?php
declare(strict_types=1);

/**
 * Admin functions for MadaraHarvest plugin.
 *
 * Manages dashboard rendering, settings, queue status, pending manga, live manga, logs, and setup wizard.
 */

/**
 * Registers admin menu pages for MadaraHarvest.
 */
function mh_add_admin_menu(): void {
    add_menu_page(
        __('MadaraHarvest Dashboard', 'madara-harvest'),
        __('MadaraHarvest', 'madara-harvest'),
        'manage_options',
        'mh_dashboard',
        'mh_render_dashboard_page',
        'dashicons-book',
        6
    );
    add_submenu_page(
        'mh_dashboard',
        __('Queue Status', 'madara-harvest'),
        __('Queue Status', 'madara-harvest'),
        'manage_options',
        'mh_queue_status',
        'mh_render_queue_status_page'
    );
    add_submenu_page(
        'mh_dashboard',
        __('Pending Manga', 'madara-harvest'),
        __('Pending Manga', 'madara-harvest'),
        'manage_options',
        'mh_pending_manga',
        'mh_render_pending_manga_page'
    );
    add_submenu_page(
        'mh_dashboard',
        __('Live Manga', 'madara-harvest'),
        __('Live Manga', 'madara-harvest'),
        'manage_options',
        'mh_live_manga',
        'mh_render_live_manga_page'
    );
    add_submenu_page(
        'mh_dashboard',
        __('Logs', 'madara-harvest'),
        __('Logs', 'madara-harvest'),
        'manage_options',
        'mh_logs',
        'mh_render_logs_page'
    );
}
add_action('admin_menu', 'mh_add_admin_menu');

/**
 * Renders the main dashboard with tabs for overview and settings.
 */
function mh_render_dashboard_page(): void {
    $tab = sanitize_text_field($_GET['tab'] ?? 'overview');

    // Handle setup wizard
    if (isset($_GET['setup']) && $_GET['setup'] === 'wizard') {
        if (isset($_POST['mh_complete_wizard']) && check_admin_referer('mh_complete_wizard_verify')) {
            update_option('mh_setup_complete', true);
            update_option('mh_sites_config', sanitize_textarea_field($_POST['mh_sites_config'] ?? ''));
            update_option('mh_debug_mode', isset($_POST['mh_debug_mode']) ? 1 : 0);
            mh_log("Setup wizard completed", 'INFO');
            wp_redirect(admin_url('admin.php?page=mh_dashboard'));
            exit;
        }
        mh_render_setup_wizard();
        return;
    }

    // Handle settings save
    if (isset($_POST['mh_save_settings']) && check_admin_referer('mh_save_settings_verify')) {
        mh_save_settings();
    } elseif (isset($_POST['mh_reset_config']) && check_admin_referer('mh_reset_config_verify')) {
        update_option('mh_sites_config', mh_get_default_config());
        mh_log("Sites configuration reset to default", 'INFO');
        echo '<div class="notice notice-success"><p>' . __('Configuration reset to default.', 'madara-harvest') . '</p></div>';
    }

    // Handle quick actions
    if (isset($_GET['mms_action']) && check_admin_referer('mh_' . $_GET['mms_action'] . '_settings', '_mms_nonce')) {
        if ($_GET['mms_action'] === 'manual_run') {
            mh_process_manga_queue();
            mh_process_chapter_queue();
            echo '<div class="notice notice-success"><p>' . __('Manual run completed.', 'madara-harvest') . '</p></div>';
        } elseif ($_GET['mms_action'] === 'clear_cache') {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mh_fetch_%'");
            mh_log("Cache cleared", 'INFO');
            echo '<div class="notice notice-success"><p>' . __('Cache cleared.', 'madara-harvest') . '</p></div>';
        }
    }

    mh_render_dashboard_ui($tab);
}

/**
 * Saves dashboard settings to WordPress options.
 */
function mh_save_settings(): void {
    // General settings
    update_option('mh_debug_mode', isset($_POST['mh_debug_mode']) ? 1 : 0);
    update_option('mh_custom_user_agent', sanitize_text_field($_POST['mh_custom_user_agent'] ?? 'Mozilla/5.0 (compatible; MadaraHarvest/1.0; +https://your-site.com)'));
    $proxies = mh_sanitize_proxies($_POST['proxies'] ?? []);
    update_option('mh_proxy_list', json_encode($proxies));
    update_option('mh_cache_duration', max(0, intval($_POST['mh_cache_duration'] ?? 300)));
    update_option('mh_email_notifications', isset($_POST['mh_email_notifications']) ? 1 : 0);
    update_option('mh_notify_email', sanitize_email($_POST['mh_notify_email'] ?? get_option('admin_email')));

    // Scraping settings
    update_option('mh_log_retention_days', max(1, intval($_POST['mh_log_retention_days'] ?? 7)));
    update_option('mh_max_retries', max(0, min(10, intval($_POST['mh_max_retries'] ?? 3))));
    update_option('mh_request_delay', max(0, intval($_POST['mh_request_delay'] ?? 1)));
    update_option('mh_parallel_threads', max(1, min(10, intval($_POST['mh_parallel_threads'] ?? 1))));
    update_option('mh_dry_run', isset($_POST['mh_dry_run']) ? 1 : 0);
    update_option('mh_post_status', in_array($_POST['mh_post_status'] ?? 'publish', ['publish', 'draft']) ? $_POST['mh_post_status'] : 'publish');
    update_option('mh_force_fetch', isset($_POST['mh_force_fetch']) ? 1 : 0);
    update_option('mh_enable_comments', isset($_POST['mh_enable_comments']) ? 1 : 0);
    update_option('mh_enable_pingback', isset($_POST['mh_enable_pingback']) ? 1 : 0);
    update_option('mh_chapter_threshold', max(1, intval($_POST['mh_chapter_threshold'] ?? 1)));
    update_option('mh_queue_schedule', sanitize_text_field($_POST['mh_queue_schedule'] ?? 'minute'));

    // Image settings
    update_option('mh_merge_images', isset($_POST['mh_merge_images']) ? 1 : 0);
    update_option('mh_image_merge_direction', sanitize_text_field($_POST['mh_image_merge_direction'] ?? 'vertical'));
    update_option('mh_image_merge_quality', max(0, min(100, intval($_POST['mh_image_merge_quality'] ?? 75))));
    update_option('mh_image_merge_format', in_array($_POST['mh_image_merge_format'] ?? 'avif', ['avif', 'webp', 'jpeg']) ? $_POST['mh_image_merge_format'] : 'avif');
    update_option('mh_image_merge_bg_color', sanitize_text_field($_POST['mh_image_merge_bg_color'] ?? 'white'));

    // Sites configuration
    $updated_sites = mh_sanitize_sites($_POST['sites'] ?? []);
    $new_config = json_encode(array_values($updated_sites), JSON_PRETTY_PRINT);
    if (json_decode($new_config) === null) {
        mh_log("Invalid JSON for sites config: " . json_last_error_msg(), 'ERROR');
        echo '<div class="notice notice-error"><p>' . __('Failed to save sites: Invalid JSON.', 'madara-harvest') . '</p></div>';
    } else {
        update_option('mh_sites_config', $new_config);
        echo '<div class="notice notice-success"><p>' . __('Settings and sites saved successfully.', 'madara-harvest') . '</p></div>';
        mh_log("Settings and sites saved", 'INFO');
    }

    // Reschedule cron if queue schedule changed
    wp_clear_scheduled_hook('mh_process_manga_queue_hook');
    wp_clear_scheduled_hook('mh_process_chapter_queue_hook');
    mh_init_queue_processing();
}

/**
 * Sanitizes proxy configurations from POST data.
 *
 * @param array $proxies Raw proxy data.
 * @return array Sanitized proxy configurations.
 */
function mh_sanitize_proxies(array $proxies): array {
    $sanitized = [];
    foreach ($proxies as $index => $proxy_data) {
        if (!is_array($proxy_data) || (isset($proxy_data['remove']) && $proxy_data['remove'] === '1')) {
            continue;
        }
        $sanitized[$index] = [
            'url' => esc_url_raw($proxy_data['url'] ?? ''),
            'port' => max(1, min(65535, intval($proxy_data['port'] ?? 0))),
            'username' => sanitize_text_field($proxy_data['username'] ?? ''),
            'password' => sanitize_text_field($proxy_data['password'] ?? '')
        ];
    }
    return array_values($sanitized);
}

/**
 * Sanitizes site configurations from POST data.
 *
 * @param array $sites Raw site data.
 * @return array Sanitized site configurations.
 */
function mh_sanitize_sites(array $sites): array {
    $sanitized = [];
    foreach ($sites as $index => $site_data) {
        if (!is_array($site_data) || (isset($site_data['remove']) && $site_data['remove'] === '1')) {
            if (isset($site_data['site_name'])) {
                mh_log("Site removed: '{$site_data['site_name']}'", 'INFO');
            }
            continue;
        }
        if (empty($site_data['site_name']) || !is_string($site_data['site_name']) ||
            empty($site_data['base_url']) || !filter_var($site_data['base_url'], FILTER_VALIDATE_URL) ||
            empty($site_data['manga_list_method']) || !in_array($site_data['manga_list_method'], ['GET', 'POST', 'AJAX'])) {
            mh_log("Invalid site config: " . json_encode($site_data), 'ERROR');
            continue;
        }
        $sanitized[$index] = [
            'site_name' => sanitize_text_field(stripslashes($site_data['site_name'])),
            'base_url' => esc_url_raw(stripslashes($site_data['base_url'])),
            'manga_list_ajax' => sanitize_text_field(stripslashes($site_data['manga_list_ajax'] ?? '')),
            'manga_list_ajax_params' => sanitize_text_field(stripslashes($site_data['manga_list_ajax_params'] ?? '')),
            'manga_list_method' => sanitize_text_field(stripslashes($site_data['manga_list_method'])),
            'manga_item_xpath' => sanitize_text_field(stripslashes($site_data['manga_item_xpath'] ?? '')),
            'chapter_list_xpath' => sanitize_text_field(stripslashes($site_data['chapter_list_xpath'] ?? '')),
            'chapter_images_xpath' => sanitize_text_field(stripslashes($site_data['chapter_images_xpath'] ?? '')),
            'manga_description_xpath' => sanitize_text_field(stripslashes($site_data['manga_description_xpath'] ?? '')),
            'manga_genre_xpath' => sanitize_text_field(stripslashes($site_data['manga_genre_xpath'] ?? '')),
            'manga_author_xpath' => sanitize_text_field(stripslashes($site_data['manga_author_xpath'] ?? '')),
            'manga_status_xpath' => sanitize_text_field(stripslashes($site_data['manga_status_xpath'] ?? '')),
            'manga_alternative_titles_xpath' => sanitize_text_field(stripslashes($site_data['manga_alternative_titles_xpath'] ?? '')),
            'manga_tags_xpath' => sanitize_text_field(stripslashes($site_data['manga_tags_xpath'] ?? '')),
            'manga_views_xpath' => sanitize_text_field(stripslashes($site_data['manga_views_xpath'] ?? '')),
            'manga_rating_xpath' => sanitize_text_field(stripslashes($site_data['manga_rating_xpath'] ?? '')),
            'manga_artist_xpath' => sanitize_text_field(stripslashes($site_data['manga_artist_xpath'] ?? '')),
            'manga_release_xpath' => sanitize_text_field(stripslashes($site_data['manga_release_xpath'] ?? '')),
            'manga_type_xpath' => sanitize_text_field(stripslashes($site_data['manga_type_xpath'] ?? '')),
            'manga_publisher_xpath' => sanitize_text_field(stripslashes($site_data['manga_publisher_xpath'] ?? '')),
            'manga_serialization_xpath' => sanitize_text_field(stripslashes($site_data['manga_serialization_xpath'] ?? '')),
            'manga_volumes_xpath' => sanitize_text_field(stripslashes($site_data['manga_volumes_xpath'] ?? ''))
        ];
    }
    return array_values($sanitized);
}

/**
 * Renders the dashboard UI based on the active tab.
 *
 * @param string $tab Active tab ('overview' or 'settings').
 */
function mh_render_dashboard_ui(string $tab): void {
    $is_first_run = !get_option('mh_setup_complete', false);
    if ($is_first_run) {
        echo '<div class="notice notice-info"><p>' . __('Welcome to MadaraHarvest! ', 'madara-harvest') . '<a href="' . esc_url(admin_url('admin.php?page=mh_dashboard&setup=wizard')) . '" id="start-wizard">' . __('Start Setup Wizard', 'madara-harvest') . '</a></p></div>';
    }

    // Dependency checks
    $madara_core_active = is_plugin_active('madara-core/wp-manga.php');
    $current_theme = wp_get_theme();
    $madara_theme_active = in_array($current_theme->get('Name'), ['Madara', 'Madara Child']) || $current_theme->get('Template') === 'madara';
    if (!$madara_core_active || !$madara_theme_active) {
        echo '<div class="notice notice-error"><p>';
        if (!$madara_core_active) echo __('Madara Core plugin is not active. Please activate it.', 'madara-harvest') . '<br>';
        if (!$madara_theme_active) echo __('A Madara-compatible theme is not active. Please activate the Madara theme.', 'madara-harvest');
        echo '</p></div>';
    }

    ?>
    <div class="wrap mh-dashboard">
        <h1><?php _e('MadaraHarvest Dashboard', 'madara-harvest'); ?></h1>
        <div class="mh-tab-bar">
            <a href="<?php echo esc_url(admin_url('admin.php?page=mh_dashboard&tab=overview')); ?>" class="mh-tab <?php echo esc_attr($tab === 'overview' ? 'active' : ''); ?>"><?php _e('Overview', 'madara-harvest'); ?></a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mh_dashboard&tab=settings')); ?>" class="mh-tab <?php echo esc_attr($tab === 'settings' ? 'active' : ''); ?>"><?php _e('Settings', 'madara-harvest'); ?></a>
        </div>

        <?php if ($tab === 'overview'): ?>
        <div class="mh-grid">
            <div class="mh-panel">
                <h2><?php _e('Scraper Status', 'madara-harvest'); ?></h2>
                <p><strong><?php _e('Manga Queue:', 'madara-harvest'); ?></strong> <?php echo esc_html(count(mh_get_manga_queue())); ?> (<?php _e('Paused:', 'madara-harvest'); ?> <?php echo esc_html(get_option('mh_process_manga_paused', 0) ? 'Yes' : 'No'); ?>)</p>
                <p><strong><?php _e('Chapter Queue:', 'madara-harvest'); ?></strong> <?php echo esc_html(count(mh_get_chapter_queue())); ?> (<?php _e('Paused:', 'madara-harvest'); ?> <?php echo esc_html(get_option('mh_process_chapter_paused', 0) ? 'Yes' : 'No'); ?>)</p>
                <?php
                $manga_count_query = new WP_Query([
                    'post_type' => 'wp-manga',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'meta_query' => [
                        'relation' => 'AND',
                        ['key' => 'madara_manga_source', 'value' => 'MadaraHarvest', 'compare' => '='],
                        ['key' => 'madara_manga_source', 'compare' => 'EXISTS']
                    ]
                ]);
                $manga_count = $manga_count_query->found_posts;
                wp_reset_postdata();

                $cron_enabled = !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON;
                $manga_cron_active = wp_next_scheduled('mh_process_manga_queue_hook');
                $chapter_cron_active = wp_next_scheduled('mh_process_chapter_queue_hook');
                ?>
                <p><strong><?php _e('Live Manga:', 'madara-harvest'); ?></strong> <?php echo esc_html($manga_count); ?></p>
                <p><strong><?php _e('Pending Manga:', 'madara-harvest'); ?></strong> <?php echo esc_html(count(mh_get_manga_queue())); ?></p>
                <p><strong><?php _e('Errors:', 'madara-harvest'); ?></strong> <?php echo esc_html(count(get_option('mh_error_log', []))); ?> (<a href="<?php echo esc_url(admin_url('admin.php?page=mh_logs')); ?>"><?php _e('View', 'madara-harvest'); ?></a>)</p>
                <p><strong><?php _e('Cron Status:', 'madara-harvest'); ?></strong>
                    <?php
                    echo esc_html($cron_enabled ? __('Enabled', 'madara-harvest') : __('Disabled (WP_CRON off)', 'madara-harvest'));
                    echo '<br> - ' . __('Manga Queue:', 'madara-harvest') . ' ' . esc_html($manga_cron_active ? __('Scheduled', 'madara-harvest') : __('Not Scheduled', 'madara-harvest'));
                    echo '<br> - ' . __('Chapter Queue:', 'madara-harvest') . ' ' . esc_html($chapter_cron_active ? __('Scheduled', 'madara-harvest') : __('Not Scheduled', 'madara-harvest'));
                    ?>
                </p>
            </div>
            <div class="mh-panel">
                <h2><?php _e('Quick Actions', 'madara-harvest'); ?></h2>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['mms_action' => 'manual_run', '_mms_nonce' => wp_create_nonce('mh_manual_run_settings')])); ?>"><?php _e('Run Now', 'madara-harvest'); ?></a>
                    <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(['mms_action' => 'clear_cache', '_mms_nonce' => wp_create_nonce('mh_clear_cache_settings')])); ?>" onclick="return confirm('<?php _e('Clear cache?', 'madara-harvest'); ?>');"><?php _e('Clear Cache', 'madara-harvest'); ?></a>
                </p>
                <p><strong><?php _e('Last Run:', 'madara-harvest'); ?></strong> <?php echo esc_html(get_option('mh_last_run', 'Never')); ?></p>
            </div>
            <div class="mh-panel">
                <h2><?php _e('Recent Report', 'madara-harvest'); ?></h2>
                <?php $report = get_option('mh_last_report', ['manga_added' => 0, 'chapters_queued' => 0, 'errors' => 0, 'timestamp' => 'N/A']); ?>
                <p><strong><?php _e('Manga Added:', 'madara-harvest'); ?></strong> <?php echo esc_html($report['manga_added']); ?></p>
                <p><strong><?php _e('Chapters Queued:', 'madara-harvest'); ?></strong> <?php echo esc_html($report['chapters_queued']); ?></p>
                <p><strong><?php _e('Errors:', 'madara-harvest'); ?></strong> <?php echo esc_html($report['errors']); ?></p>
                <p><strong><?php _e('Timestamp:', 'madara-harvest'); ?></strong> <?php echo esc_html($report['timestamp']); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tab === 'settings'): ?>
        <div class="mh-settings-tabs">
            <form method="post" action="">
                <?php wp_nonce_field('mh_save_settings_verify'); ?>
                <div class="mh-sub-tab-bar">
                    <a href="#general" class="mh-sub-tab <?php echo esc_attr($tab === 'settings' ? 'active' : ''); ?>"><?php _e('General', 'madara-harvest'); ?></a>
                    <a href="#scraping" class="mh-sub-tab"><?php _e('Scraping', 'madara-harvest'); ?></a>
                    <a href="#images" class="mh-sub-tab"><?php _e('Images', 'madara-harvest'); ?></a>
                    <a href="#servers" class="mh-sub-tab"><?php _e('Servers', 'madara-harvest'); ?></a>
                    <a href="#sites" class="mh-sub-tab"><?php _e('Sites & XPaths', 'madara-harvest'); ?></a>
                </div>
    <?php
	endif;
}
?>
                <div class="mh-settings-tab active" data-tab="general">
                    <h2><?php _e('General Settings', 'madara-harvest'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="mh_debug_mode"><?php _e('Debug Mode', 'madara-harvest'); ?> <span class="mh-tooltip" title="<?php _e('Enable detailed logging for troubleshooting', 'madara-harvest'); ?>">?</span></label></th>
                            <td><input type="checkbox" id="mh_debug_mode" name="mh_debug_mode" <?php checked(get_option('mh_debug_mode', 0), 1); ?> value="1" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_custom_user_agent"><?php _e('User Agent', 'madara-harvest'); ?></label></th>
                            <td><input type="text" id="mh_custom_user_agent" name="mh_custom_user_agent" value="<?php echo esc_attr(get_option('mh_custom_user_agent', 'Mozilla/5.0 (compatible; MadaraHarvest/1.0; +https://your-site.com)')); ?>" style="width: 100%;" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_cache_duration"><?php _e('Cache Duration (seconds)', 'madara-harvest'); ?></label></th>
                            <td><input type="number" id="mh_cache_duration" name="mh_cache_duration" value="<?php echo esc_attr(get_option('mh_cache_duration', 300)); ?>" min="0" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_email_notifications"><?php _e('Email Notifications', 'madara-harvest'); ?></label></th>
                            <td><input type="checkbox" id="mh_email_notifications" name="mh_email_notifications" <?php checked(get_option('mh_email_notifications', 0), 1); ?> value="1" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_notify_email"><?php _e('Notify Email', 'madara-harvest'); ?></label></th>
                            <td><input type="email" id="mh_notify_email" name="mh_notify_email" value="<?php echo esc_attr(get_option('mh_notify_email', get_option('admin_email'))); ?>" style="width: 100%;" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_log_retention_days"><?php _e('Log Retention (days)', 'madara-harvest'); ?></label></th>
                            <td><input type="number" id="mh_log_retention_days" name="mh_log_retention_days" value="<?php echo esc_attr(get_option('mh_log_retention_days', 7)); ?>" min="1" /></td>
                        </tr>
                    </table>
                </div>

                <div class="mh-settings-tab" data-tab="scraping">
                    <h2><?php _e('Scraping Settings', 'madara-harvest'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="mh_max_retries"><?php _e('Max Retries', 'madara-harvest'); ?></label></th>
                            <td><input type="number" id="mh_max_retries" name="mh_max_retries" value="<?php echo esc_attr(get_option('mh_max_retries', 3)); ?>" min="0" max="10" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_request_delay"><?php _e('Request Delay (seconds)', 'madara-harvest'); ?></label></th>
                            <td><input type="number" id="mh_request_delay" name="mh_request_delay" value="<?php echo esc_attr(get_option('mh_request_delay', 1)); ?>" min="0" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_parallel_threads"><?php _e('Parallel Threads', 'madara-harvest'); ?></label></th>
                            <td><input type="number" id="mh_parallel_threads" name="mh_parallel_threads" value="<?php echo esc_attr(get_option('mh_parallel_threads', 1)); ?>" min="1" max="10" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_dry_run"><?php _e('Dry Run', 'madara-harvest'); ?></label></th>
                            <td><input type="checkbox" id="mh_dry_run" name="mh_dry_run" <?php checked(get_option('mh_dry_run', 0), 1); ?> value="1" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_post_status"><?php _e('Post Status', 'madara-harvest'); ?></label></th>
                            <td>
                                <select id="mh_post_status" name="mh_post_status">
                                    <option value="publish" <?php selected(get_option('mh_post_status', 'publish'), 'publish'); ?>><?php _e('Publish', 'madara-harvest'); ?></option>
                                    <option value="draft" <?php selected(get_option('mh_post_status', 'draft'), 'draft'); ?>><?php _e('Draft', 'madara-harvest'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mh_force_fetch"><?php _e('Force Fetch', 'madara-harvest'); ?></label></th>
                            <td><input type="checkbox" id="mh_force_fetch" name="mh_force_fetch" <?php checked(get_option('mh_force_fetch', 0), 1); ?> value="1" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_enable_comments"><?php _e('Enable Comments', 'madara-harvest'); ?></label></th>
                            <td><input type="checkbox" id="mh_enable_comments" name="mh_enable_comments" <?php checked(get_option('mh_enable_comments', 0), 1); ?> value="1" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_enable_pingback"><?php _e('Enable Pingbacks', 'madara-harvest'); ?></label></th>
                            <td><input type="checkbox" id="mh_enable_pingback" name="mh_enable_pingback" <?php checked(get_option('mh_enable_pingback', 0), 1); ?> value="1" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_chapter_threshold"><?php _e('Chapter Threshold', 'madara-harvest'); ?> <span class="mh-tooltip" title="<?php _e('Minimum chapters before adding to Madara', 'madara-harvest'); ?>">?</span></label></th>
                            <td><input type="number" id="mh_chapter_threshold" name="mh_chapter_threshold" value="<?php echo esc_attr(get_option('mh_chapter_threshold', 1)); ?>" min="1" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_queue_schedule"><?php _e('Queue Schedule', 'madara-harvest'); ?></label></th>
                            <td>
                                <select id="mh_queue_schedule" name="mh_queue_schedule">
                                    <option value="minute" <?php selected(get_option('mh_queue_schedule', 'minute'), 'minute'); ?>><?php _e('Every Minute', 'madara-harvest'); ?></option>
                                    <option value="hourly" <?php selected(get_option('mh_queue_schedule', 'hourly'), 'hourly'); ?>><?php _e('Hourly', 'madara-harvest'); ?></option>
                                    <option value="daily" <?php selected(get_option('mh_queue_schedule', 'daily'), 'daily'); ?>><?php _e('Daily (12 AM)', 'madara-harvest'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="mh-settings-tab" data-tab="images">
                    <h2><?php _e('Image Settings', 'madara-harvest'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="mh_merge_images"><?php _e('Merge Images', 'madara-harvest'); ?></label></th>
                            <td><input type="checkbox" id="mh_merge_images" name="mh_merge_images" <?php checked(get_option('mh_merge_images', 0), 1); ?> value="1" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_image_merge_direction"><?php _e('Merge Direction', 'madara-harvest'); ?></label></th>
                            <td>
                                <select id="mh_image_merge_direction" name="mh_image_merge_direction">
                                    <option value="vertical" <?php selected(get_option('mh_image_merge_direction', 'vertical'), 'vertical'); ?>><?php _e('Vertical', 'madara-harvest'); ?></option>
                                    <option value="horizontal" <?php selected(get_option('mh_image_merge_direction', 'horizontal'), 'horizontal'); ?>><?php _e('Horizontal', 'madara-harvest'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mh_image_merge_quality"><?php _e('Quality (0-100)', 'madara-harvest'); ?></label></th>
                            <td><input type="number" id="mh_image_merge_quality" name="mh_image_merge_quality" value="<?php echo esc_attr(get_option('mh_image_merge_quality', 75)); ?>" min="0" max="100" /></td>
                        </tr>
                        <tr>
                            <th><label for="mh_image_merge_format"><?php _e('Format', 'madara-harvest'); ?></label></th>
                            <td>
                                <select id="mh_image_merge_format" name="mh_image_merge_format">
                                    <option value="avif" <?php selected(get_option('mh_image_merge_format', 'avif'), 'avif'); ?>><?php _e('AVIF', 'madara-harvest'); ?></option>
                                    <option value="webp" <?php selected(get_option('mh_image_merge_format', 'webp'), 'webp'); ?>><?php _e('WebP', 'madara-harvest'); ?></option>
                                    <option value="jpeg" <?php selected(get_option('mh_image_merge_format', 'jpeg'), 'jpeg'); ?>><?php _e('JPEG', 'madara-harvest'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mh_image_merge_bg_color"><?php _e('Background Color', 'madara-harvest'); ?></label></th>
                            <td><input type="text" id="mh_image_merge_bg_color" name="mh_image_merge_bg_color" value="<?php echo esc_attr(get_option('mh_image_merge_bg_color', 'white')); ?>" /></td>
                        </tr>
                    </table>
                </div>

                <div class="mh-settings-tab" data-tab="servers">
                    <h2><?php _e('Proxy Servers', 'madara-harvest'); ?></h2>
                    <div id="mh-proxies">
                        <?php
                        $proxies = json_decode(get_option('mh_proxy_list', '[]'), true);
                        foreach ($proxies as $index => $proxy):
                        ?>
                            <div class="mh-proxy collapsible">
                                <h3><?php printf(__('Proxy %d', 'madara-harvest'), $index + 1); ?></h3>
                                <div class="mh-proxy-content">
                                    <p><label><?php _e('URL:', 'madara-harvest'); ?> <input type="text" name="proxies[<?php echo esc_attr($index); ?>][url]" value="<?php echo esc_attr($proxy['url']); ?>" /></label></p>
                                    <p><label><?php _e('Port:', 'madara-harvest'); ?> <input type="number" name="proxies[<?php echo esc_attr($index); ?>][port]" value="<?php echo esc_attr($proxy['port']); ?>" min="1" max="65535" /></label></p>
                                    <p><label><?php _e('Username:', 'madara-harvest'); ?> <input type="text" name="proxies[<?php echo esc_attr($index); ?>][username]" value="<?php echo esc_attr($proxy['username']); ?>" /></label></p>
                                    <p><label><?php _e('Password:', 'madara-harvest'); ?> <input type="password" name="proxies[<?php echo esc_attr($index); ?>][password]" value="<?php echo esc_attr($proxy['password']); ?>" /></label></p>
                                    <p><label><?php _e('Remove:', 'madara-harvest'); ?> <input type="checkbox" name="proxies[<?php echo esc_attr($index); ?>][remove]" value="1" /></label></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p><button type="button" id="add-proxy" class="button"><?php _e('Add Proxy', 'madara-harvest'); ?></button></p>
                </div>

                <div class="mh-settings-tab" data-tab="sites">
                    <h2><?php _e('Sites & XPaths', 'madara-harvest'); ?></h2>
                    <div id="mh-sites">
                        <?php
                        $sites_config = get_option('mh_sites_config');
                        if (empty($sites_config)) {
                            $sites_config = mh_get_default_config();
                            update_option('mh_sites_config', $sites_config);
                        }
                        $sites = json_decode($sites_config, true);
                        foreach ($sites as $index => $site):
                        ?>
                            <div class="mh-site collapsible">
                                <h3><?php echo esc_html($site['site_name']); ?> <span class="mh-site-status" style="color: <?php $status = get_option('mh_site_status', [])[$site['site_name']]['status'] ?? 'unknown'; echo esc_attr($status === 'success' ? 'green' : ($status === 'error' ? 'red' : 'gray')); ?>;">●</span></h3>
                                <div class="mh-site-content">
                                    <p><label><?php _e('Site Name:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][site_name]" value="<?php echo esc_attr($site['site_name']); ?>" /></label></p>
                                    <p><label><?php _e('Base URL:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][base_url]" value="<?php echo esc_attr($site['base_url']); ?>" /></label></p>
                                    <p><label><?php _e('AJAX Endpoint:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_list_ajax]" value="<?php echo esc_attr($site['manga_list_ajax']); ?>" /></label></p>
                                    <p><label><?php _e('AJAX Params:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_list_ajax_params]" value="<?php echo esc_attr($site['manga_list_ajax_params']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Method:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_list_method]" value="<?php echo esc_attr($site['manga_list_method']); ?>" /></label></p>
                                    <p><label><?php _e('Manga Item XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_item_xpath]" value="<?php echo esc_attr($site['manga_item_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Chapter List XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][chapter_list_xpath]" value="<?php echo esc_attr($site['chapter_list_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Images XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][chapter_images_xpath]" value="<?php echo esc_attr($site['chapter_images_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Description XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_description_xpath]" value="<?php echo esc_attr($site['manga_description_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Genre XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_genre_xpath]" value="<?php echo esc_attr($site['manga_genre_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Author XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_author_xpath]" value="<?php echo esc_attr($site['manga_author_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Status XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_status_xpath]" value="<?php echo esc_attr($site['manga_status_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Alt Titles XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_alternative_titles_xpath]" value="<?php echo esc_attr($site['manga_alternative_titles_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Tags XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_tags_xpath]" value="<?php echo esc_attr($site['manga_tags_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Views XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_views_xpath]" value="<?php echo esc_attr($site['manga_views_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Rating XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_rating_xpath]" value="<?php echo esc_attr($site['manga_rating_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Artist XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_artist_xpath]" value="<?php echo esc_attr($site['manga_artist_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Release XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_release_xpath]" value="<?php echo esc_attr($site['manga_release_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Type XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_type_xpath]" value="<?php echo esc_attr($site['manga_type_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Publisher XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_publisher_xpath]" value="<?php echo esc_attr($site['manga_publisher_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Serialization XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_serialization_xpath]" value="<?php echo esc_attr($site['manga_serialization_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Volumes XPath:', 'madara-harvest'); ?> <input type="text" name="sites[<?php echo esc_attr($index); ?>][manga_volumes_xpath]" value="<?php echo esc_attr($site['manga_volumes_xpath']); ?>" size="50" /></label></p>
                                    <p><label><?php _e('Remove:', 'madara-harvest'); ?> <input type="checkbox" name="sites[<?php echo esc_attr($index); ?>][remove]" value="1" /></label></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p><button type="button" id="add-site" class="button"><?php _e('Add Site', 'madara-harvest'); ?></button></p>
                </div>

                <p><?php submit_button(__('Save All Settings', 'madara-harvest'), 'primary', 'mh_save_settings'); ?></p>
            </form>
            <form method="post" action="">
                <?php wp_nonce_field('mh_reset_config_verify'); ?>
                <?php submit_button(__('Reset Sites to Default', 'madara-harvest'), 'secondary', 'mh_reset_config', true, ['onclick' => 'return confirm("' . __('Reset sites to default?', 'madara-harvest') . '");']); ?>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <style>
        .mh-dashboard { max-width: 1200px; margin: 20px auto; }
        .mh-tab-bar { border-bottom: 1px solid #ccc; margin-bottom: 20px; }
        .mh-tab { display: inline-block; padding: 10px 20px; text-decoration: none; color: #0073aa; }
        .mh-tab.active { border-bottom: 2px solid #0073aa; font-weight: bold; }
        .mh-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .mh-panel { background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .mh-panel h2 { margin-top: 0; }
        .mh-settings-tabs { margin-top: 20px; }
        .mh-settings-tab { display: none; }
        .mh-settings-tab.active { display: block; }
        .mh-sub-tab-bar { border-bottom: 1px solid #ddd; margin-bottom: 15px; }
        .mh-sub-tab { display: inline-block; padding: 8px 15px; text-decoration: none; color: #0073aa; }
        .mh-sub-tab.active { border-bottom: 2px solid #0073aa; font-weight: bold; }
        .mh-site, .mh-proxy { margin-bottom: 10px; border: 1px solid #ddd; border-radius: 5px; }
        .mh-site h3, .mh-proxy h3 { cursor: pointer; padding: 10px; margin: 0; background: #f1f1f1; }
        .mh-site-content, .mh-proxy-content { padding: 10px; display: none; }
        .mh-site.collapsible .mh-site-content.active, .mh-proxy.collapsible .mh-proxy-content.active { display: block; }
        .mh-site-status { font-size: 16px; }
        .mh-tooltip { cursor: help; color: #0073aa; font-size: 14px; }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.mh-tab').forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelectorAll('.mh-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    const targetTab = this.getAttribute('href').split('tab=')[1];
                    if (targetTab === 'overview') {
                        document.querySelector('.mh-grid').style.display = 'grid';
                        document.querySelector('.mh-settings-tabs').style.display = 'none';
                    } else if (targetTab === 'settings') {
                        document.querySelector('.mh-grid').style.display = 'none';
                        document.querySelector('.mh-settings-tabs').style.display = 'block';
                        document.querySelectorAll('.mh-settings-tab').forEach(t => t.classList.remove('active'));
                        document.querySelector('.mh-settings-tab[data-tab="general"]').classList.add('active');
                        document.querySelectorAll('.mh-sub-tab').forEach(st => st.classList.remove('active'));
                        document.querySelector('.mh-sub-tab[href="#general"]').classList.add('active');
                    }
                });
            });

            document.querySelectorAll('.mh-sub-tab').forEach(subTab => {
                subTab.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelectorAll('.mh-sub-tab').forEach(st => st.classList.remove('active'));
                    this.classList.add('active');
                    const targetSubTab = this.getAttribute('href').split('#')[1];
                    document.querySelectorAll('.mh-settings-tab').forEach(t => t.classList.remove('active'));
                    document.querySelector(`.mh-settings-tab[data-tab="${targetSubTab}"]`).classList.add('active');
                });
            });

            document.querySelectorAll('.mh-site h3, .mh-proxy h3').forEach(h => {
                h.addEventListener('click', function() {
                    this.nextElementSibling.classList.toggle('active');
                });
            });

            document.getElementById('add-site').addEventListener('click', function() {
                const sites = document.getElementById('mh-sites');
                const count = sites.children.length;
                const div = document.createElement('div');
                div.className = 'mh-site collapsible';
                div.innerHTML = `
                    <h3><?php _e('New Site', 'madara-harvest'); ?> <span class="mh-site-status" style="color: gray;">●</span></h3>
                    <div class="mh-site-content">
                        <p><label><?php _e('Site Name:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][site_name]" value="" /></label></p>
                        <p><label><?php _e('Base URL:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][base_url]" value="" /></label></p>
                        <p><label><?php _e('AJAX Endpoint:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_list_ajax]" value="" /></label></p>
                        <p><label><?php _e('AJAX Params:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_list_ajax_params]" value="" size="50" /></label></p>
                        <p><label><?php _e('Method:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_list_method]" value="POST" /></label></p>
                        <p><label><?php _e('Manga Item XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_item_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Chapter List XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][chapter_list_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Images XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][chapter_images_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Description XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_description_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Genre XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_genre_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Author XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_author_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Status XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_status_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Alt Titles XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_alternative_titles_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Tags XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_tags_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Views XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_views_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Rating XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_rating_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Artist XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_artist_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Release XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_release_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Type XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_type_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Publisher XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_publisher_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Serialization XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_serialization_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Volumes XPath:', 'madara-harvest'); ?> <input type="text" name="sites[${count}][manga_volumes_xpath]" value="" size="50" /></label></p>
                        <p><label><?php _e('Remove:', 'madara-harvest'); ?> <input type="checkbox" name="sites[${count}][remove]" value="1" /></label></p>
                    </div>
                `;
                sites.appendChild(div);
                div.querySelector('h3').addEventListener('click', function() {
                    div.querySelector('.mh-site-content').classList.toggle('active');
                });
            });

            document.getElementById('add-proxy').addEventListener('click', function() {
                const proxies = document.getElementById('mh-proxies');
                const count = proxies.children.length;
                const div = document.createElement('div');
                div.className = 'mh-proxy collapsible';
                div.innerHTML = `
                    <h3><?php _e('New Proxy', 'madara-harvest'); ?></h3>
                    <div class="mh-proxy-content">
                        <p><label><?php _e('URL:', 'madara-harvest'); ?> <input type="text" name="proxies[${count}][url]" value="" /></label></p>
                        <p><label><?php _e('Port:', 'madara-harvest'); ?> <input type="number" name="proxies[${count}][port]" value="" min="1" max="65535" /></label></p>
                        <p><label><?php _e('Username:', 'madara-harvest'); ?> <input type="text" name="proxies[${count}][username]" value="" /></label></p>
                        <p><label><?php _e('Password:', 'madara-harvest'); ?> <input type="password" name="proxies[${count}][password]" value="" /></label></p>
                        <p><label><?php _e('Remove:', 'madara-harvest'); ?> <input type="checkbox" name="proxies[${count}][remove]" value="1" /></label></p>
                    </div>
                `;
                proxies.appendChild(div);
                div.querySelector('h3').addEventListener('click', function() {
                    div.querySelector('.mh-proxy-content').classList.toggle('active');
                });
            });
        });
    </script>
    <?php
}

/**
 * Renders the setup wizard for initial configuration.
 */
function mh_render_setup_wizard(): void {
    ?>
    <div class="wrap mh-dashboard">
        <h1><?php _e('MadaraHarvest Setup Wizard', 'madara-harvest'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('mh_complete_wizard_verify'); ?>
            <p><?php _e('Welcome to MadaraHarvest! Configure your initial settings below to get started.', 'madara-harvest'); ?></p>
            <table class="form-table">
                <tr>
                    <th><label for="mh_sites_config"><?php _e('Sites Configuration (JSON)', 'madara-harvest'); ?></label></th>
                    <td>
                        <textarea id="mh_sites_config" name="mh_sites_config" rows="10" cols="50"><?php echo esc_textarea(mh_get_default_config()); ?></textarea>
                        <p class="description"><?php _e('Enter a JSON array of site configurations.', 'madara-harvest'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="mh_debug_mode"><?php _e('Enable Debug Mode', 'madara-harvest'); ?></label></th>
                    <td><input type="checkbox" id="mh_debug_mode" name="mh_debug_mode" value="1" /> <span class="description"><?php _e('Enable detailed logging for troubleshooting.', 'madara-harvest'); ?></span></td>
                </tr>
            </table>
            <p><input type="submit" name="mh_complete_wizard" class="button button-primary" value="<?php _e('Complete Setup', 'madara-harvest'); ?>" /></p>
        </form>
    </div>
    <?php
}

/**
 * Renders the Queue Status page with comprehensive queue information.
 */
function mh_render_queue_status_page(): void {
    $manga_queue = mh_get_manga_queue();
    $chapter_queue = mh_get_chapter_queue();
    $total_manga = count($manga_queue);
    $total_chapters = count($chapter_queue);
    $manga_paused = count(array_filter($manga_queue, fn($task) => $task['status'] === 'paused'));
    $chapters_paused = count(array_filter($chapter_queue, fn($task) => $task['status'] === 'paused'));
    $manga_errors = count(array_filter($manga_queue, fn($task) => $task['status'] === 'error'));
    $chapter_errors = count(array_filter($chapter_queue, fn($task) => $task['status'] === 'error'));
    $chapters_done = 0; // Simplified tracking via live manga chapters
    $last_run = get_option('mh_last_run', 'Never');

    // Calculate chapters done from live manga
    $live_manga_query = new WP_Query([
        'post_type' => 'wp-manga',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'meta_query' => [
            'relation' => 'AND',
            ['key' => 'madara_manga_source', 'value' => 'MadaraHarvest', 'compare' => '='],
            ['key' => 'madara_manga_source', 'compare' => 'EXISTS']
        ]
    ]);
    while ($live_manga_query->have_posts()) {
        $live_manga_query->the_post();
        $chapters = get_post_meta(get_the_ID(), '_wp_manga_chapters', true);
        $chapters_done += is_array($chapters) ? count($chapters) : 0;
    }
    wp_reset_postdata();

    ?>
    <div class="wrap mh-dashboard">
        <h1><?php _e('Queue Status', 'madara-harvest'); ?></h1>
        <div class="mh-grid">
            <div class="mh-panel">
                <h2><?php _e('Manga Queue', 'madara-harvest'); ?></h2>
                <p><strong><?php _e('Total Manga:', 'madara-harvest'); ?></strong> <?php echo esc_html($total_manga); ?></p>
                <p><strong><?php _e('Paused:', 'madara-harvest'); ?></strong> <?php echo esc_html($manga_paused); ?></p>
                <p><strong><?php _e('Errors:', 'madara-harvest'); ?></strong> <?php echo esc_html($manga_errors); ?></p>
                <p><strong><?php _e('Processing:', 'madara-harvest'); ?></strong> <?php echo esc_html(get_option('mh_process_manga_paused', 0) ? 'Paused' : 'Active'); ?></p>
            </div>
            <div class="mh-panel">
                <h2><?php _e('Chapter Queue', 'madara-harvest'); ?></h2>
                <p><strong><?php _e('Total Chapters:', 'madara-harvest'); ?></strong> <?php echo esc_html($total_chapters); ?></p>
                <p><strong><?php _e('Chapters Done:', 'madara-harvest'); ?></strong> <?php echo esc_html($chapters_done); ?></p>
                <p><strong><?php _e('Paused:', 'madara-harvest'); ?></strong> <?php echo esc_html($chapters_paused); ?></p>
                <p><strong><?php _e('Errors:', 'madara-harvest'); ?></strong> <?php echo esc_html($chapter_errors); ?></p>
                <p><strong><?php _e('Processing:', 'madara-harvest'); ?></strong> <?php echo esc_html(get_option('mh_process_chapter_paused', 0) ? 'Paused' : 'Active'); ?></p>
            </div>
            <div class="mh-panel">
                <h2><?php _e('Queue Summary', 'madara-harvest'); ?></h2>
                <p><strong><?php _e('Total Tasks:', 'madara-harvest'); ?></strong> <?php echo esc_html($total_manga + $total_chapters); ?></p>
                <p><strong><?php _e('Total Paused:', 'madara-harvest'); ?></strong> <?php echo esc_html($manga_paused + $chapters_paused); ?></p>
                <p><strong><?php _e('Total Errors:', 'madara-harvest'); ?></strong> <?php echo esc_html($manga_errors + $chapter_errors); ?></p>
                <p><strong><?php _e('Last Run:', 'madara-harvest'); ?></strong> <?php echo esc_html($last_run); ?></p>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renders the Pending Manga page.
 */
function mh_render_pending_manga_page(): void {
    $manga_queue = mh_get_manga_queue();
    $per_page = 20;
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $total_items = count($manga_queue);
    $total_pages = ceil($total_items / $per_page);
    $offset = ($paged - 1) * $per_page;
    $current_manga = array_slice($manga_queue, $offset, $per_page);

    // Handle bulk actions
    if (isset($_POST['mh_bulk_action']) && check_admin_referer('mh_bulk_action_pending')) {
        $action = sanitize_text_field($_POST['mh_bulk_action']);
        $selected = array_map('intval', $_POST['manga_ids'] ?? []);
        foreach ($selected as $index) {
            if (isset($manga_queue[$index])) {
                if ($action === 'delete') {
                    unset($manga_queue[$index]);
                    mh_log("Deleted manga from queue at index $index", 'INFO');
                } elseif ($action === 'pause') {
                    $manga_queue[$index]['status'] = 'paused';
                    mh_log("Paused manga in queue at index $index", 'INFO');
                } elseif ($action === 'resume') {
                    $manga_queue[$index]['status'] = 'queued';
                    mh_log("Resumed manga in queue at index $index", 'INFO');
                }
            }
        }
        update_option('mh_manga_queue', array_values($manga_queue));
        echo '<div class="notice notice-success"><p>' . __('Bulk action completed.', 'madara-harvest') . '</p></div>';
    }

    // Handle individual actions
    if (isset($_GET['mms_action']) && isset($_GET['index']) && check_admin_referer('mh_' . $_GET['mms_action'], '_mms_nonce')) {
        $index = intval($_GET['index']);
        if (isset($manga_queue[$index])) {
            $action = $_GET['mms_action'];
            if ($action === 'delete_manga') {
                unset($manga_queue[$index]);
                mh_log("Deleted manga from queue at index $index", 'INFO');
            } elseif ($action === 'pause_manga') {
                $manga_queue[$index]['status'] = 'paused';
                mh_log("Paused manga in queue at index $index", 'INFO');
            } elseif ($action === 'resume_manga') {
                $manga_queue[$index]['status'] = 'queued';
                mh_log("Resumed manga in queue at index $index", 'INFO');
            }
            update_option('mh_manga_queue', array_values($manga_queue));
            wp_redirect(admin_url('admin.php?page=mh_pending_manga&paged=' . $paged));
            exit;
        }
    }

    ?>
    <div class="wrap mh-dashboard">
        <h1><?php _e('Pending Manga', 'madara-harvest'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('mh_bulk_action_pending'); ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="mh_bulk_action">
                        <option value=""><?php _e('Bulk Actions', 'madara-harvest'); ?></option>
                        <option value="delete"><?php _e('Delete', 'madara-harvest'); ?></option>
                        <option value="pause"><?php _e('Pause', 'madara-harvest'); ?></option>
                        <option value="resume"><?php _e('Resume', 'madara-harvest'); ?></option>
                    </select>
                    <input type="submit" class="button action" value="<?php _e('Apply', 'madara-harvest'); ?>" />
                </div>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all-pending" /></th>
                        <th><?php _e('Title', 'madara-harvest'); ?></th>
                        <th><?php _e('Site', 'madara-harvest'); ?></th>
                        <th><?php _e('Status', 'madara-harvest'); ?></th>
                        <th><?php _e('Priority', 'madara-harvest'); ?></th>
                        <th><?php _e('Queued Time', 'madara-harvest'); ?></th>
                        <th><?php _e('Actions', 'madara-harvest'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($current_manga)): ?>
                        <tr><td colspan="7"><?php _e('No pending manga found.', 'madara-harvest'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($current_manga as $index => $manga): ?>
                            <tr>
                                <td><input type="checkbox" name="manga_ids[]" value="<?php echo esc_attr($offset + $index); ?>" /></td>
                                <td><?php echo esc_html($manga['manga_title'] ?? 'Untitled'); ?></td>
                                <td><?php echo esc_html($manga['site_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo esc_html($manga['status'] ?? 'Queued'); ?></td>
                                <td><?php echo esc_html($manga['priority'] ?? 'Normal'); ?></td>
                                <td><?php echo esc_html(mh_format_timestamp($manga['queued_timestamp'] ?? 'N/A')); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg(['mms_action' => 'delete_manga', 'index' => $offset + $index, '_mms_nonce' => wp_create_nonce('mh_delete_manga')])); ?>" onclick="return confirm('<?php _e('Delete this manga?', 'madara-harvest'); ?>');"><?php _e('Delete', 'madara-harvest'); ?></a> |
                                    <a href="<?php echo esc_url(add_query_arg(['mms_action' => 'pause_manga', 'index' => $offset + $index, '_mms_nonce' => wp_create_nonce('mh_pause_manga')])); ?>"><?php _e('Pause', 'madara-harvest'); ?></a> |
                                    <a href="<?php echo esc_url(add_query_arg(['mms_action' => 'resume_manga', 'index' => $offset + $index, '_mms_nonce' => wp_create_nonce('mh_resume_manga')])); ?>"><?php _e('Resume', 'madara-harvest'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="tablenav bottom">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('«'),
                    'next_text' => __('»'),
                    'total' => $total_pages,
                    'current' => $paged
                ]);
                ?>
                <span class="displaying-num"><?php printf(__('%d items', 'madara-harvest'), $total_items); ?></span>
            </div>
        </form>
        <script>
            document.getElementById('select-all-pending').addEventListener('change', function() {
                document.querySelectorAll('input[name="manga_ids[]"]').forEach(cb => cb.checked = this.checked);
            });
        </script>
    </div>
    <?php
}

/**
 * Renders the Live Manga page.
 */
function mh_render_live_manga_page(): void {
    // Handle bulk actions
    if (isset($_POST['mh_bulk_action']) && check_admin_referer('mh_bulk_action_verify')) {
        $action = sanitize_text_field($_POST['mh_bulk_action']);
        $manga_ids = array_map('intval', $_POST['manga_ids'] ?? []);
        if ($action === 'bulk_delete') {
            foreach ($manga_ids as $id) {
                wp_delete_post($id, true);
                mh_log("Deleted manga post ID: $id", 'INFO');
            }
        } elseif ($action === 'bulk_rescrape') {
            $sites = json_decode(get_option('mh_sites_config'), true);
            foreach ($manga_ids as $id) {
                $source_id = get_post_meta($id, '_wp_manga_source_id', true);
                $manga_link = get_post_meta($id, '_wp_manga_link', true);
                $site_name = get_post_meta($id, '_wp_manga_site_name', true);
                $site = array_values(array_filter($sites, fn($s) => $s['site_name'] === $site_name))[0] ?? null;
                if ($site && $source_id && $manga_link) {
                    $task = [
                        'site_name' => $site['site_name'],
                        'site_config' => $site,
                        'manga_id' => $source_id,
                        'manga_title' => get_the_title($id),
                        'manga_link' => $manga_link,
                        'cover' => get_post_meta($id, '_wp_manga_poster', true),
                        'alternative' => get_post_meta($id, '_wp_manga_alternative', true),
                        'genre' => get_post_meta($id, '_wp_manga_genre', true),
                        'status' => get_post_meta($id, '_wp_manga_status', true),
                        'retry_count' => 0,
                        'queued_timestamp' => current_time('mysql'),
                        'priority' => 'normal'
                    ];
                    mh_add_to_manga_queue($task);
                    mh_log("Re-queued manga ID: $id for re-scrape", 'INFO');
                }
            }
        }
        wp_redirect(admin_url('admin.php?page=mh_live_manga&bulk_action=success'));
        exit;
    } elseif (isset($_POST['mh_export_live']) && check_admin_referer('mh_export_live_verify')) {
        $manga_query = new WP_Query([
            'post_type' => 'wp-manga',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => 'madara_manga_source', 'value' => 'MadaraHarvest', 'compare' => '='],
                ['key' => 'madara_manga_source', 'compare' => 'EXISTS']
            ]
        ]);
        $export_data = [];
        while ($manga_query->have_posts()) {
            $manga_query->the_post();
            $id = get_the_ID();
            $export_data[] = [
                'title' => get_the_title(),
                'meta' => get_post_meta($id),
                'chapters' => get_post_meta($id, '_wp_manga_chapters', true)
            ];
        }
        wp_reset_postdata();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="live_manga_' . date('Y-m-d-H-i-s') . '.json"');
        echo json_encode($export_data, JSON_PRETTY_PRINT);
        mh_log("Exported " . count($export_data) . " live manga", 'INFO');
        exit;
    }

    // Handle individual actions
    if (isset($_GET['mms_action']) && isset($_GET['id']) && check_admin_referer('mh_' . $_GET['mms_action'] . '_' . $_GET['id'], '_mms_nonce')) {
        $manga_id = intval($_GET['id']);
        $action = $_GET['mms_action'];
        if ($action === 'delete_manga') {
            wp_delete_post($manga_id, true);
            mh_log("Deleted manga post ID: $manga_id", 'INFO');
        } elseif ($action === 'rescrape_manga') {
            $sites = json_decode(get_option('mh_sites_config'), true);
            $source_id = get_post_meta($manga_id, '_wp_manga_source_id', true);
            $manga_link = get_post_meta($manga_id, '_wp_manga_link', true);
            $site_name = get_post_meta($manga_id, '_wp_manga_site_name', true);
            $site = array_values(array_filter($sites, fn($s) => $s['site_name'] === $site_name))[0] ?? null;
            if ($site && $source_id && $manga_link) {
                $task = [
                    'site_name' => $site['site_name'],
                    'site_config' => $site,
                    'manga_id' => $source_id,
                    'manga_title' => get_the_title($manga_id),
                    'manga_link' => $manga_link,
                    'cover' => get_post_meta($manga_id, '_wp_manga_poster', true),
                    'alternative' => get_post_meta($manga_id, '_wp_manga_alternative', true),
                    'genre' => get_post_meta($manga_id, '_wp_manga_genre', true),
                    'status' => get_post_meta($manga_id, '_wp_manga_status', true),
                    'retry_count' => 0,
                    'queued_timestamp' => current_time('mysql'),
                    'priority' => 'normal'
                ];
                mh_add_to_manga_queue($task);
                mh_log("Re-queued manga ID: $manga_id for re-scrape", 'INFO');
            }
        } elseif ($action === 'check_new_chapters') {
            // Placeholder for chapter check logic
            mh_log("Checked new chapters for manga ID: $manga_id (placeholder)", 'INFO');
        }
        wp_redirect(admin_url('admin.php?page=mh_live_manga'));
        exit;
    }

    $per_page = 50;
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $args = [
        'post_type' => 'wp-manga',
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'post_status' => 'any',
        'meta_query' => [
            'relation' => 'AND',
            ['key' => 'madara_manga_source', 'value' => 'MadaraHarvest', 'compare' => '='],
            ['key' => 'madara_manga_source', 'compare' => 'EXISTS']
        ]
    ];
    $manga_query = new WP_Query($args);
    $total_manga = $manga_query->found_posts;
    $total_pages = ceil($total_manga / $per_page);

    ?>
    <div class="wrap mh-dashboard">
        <h1><?php _e('Live Manga', 'madara-harvest'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('mh_bulk_action_verify'); ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="mh_bulk_action">
                        <option value=""><?php _e('Bulk Actions', 'madara-harvest'); ?></option>
                        <option value="bulk_delete"><?php _e('Delete', 'madara-harvest'); ?></option>
                        <option value="bulk_rescrape"><?php _e('Re-scrape', 'madara-harvest'); ?></option>
                    </select>
                    <input type="submit" class="button action" value="<?php _e('Apply', 'madara-harvest'); ?>" />
                    <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(['mms_action' => 'check_all_new_chapters', '_mms_nonce' => wp_create_nonce('mh_check_all_new_chapters')])); ?>" onclick="return confirm('<?php _e('Check all for new chapters?', 'madara-harvest'); ?>');"><?php _e('Check All', 'madara-harvest'); ?></a>
                    <input type="submit" name="mh_export_live" class="button button-secondary" value="<?php _e('Export', 'madara-harvest'); ?>" formnovalidate />
                    <?php wp_nonce_field('mh_export_live_verify', '_wpnonce_export'); ?>
                </div>
            </div>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select_all" /></th>
                        <th><?php _e('Thumbnail', 'madara-harvest'); ?></th>
                        <th><?php _e('Title', 'madara-harvest'); ?></th>
                        <th><?php _e('Chapters', 'madara-harvest'); ?></th>
                        <th><?php _e('Last Scraped', 'madara-harvest'); ?></th>
                        <th><?php _e('Actions', 'madara-harvest'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($manga_query->have_posts()): ?>
                        <?php while ($manga_query->have_posts()): $manga_query->the_post(); ?>
                            <?php
                            $manga_id = get_the_ID();
                            $chapters = get_post_meta($manga_id, '_wp_manga_chapters', true);
                            $chapter_count = is_array($chapters) ? count($chapters) : 0;
                            $last_scraped = get_post_meta($manga_id, '_wp_manga_last_updated', true) ?: 'N/A';
                            ?>
                            <tr>
                                <td><input type="checkbox" name="manga_ids[]" value="<?php echo esc_attr($manga_id); ?>" /></td>
                                <td><?php echo get_the_post_thumbnail($manga_id, [80, 120]); ?></td>
                                <td><a href="<?php echo esc_url(get_edit_post_link($manga_id)); ?>"><?php the_title(); ?></a></td>
                                <td><?php echo esc_html($chapter_count); ?></td>
                                <td><?php echo esc_html($last_scraped); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg(['mms_action' => 'check_new_chapters', 'id' => $manga_id, '_mms_nonce' => wp_create_nonce('mh_check_new_chapters_' . $manga_id)])); ?>"><?php _e('Check', 'madara-harvest'); ?></a> |
                                    <a href="<?php echo esc_url(add_query_arg(['mms_action' => 'rescrape_manga', 'id' => $manga_id, '_mms_nonce' => wp_create_nonce('mh_rescrape_manga_' . $manga_id)])); ?>"><?php _e('Re-scrape', 'madara-harvest'); ?></a> |
                                    <a href="<?php echo esc_url(add_query_arg(['mms_action' => 'delete_manga', 'id' => $manga_id, '_mms_nonce' => wp_create_nonce('mh_delete_manga_' . $manga_id)])); ?>" onclick="return confirm('<?php _e('Delete?', 'madara-harvest'); ?>');"><?php _e('Delete', 'madara-harvest'); ?></a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); ?>
                    <?php else: ?>
                        <tr><td colspan="6"><?php _e('No live manga found.', 'madara-harvest'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="tablenav bottom">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('«'),
                    'next_text' => __('»'),
                    'total' => $total_pages,
                    'current' => $paged
                ]);
                ?>
                <span class="displaying-num"><?php printf(__('%d items', 'madara-harvest'), $total_manga); ?></span>
            </div>
        </form>
        <script>
            document.getElementById('select_all').addEventListener('change', function() {
                document.querySelectorAll('input[name="manga_ids[]"]').forEach(cb => cb.checked = this.checked);
            });
        </script>
    </div>
    <?php
}

/**
 * Renders the Logs page.
 */
function mh_render_logs_page(): void {
    $logs = get_option('mh_log', '');
    $logs_array = explode(PHP_EOL, trim($logs));
    $per_page = 50;
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $total_items = count($logs_array);
    $total_pages = ceil($total_items / $per_page);
    $offset = ($paged - 1) * $per_page;
    $current_logs = array_slice($logs_array, $offset, $per_page);

    // Handle clear logs
    if (isset($_POST['mh_clear_logs']) && check_admin_referer('mh_clear_logs_verify')) {
        update_option('mh_log', '');
        mh_log("Logs cleared by admin", 'INFO');
        echo '<div class="notice notice-success"><p>' . __('Logs cleared successfully.', 'madara-harvest') . '</p></div>';
        $current_logs = [];
        $total_items = 0;
        $total_pages = 1;
    }

    ?>
    <div class="wrap mh-dashboard">
        <h1><?php _e('Logs', 'madara-harvest'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('mh_clear_logs_verify'); ?>
            <p><input type="submit" name="mh_clear_logs" class="button button-secondary" value="<?php _e('Clear Logs', 'madara-harvest'); ?>" onclick="return confirm('<?php _e('Clear all logs?', 'madara-harvest'); ?>');" /></p>
        </form>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Timestamp', 'madara-harvest'); ?></th>
                    <th><?php _e('Level', 'madara-harvest'); ?></th>
                    <th><?php _e('Message', 'madara-harvest'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($current_logs)): ?>
                    <tr><td colspan="3"><?php _e('No logs available.', 'madara-harvest'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($current_logs as $log): ?>
                        <?php
                        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*(\w+): (.+)/', $log, $matches)) {
                            $timestamp = $matches[1];
                            $level = $matches[2];
                            $message = $matches[3];
                        } else {
                            $timestamp = 'N/A';
                            $level = 'UNKNOWN';
                            $message = $log;
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($timestamp); ?></td>
                            <td><?php echo esc_html($level); ?></td>
                            <td><?php echo esc_html($message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="tablenav bottom">
            <?php
            echo paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('«'),
                'next_text' => __('»'),
                'total' => $total_pages,
                'current' => $paged
            ]);
            ?>
            <span class="displaying-num"><?php printf(__('%d items', 'madara-harvest'), $total_items); ?></span>
        </div>
    </div>
    <?php
}