<?php
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
    return defined('UMD_VERSION') ? UMD_VERSION : '1.0.0';
}

/**
 * Plugin activation wrapper
 */
function umd_activate_plugin($network_wide = false) {
    UltimateMediaDeletion\Core::activate($network_wide);
}

/**
 * Delete file by URL (public wrapper)
 */
function umd_delete_file_by_url($url) {
    return UltimateMediaDeletion\Helpers::delete_file_by_url($url);
}

/**
 * Get human-readable file size
 */
function umd_get_file_size($file_path) {
    return UltimateMediaDeletion\Helpers::get_file_size($file_path);
}