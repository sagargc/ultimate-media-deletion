<?php
namespace UltimateMediaDeletion;

class Core {
    public function __construct() {
        add_action('before_delete_post', [$this, 'delete_all_post_media']);
    }

    public function delete_all_post_media($post_id) {
        if (!is_admin() || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Standard attachments
        $this->delete_standard_attachments($post_id);
        
        // ACF media
        if (function_exists('get_fields')) {
            $this->delete_acf_media_recursive($post_id);
        }
        
        // HTML content images
        $this->delete_images_from_html_content($post_id);
        
        do_action('ultimate_media_deletion_after_delete', $post_id);
    }

    private function delete_standard_attachments($post_id) {
        $attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'post_parent' => $post_id,
            'fields' => 'ids'
        ]);

        foreach ($attachments as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
    }

    private function delete_acf_media_recursive($post_id) {
        $fields = get_fields($post_id);
        if (empty($fields)) return;

        array_walk_recursive($fields, function($value) {
            if (is_numeric($value)) {
                wp_delete_attachment((int)$value, true);
            } elseif (is_array($value) && isset($value['ID'])) {
                wp_delete_attachment($value['ID'], true);
            } elseif (is_string($value) && Helpers::is_image_url($value)) {
                Helpers::delete_attachment_by_url($value);
            }
        });
    }

    private function delete_images_from_html_content($post_id) {
        $content_sources = [
            get_post_field('post_content', $post_id),
            get_post_field('post_excerpt', $post_id)
        ];

        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post_id);
            foreach ($acf_fields as $field_value) {
                if (is_string($field_value)) {
                    $content_sources[] = $field_value;
                }
            }
        }

        foreach ($content_sources as $content) {
            if (empty($content)) continue;
            
            preg_match_all('/<img[^>]+src=([\'"])(?<src>.+?)\1/', $content, $matches);
            if (!empty($matches['src'])) {
                foreach ($matches['src'] as $image_url) {
                    Helpers::delete_attachment_by_url($image_url);
                }
            }
        }
    }

    public static function activate() {
        // Activation code here
    }

    public static function deactivate() {
        // Deactivation code here
    }
}