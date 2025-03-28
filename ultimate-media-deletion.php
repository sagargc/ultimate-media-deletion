<?php
/**
 * Plugin Name: Ultimate Media Deletion
 * Description: Delete all media associated with posts including ACF fields and HTML content
 * Version: 2.1.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL2
 * Text Domain: ultimate-media-deletion
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('UMD_VERSION', '2.1.0');
define('UMD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UMD_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check for required PHP version
if (version_compare(PHP_VERSION, '7.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>';
        printf(
            __('Ultimate Media Deletion requires PHP 7.0 or higher. Your server is running PHP %s.', 'ultimate-media-deletion'),
            PHP_VERSION
        );
        echo '</p></div>';
    });
    return;
}

// Autoload plugin classes
spl_autoload_register(function($class) {
    $prefix = 'UltimateMediaDeletion\\';
    $base_dir = UMD_PLUGIN_DIR . 'includes/';
    
    if (strpos($class, $prefix)) {
        $relative_class = substr($class, strlen($prefix));
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    }
});

// Initialize the plugin
add_action('plugins_loaded', function() {
    require_once UMD_PLUGIN_DIR . 'includes/core.php';
    require_once UMD_PLUGIN_DIR . 'includes/admin.php';
    require_once UMD_PLUGIN_DIR . 'includes/helpers.php';
    
    new UltimateMediaDeletion\Core();
    new UltimateMediaDeletion\Admin();
});

// Register activation/deactivation hooks
register_activation_hook(__FILE__, ['UltimateMediaDeletion\Core', 'activate']);
register_deactivation_hook(__FILE__, ['UltimateMediaDeletion\Core', 'deactivate']);