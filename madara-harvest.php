<?php
/*
Plugin Name: MadaraHarvest
Plugin URI: https://your-site.com/madara-harvest
Description: A comprehensive manga harvesting plugin for WordPress, integrating with the Madara theme and core plugin.
Version: 1.0.0
Author: Your Name
Author URI: https://your-site.com
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: madara-harvest
Domain Path: /languages
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit('Direct script access denied.');
}

// Define constants
define('MADARA_HARVEST_VERSION', '1.0.0');
define('MADARA_HARVEST_DIR', plugin_dir_path(__FILE__));
define('MADARA_HARVEST_URL', plugin_dir_url(__FILE__));

// Include all necessary files
require_once MADARA_HARVEST_DIR . 'includes/core.php';
require_once MADARA_HARVEST_DIR . 'includes/utilities.php';
require_once MADARA_HARVEST_DIR . 'includes/queue.php';
require_once MADARA_HARVEST_DIR . 'includes/admin.php';

/**
 * Activation hook to initialize the plugin.
 */
function mh_activate_plugin(): void {
    mh_initialize_options();
    mh_init_queue_processing();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'mh_activate_plugin');

/**
 * Deactivation hook to clean up.
 */
function mh_deactivate_plugin(): void {
    wp_clear_scheduled_hook('mh_process_manga_queue_hook');
    wp_clear_scheduled_hook('mh_process_chapter_queue_hook');
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'mh_deactivate_plugin');

/**
 * Uninstall hook to remove data (optional).
 */
function mh_uninstall_plugin(): void {
    $options = [
        'mh_debug_mode', 'mh_custom_user_agent', 'mh_proxy_list', 'mh_cache_duration',
        'mh_email_notifications', 'mh_notify_email', 'mh_log_retention_days', 'mh_max_retries',
        'mh_request_delay', 'mh_parallel_threads', 'mh_dry_run', 'mh_post_status',
        'mh_force_fetch', 'mh_enable_comments', 'mh_enable_pingback', 'mh_chapter_threshold',
        'mh_queue_schedule', 'mh_merge_images', 'mh_image_merge_direction', 'mh_image_merge_quality',
        'mh_image_merge_format', 'mh_image_merge_bg_color', 'mh_setup_complete',
        'mh_process_manga_paused', 'mh_process_chapter_paused', 'mh_last_run', 'mh_error_log',
        'mh_manga_queue', 'mh_chapter_queue', 'mh_site_status', 'mh_last_report', 'mh_sites_config'
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mh_fetch_%'");
}
register_uninstall_hook(__FILE__, 'mh_uninstall_plugin');

/**
 * Load text domain for translations.
 */
function mh_load_textdomain(): void {
    load_plugin_textdomain('madara-harvest', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'mh_load_textdomain');

// Register core hooks
add_action('admin_menu', 'mh_add_admin_menu');
add_action('init', 'mh_initialize_options');
add_action('init', 'mh_init_queue_processing');

/**
 * Enqueue admin assets for UI consistency.
 */
function mh_enqueue_admin_assets(): void {
    wp_enqueue_style('mh-admin-style', MADARA_HARVEST_URL . 'assets/css/admin.css', [], MADARA_HARVEST_VERSION);
    wp_enqueue_script('mh-admin-script', MADARA_HARVEST_URL . 'assets/js/admin.js', ['jquery'], MADARA_HARVEST_VERSION, true);
}
add_action('admin_enqueue_scripts', 'mh_enqueue_admin_assets');

/**
 * Register AJAX endpoints for manual actions.
 */
function mh_register_ajax_endpoints(): void {
    add_action('wp_ajax_mh_check_health', function () {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        mh_check_site_health();
        wp_send_json_success('Health check completed');
    });

    add_action('wp_ajax_mh_manual_run', function () {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        mh_process_manga_queue();
        mh_process_chapter_queue();
        wp_send_json_success('Manual run completed');
    });
}
add_action('admin_init', 'mh_register_ajax_endpoints');