<?php
/**
 * Plugin Name: Ultimate Media Deletion
 * Description: Comprehensive media deletion solution for WordPress
 * Version: 2.1.0
 * Author: Sagar GC
 * Author URI: https://sagargc.com.np
 * Plugin URI: https://sagargc.com.np/ultimate-media-deletion 
 * License: GPL-3.0+
 * Text Domain: ultimate-media-deletion
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

// Define constants
define('UMD_VERSION', '2.1.0');
define('UMD_PLUGIN_FILE', __FILE__);
define('UMD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UMD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UMD_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check PHP version
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>',
            sprintf(
                __('Ultimate Media Deletion requires PHP 7.4+. Your server is running PHP %s.', 'ultimate-media-deletion'),
                PHP_VERSION
            ),
        '</p></div>';
    });
    return;
}

// Load Composer autoloader
if (file_exists(UMD_PLUGIN_DIR . 'vendor/autoload.php')) {
    require UMD_PLUGIN_DIR . 'vendor/autoload.php';
}

// Initialize plugin
add_action('plugins_loaded', function() {
    // Load core classes
    require_once UMD_PLUGIN_DIR . 'includes/Core.php';
    require_once UMD_PLUGIN_DIR . 'includes/Admin.php';
    require_once UMD_PLUGIN_DIR . 'includes/Helpers.php';

    // Instantiate main components
    new UltimateMediaDeletion\Core();
    new UltimateMediaDeletion\Admin();
}, 5);

// Register activation hook
register_activation_hook(__FILE__, function($network_wide) {
    require_once UMD_PLUGIN_DIR . 'includes/Core.php';
    UltimateMediaDeletion\Core::activate($network_wide);
});

// Register deactivation hook
register_deactivation_hook(__FILE__, function() {
    require_once UMD_PLUGIN_DIR . 'includes/Core.php';
    UltimateMediaDeletion\Core::deactivate();
});

// Register uninstall handler
register_uninstall_hook(__FILE__, 'umd_uninstall_handler');

/**
 * Handles plugin uninstallation
 */
function umd_uninstall_handler() {
    require_once UMD_PLUGIN_DIR . 'includes/Core.php';
    
    if (method_exists('UltimateMediaDeletion\Core', 'uninstall')) {
        UltimateMediaDeletion\Core::uninstall();
    } else {
        // Fallback cleanup
        delete_option('ultimate_media_deletion_settings');
        wp_clear_scheduled_hook('umd_daily_cleanup');
    }
}

// Add "View details" link to plugin row
add_filter('plugin_row_meta', 'umd_custom_view_details_link', 10, 2);
function umd_custom_view_details_link($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $view_details_link = '<a href="' . esc_url('https://sagargc.com.np/ultimate-media-deletion') . '" target="_blank">View details</a>';
        array_splice($links, 1, 0, $view_details_link);
    }
    return $links;
}