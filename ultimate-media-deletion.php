<?php
/**
 * Plugin Name: Ultimate Media Deletion
 * Description: Comprehensive media deletion solution for WordPress
 * Version: 2.1.0
 * Author: Sagar GC
 * Author URI: https://sagargc.com.np
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

// Register hooks
register_activation_hook(__FILE__, function($network_wide) {
    require_once UMD_PLUGIN_DIR . 'includes/Core.php';
    UltimateMediaDeletion\Core::activate($network_wide);
});

register_deactivation_hook(__FILE__, function() {
    require_once UMD_PLUGIN_DIR . 'includes/Core.php';
    UltimateMediaDeletion\Core::deactivate();
});

register_uninstall_hook(__FILE__, [UltimateMediaDeletion\Core::class, 'uninstall']);

// Load test environment if needed
if (defined('UMD_RUNNING_TESTS') && UMD_RUNNING_TESTS) {
    require_once UMD_PLUGIN_DIR . 'tests/bootstrap.php';
}