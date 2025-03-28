<?php
/**
 * Plugin Name: Ultimate Media Deletion
 * Plugin URI: https://github.com/sagargc/ultimate-media-deletion
 * Description: Comprehensive solution for deleting WordPress media including standard attachments, ACF fields, and HTML content images with full admin UI support.
 * Version: 2.2.0
 * Author: Sagar GC
 * Author URI: https://sagargc.com.np
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: ultimate-media-deletion
 * Domain Path: /languages
 */

/*
Ultimate Media Deletion is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
any later version.

Ultimate Media Deletion is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
*/

defined('ABSPATH') || exit;

class Ultimate_Media_Deletion {

    private static $instance;
    public $deleted_media_count = 0;

    public static function instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        // Core hooks
        add_action('before_delete_post', [$this, 'delete_all_post_media']);
        
        // Admin UI
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Bulk actions
        add_filter('bulk_actions-edit-post', [$this, 'register_bulk_actions']);
        add_filter('bulk_actions-edit-page', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_actions'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_actions'], 10, 3);
    }

    public function delete_all_post_media($post_id) {
        $this->deleted_media_count = 0;
        
        // 1. Standard attachments
        $attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'post_parent' => $post_id,
            'fields' => 'ids'
        ]);
        
        foreach ($attachments as $attachment_id) {
            if (wp_delete_attachment($attachment_id, true)) {
                $this->deleted_media_count++;
            }
        }

        // 2. ACF media
        if (function_exists('get_fields')) {
            $fields = get_fields($post_id);
            if ($fields) {
                array_walk_recursive($fields, function($value) {
                    if (is_numeric($value)) {
                        if (wp_delete_attachment((int)$value, true)) {
                            $this->deleted_media_count++;
                        }
                    } elseif (is_array($value) && isset($value['ID'])) {
                        if (wp_delete_attachment($value['ID'], true)) {
                            $this->deleted_media_count++;
                        }
                    } elseif (is_string($value) && $this->is_image_url($value)) {
                        if ($this->delete_attachment_by_url($value)) {
                            $this->deleted_media_count++;
                        }
                    }
                });
            }
        }

        // 3. HTML content images
        $post = get_post($post_id);
        if ($post) {
            preg_match_all('/<img[^>]+src=([\'"])(?<src>.+?)\1/', $post->post_content, $matches);
            if (!empty($matches['src'])) {
                foreach ($matches['src'] as $image_url) {
                    if ($this->delete_attachment_by_url($image_url)) {
                        $this->deleted_media_count++;
                    }
                }
            }
        }
        
        return $this->deleted_media_count;
    }

    public function admin_notices() {
        // Single post deletion notice
        if (isset($_GET['umd_deleted']) && !empty($_GET['post'])) {
            $count = (int)$_GET['umd_deleted'];
            echo $this->get_notice_html(
                sprintf(
                    _n(
                        'Deleted %d media item along with the post.',
                        'Deleted %d media items along with the post.', 
                        $count,
                        'ultimate-media-deletion'
                    ),
                    $count
                ),
                'success'
            );
        }

        // Bulk deletion notice
        if (!empty($_REQUEST['bulk_action']) && 'delete_with_media' === $_REQUEST['bulk_action']) {
            $count = intval($_REQUEST['deleted_with_media'] ?? 0);
            echo $this->get_notice_html(
                sprintf(
                    _n(
                        'Deleted %d post with all associated media.',
                        'Deleted %d posts with all associated media.',
                        $count,
                        'ultimate-media-deletion'
                    ),
                    $count
                ),
                'success'
            );
        }

        // Warning before deletion
        global $post;
        if (isset($_GET['action']) && 'delete' === $_GET['action'] && $post) {
            $media_count = $this->count_post_media($post->ID);
            if ($media_count > 0) {
                echo $this->get_notice_html(
                    sprintf(
                        _n(
                            'Warning: This will permanently delete %d attached media item.',
                            'Warning: This will permanently delete %d attached media items.', 
                            $media_count,
                            'ultimate-media-deletion'
                        ),
                        $media_count
                    ),
                    'warning'
                );
            }
        }
    }

    private function get_notice_html($message, $type = 'success') {
        return sprintf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    // ... [Include all other methods from previous implementation]

    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ('delete_with_media' !== $doaction) {
            return $redirect_to;
        }

        $deleted = 0;
        foreach ($post_ids as $post_id) {
            $media_deleted = $this->delete_all_post_media($post_id);
            if (wp_delete_post($post_id, true)) {
                $deleted++;
                $this->log_deletion($post_id, $media_deleted);
            }
        }

        return add_query_arg([
            'deleted_with_media' => $deleted,
            'bulk_action' => 'delete_with_media'
        ], $redirect_to);
    }

    private function log_deletion($post_id, $media_count) {
        error_log(sprintf(
            '[Ultimate Media Deletion] Deleted post %d with %d media items',
            $post_id,
            $media_count
        ));
    }
}

// Initialize the plugin
Ultimate_Media_Deletion::instance();

// Hook for direct post deletion
add_action('wp_trash_post', function($post_id) {
    $deleted_count = Ultimate_Media_Deletion::instance()->delete_all_post_media($post_id);
    add_filter('redirect_post_location', function($location) use ($deleted_count) {
        return add_query_arg('umd_deleted', $deleted_count, $location);
    });
});