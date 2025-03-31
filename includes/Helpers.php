<?php
namespace UltimateMediaDeletion;

defined('ABSPATH') || exit;

class Helpers {
    /**
     * Count all media items associated with a post (with caching)
     */
    public static function count_post_media($post_id) {
        static $cache = [];
        
        if (isset($cache[$post_id])) {
            return $cache[$post_id];
        }

        $count = self::count_standard_attachments($post_id);
        
        if (function_exists('get_fields')) {
            $count += self::count_acf_media_fields($post_id);
        }
        
        $count += self::count_embedded_media($post_id);
        
        $cache[$post_id] = $count;
        return $count;
    }

    /**
     * Count standard attachments (optimized query)
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
     * Get attachment IDs for a post (optimized)
     */
    public static function get_post_attachment_ids($post_id) {
        global $wpdb;
        
        return $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts 
             WHERE post_type = 'attachment' 
             AND post_parent = %d",
            $post_id
        ));
    }

    /**
     * Count media in ACF fields (recursive check)
     */
    private static function count_acf_media_fields($post_id) {
        static $cache = [];
        
        if (isset($cache[$post_id])) {
            return $cache[$post_id];
        }

        $fields = get_fields($post_id);
        if (empty($fields)) {
            $cache[$post_id] = 0;
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveArrayIterator($fields),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $value) {
            if (is_numeric($value)) {
                $count++;
            } elseif (is_array($value) && isset($value['ID'])) {
                $count++;
            } elseif (is_string($value) && self::is_image_url($value)) {
                $count++;
            }
        }

        $cache[$post_id] = $count;
        return $count;
    }

    /**
     * Count embedded media in content
     */
    public static function count_embedded_media($post_id) {
        $post = get_post($post_id);
        if (!$post) return 0;

        $count = 0;
        $content_sources = [$post->post_content, $post->post_excerpt];
        
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post_id);
            if (is_array($acf_fields)) {
                foreach ($acf_fields as $field_value) {
                    if (is_string($field_value)) {
                        $content_sources[] = $field_value;
                    }
                }
            }
        }

        foreach ($content_sources as $content) {
            if (empty($content)) continue;
            
            // Count <img> tags
            preg_match_all('/<img[^>]+src=([\'"])(?<src>.+?)\1/', $content, $matches);
            $count += count($matches['src'] ?? []);
            
            // Count WordPress image shortcodes
            preg_match_all('/\[(?:gallery|image|wp_image)\b[^\]]*\]/', $content, $shortcodes);
            $count += count($shortcodes[0] ?? []);
        }

        return $count;
    }

    /**
     * Enhanced image URL validation
     */
    public static function is_image_url($url) {
        if (!is_string($url) || empty($url)) return false;

        // Skip data URLs
        if (strpos($url, 'data:image') === 0) return false;

        $parsed = parse_url($url);
        if (empty($parsed['path'])) return false;

        $ext = strtolower(pathinfo($parsed['path'], PATHINFO_EXTENSION));
        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        
        // Check for WordPress size suffixes
        if (preg_match('/-\d+x\d+\.('.implode('|', $image_exts).')$/i', $parsed['path'])) {
            return true;
        }

        return in_array($ext, $image_exts);
    }

    /**
     * Delete file by URL (with variations)
     */
    public static function delete_file_by_url($file_url) {
        $upload_dir = wp_upload_dir();
        
        if (strpos($file_url, $upload_dir['baseurl']) === false) {
            return false;
        }

        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_url);
        return self::delete_file_and_variations($file_path);
    }

    /**
     * Delete file and all its variations
     */
    public static function delete_file_and_variations($file_path) {
        if (!file_exists($file_path)) return false;

        $file_info = pathinfo($file_path);
        $deleted = true;

        // Delete all size variations
        $variations = glob(sprintf(
            '%s/%s-*.%s',
            $file_info['dirname'],
            $file_info['filename'],
            $file_info['extension']
        )) ?: [];

        // Add WebP variations
        $variations = array_merge($variations, glob(sprintf(
            '%s/%s-*.webp',
            $file_info['dirname'],
            $file_info['filename']
        )) ?: []);

        // Add original variations
        $variations[] = $file_path;
        $variations[] = str_replace('.'.$file_info['extension'], '.webp', $file_path);

        foreach (array_unique($variations) as $file) {
            if (is_file($file) && !unlink($file)) {
                error_log("[UMD] Failed to delete file: {$file}");
                $deleted = false;
            }
        }

        // Clean up empty directories
        self::cleanup_empty_directories($file_info['dirname']);
        
        return $deleted;
    }

    /**
     * Recursively clean up empty directories
     */
    public static function cleanup_empty_directories($dir) {
        if (!is_dir($dir)) return;

        // Skip uploads root directory
        $upload_dir = wp_upload_dir();
        if (trailingslashit($dir) === trailingslashit($upload_dir['basedir'])) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        if (!empty($files)) return;

        if (@rmdir($dir)) {
            self::cleanup_empty_directories(dirname($dir));
        }
    }

    /**
     * Verify if file is within uploads directory
     */
    public static function is_file_in_uploads($file_path) {
        $upload_dir = wp_upload_dir();
        $upload_base = trailingslashit($upload_dir['basedir']);
        
        return strpos(trailingslashit($file_path), $upload_base) === 0;
    }

    /**
     * Get human-readable file size
     */
    public static function get_file_size($file_path) {
        if (!file_exists($file_path)) return 0;

        $bytes = filesize($file_path);
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Public alias for count_post_media()
     */
    public static function get_media_count($post_id) {
        return self::count_post_media($post_id);
    }
}