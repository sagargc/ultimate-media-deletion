<?php
namespace UltimateMediaDeletion;

defined('ABSPATH') || exit;

class Core {
    /**
     * Initialize plugin core functionality
     */
    public function __construct() {
        // Media deletion hooks
        add_action('before_delete_post', [$this, 'delete_all_post_media']);
        
        // Scheduled tasks
        add_action('umd_daily_cleanup', [__CLASS__, 'daily_cleanup']);
        
        // Admin UI
        add_action('admin_notices', [__CLASS__, 'show_activation_notice']);
        
        // Version check on init
        add_action('init', [$this, 'version_check']);
    }

    /**
     * Handle post media deletion
     */
    public function delete_all_post_media($post_id) {
        if (!is_admin() || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        $this->delete_standard_attachments($post_id);
        
        if (function_exists('get_fields')) {
            $this->delete_acf_media_recursive($post_id);
        }
        
        $this->delete_images_from_html_content($post_id);
        
        // Log the deletion
        self::log_deletion($post_id);
        
        do_action('ultimate_media_deletion_after_delete', $post_id);
    }

    /**
     * Delete standard post attachments
     */
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

    /**
     * Recursively delete ACF media fields
     */
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

    /**
     * Delete images from HTML content
     */
    private function delete_images_from_html_content($post_id) {
        $content_sources = [
            get_post_field('post_content', $post_id),
            get_post_field('post_excerpt', $post_id)
        ];

        if (function_exists('get_fields')) {
            foreach (get_fields($post_id) as $field_value) {
                if (is_string($field_value)) {
                    $content_sources[] = $field_value;
                }
            }
        }

        foreach ($content_sources as $content) {
            if (empty($content)) continue;
            
            preg_match_all('/<img[^>]+src=([\'"])(?<src>.+?)\1/', $content, $matches);
            
            foreach ($matches['src'] ?? [] as $image_url) {
                Helpers::delete_attachment_by_url($image_url);
            }
        }
    }

    /**
     * Plugin activation handler
     */
    public static function activate($network_wide = false) {
        try {
            if (is_multisite() && $network_wide) {
                self::network_activate();
            } else {
                self::single_activate();
            }
            
            update_option('umd_version', UMD_VERSION);
            set_transient('umd_activation_notice', true, 30);
        } catch (\Exception $e) {
            error_log('UMD Activation Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Plugin deactivation handler
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('umd_daily_cleanup');
        delete_transient('umd_activation_notice');
    }

    /**
     * Handle version upgrades
     */
    public function version_check() {
        $stored_version = get_option('umd_version', '0.1.0');
        
        if (version_compare($stored_version, UMD_VERSION, '<')) {
            $this->run_upgrades($stored_version);
            update_option('umd_version', UMD_VERSION);
        }
    }

    /**
     * Run version-specific upgrades
     */
    private function run_upgrades($from_version) {
        if (version_compare($from_version, '2.0.0', '<')) {
            self::create_tables();
        }
        
        // Add future version upgrades here
    }

    /**
     * Network activation handler
     */
    private static function network_activate() {
        foreach (get_sites(['fields' => 'ids']) as $site_id) {
            switch_to_blog($site_id);
            self::single_activate();
            restore_current_blog();
        }
    }

    /**
     * Single site activation
     */
    private static function single_activate() {
        foreach (['administrator', 'editor'] as $role_name) {
            if ($role = get_role($role_name)) {
                $role->add_cap('delete_with_media');
                $role->add_cap('view_media_deletion_logs');
            }
        }
        
        self::create_tables();
        
        if (!wp_next_scheduled('umd_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'umd_daily_cleanup');
        }
    }

    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'umd_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            media_count int(11) NOT NULL DEFAULT 0,
            details longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY user_id (user_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Log media deletions
     */
    private static function log_deletion($post_id) {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'umd_logs', [
            'post_id' => $post_id,
            'user_id' => get_current_user_id(),
            'media_count' => Helpers::count_post_media($post_id),
            'details' => maybe_serialize([
                'post_title' => get_the_title($post_id),
                'post_type' => get_post_type($post_id)
            ])
        ]);
    }

    /**
     * Daily maintenance cleanup
     */
    public static function daily_cleanup() {
        global $wpdb;
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}umd_logs WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
    }

    /**
     * Show admin notices
     */
    public static function show_activation_notice() {
        if (get_transient('umd_activation_notice')) {
            echo '<div class="notice notice-success is-dismissible">',
                '<p>', sprintf(
                    __('Ultimate Media Deletion %s is ready!', 'ultimate-media-deletion'),
                    UMD_VERSION
                ), '</p>',
            '</div>';
            
            delete_transient('umd_activation_notice');
        }
    }
}