<?php
namespace UltimateMediaDeletion;

defined('ABSPATH') || exit;

class Helpers {
    /**
     * Count all media items associated with a post
     */
    public static function count_post_media($post_id) {
        $count = self::count_standard_attachments($post_id);
        
        if (function_exists('get_fields')) {
            $count += self::count_acf_media_fields($post_id);
        }
        
        return $count;
    }

    /**
     * Count standard post attachments
     */
    private static function count_standard_attachments($post_id) {
        global $wpdb;
        
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->posts 
             WHERE post_type = 'attachment' 
             AND post_parent = %d",
            $post_id
        ));
    }

    /**
     * Count media references in ACF fields
     */
    private static function count_acf_media_fields($post_id) {
        $fields = get_fields($post_id);
        if (empty($fields)) {
            return 0;
        }

        $count = 0;
        array_walk_recursive($fields, function($value) use (&$count) {
            if (is_numeric($value) || 
                (is_array($value) && isset($value['ID'])) || 
                (is_string($value) && self::is_image_url($value))) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * Check if URL points to an image
     */
    public static function is_image_url($url) {
        if (!is_string($url) || empty($url)) {
            return false;
        }

        $parsed = parse_url($url);
        if (empty($parsed['path'])) {
            return false;
        }

        $ext = strtolower(pathinfo($parsed['path'], PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }

    /**
     * Delete attachment by URL with variations
     */
    public static function delete_attachment_by_url($image_url) {
        if (!self::is_image_url($image_url)) {
            return false;
        }

        // First try WordPress attachment lookup
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) {
            return wp_delete_attachment($attachment_id, true);
        }

        // Fallback to direct file deletion
        $upload_dir = wp_upload_dir();
        if (strpos($image_url, $upload_dir['baseurl']) === false) {
            return false;
        }

        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);
        if (!file_exists($file_path)) {
            return false;
        }

        // Delete main file and variations
        self::delete_file_and_variations($file_path);
        return true;
    }

    /**
     * Delete file and all its variations
     */
    private static function delete_file_and_variations($file_path) {
        if (!file_exists($file_path)) {
            return;
        }

        $file_info = pathinfo($file_path);
        $pattern = sprintf(
            '%s/%s-*.%s',
            $file_info['dirname'],
            $file_info['filename'],
            $file_info['extension']
        );

        // Delete all size variations
        foreach (glob($pattern) as $variation) {
            if (is_file($variation)) {
                unlink($variation);
            }
        }

        // Delete WebP versions if they exist
        $webp_pattern = $file_info['dirname'] . '/' . $file_info['filename'] . '*.webp';
        foreach (glob($webp_pattern) as $webp_file) {
            if (is_file($webp_file)) {
                unlink($webp_file);
            }
        }

        // Delete original file
        if (is_file($file_path)) {
            unlink($file_path);
        }
    }
}