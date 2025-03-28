<?php
// includes/functions.php

if (!defined('ABSPATH')) exit;

/**
 * Helper function to count media for a post
 */
function umd_count_post_media($post_id) {
    return UltimateMediaDeletion\Helpers::count_post_media($post_id);
}

/**
 * Check if URL is an image
 */
function umd_is_image_url($url) {
    return UltimateMediaDeletion\Helpers::is_image_url($url);
}

/**
 * Get plugin version
 */
function umd_get_version() {
    return UMD_VERSION;
}

/**
 * Plugin activation wrapper
 */
function umd_activate_plugin($network_wide = false) {
    UltimateMediaDeletion\Core::activate($network_wide);
}

// Add other utility functions as needed