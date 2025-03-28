<?php
/**
 * Test bootstrap file
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Define WordPress constants
define('WP_PLUGIN_DIR', __DIR__ . '/../');
define('ABSPATH', __DIR__ . '/../');

// Load WordPress mock functions
require_once __DIR__ . '/../vendor/brain/monkey/inc/patchwork-loader.php';

// Initialize Brain Monkey
Brain\Monkey\setUp();

// Common test functions
function umd_test_teardown() {
    Brain\Monkey\tearDown();
}