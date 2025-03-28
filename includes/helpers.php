<?php
namespace UltimateMediaDeletion;

class Helpers {
    public static function count_post_media($post_id) {
        $count = 0;

        // Standard attachments
        $attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'post_parent' => $post_id,
            'fields' => 'ids'
        ]);
        $count += count($attachments);

        // ACF media
        if (function_exists('get_fields')) {
            $fields = get_fields($post_id);
            if ($fields) {
                array_walk_recursive($fields, function($value) use (&$count) {
                    if (is_numeric($value) || 
                        (is_array($value) && isset($value['ID'])) || 
                        (is_string($value) && self::is_image_url($value))) {
                        $count++;
                    }
                });
            }
        }

        return $count;
    }

    public static function is_image_url($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }

    public static function delete_attachment_by_url($image_url) {
        if (strpos($image_url, site_url()) === false) return;
        
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) {
            wp_delete_attachment($attachment_id, true);
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);
        
        if (file_exists($file_path)) {
            unlink($file_path);
            self::delete_image_variations($file_path);
        }
    }

    public static function delete_image_variations($file_path) {
        $file_info = pathinfo($file_path);
        $pattern = $file_info['dirname'] . '/' . $file_info['filename'] . '-*.' . $file_info['extension'];
        
        foreach (glob($pattern) as $variation) {
            if ($variation !== $file_path) {
                unlink($variation);
                $webp = $variation . '.webp';
                if (file_exists($webp)) {
                    unlink($webp);
                }
            }
        }
    }
}