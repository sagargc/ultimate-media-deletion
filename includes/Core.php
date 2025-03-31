<?php
namespace UltimateMediaDeletion;

defined('ABSPATH') || exit;

class Core {
    /**
     * Initialize plugin core functionality
     */
    public function __construct() {
        // Register cleanup hooks
        add_action('init', [$this, 'register_cleanup_hooks']);
        
        // Media deletion hooks
        add_action('before_delete_post', [$this, 'delete_associated_media']);
        add_action('trashed_post', [$this, 'handle_trashed_post']);
        
        // Scheduled tasks
        if (!wp_next_scheduled('umd_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'umd_daily_cleanup');
        }
        add_action('umd_daily_cleanup', [$this, 'daily_cleanup']);
        
        // Version check
        add_action('init', [$this, 'version_check']);
    }

    /**
     * Register all cleanup hooks
     */
    public function register_cleanup_hooks() {
        // Clean orphaned metadata
        add_action('deleted_post', [$this, 'clean_orphaned_postmeta']);
        
        // Cleanup attachment files
        add_action('delete_attachment', [$this, 'cleanup_attachment_files'], 10, 2);
        
        // Clean post revisions
        add_action('wp_before_delete_post', [$this, 'clean_post_revisions']);
    }

    /**
     * Delete all media associated with a post
     */
    public function delete_associated_media($post_id) {
        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Get media count before deletion
        $media_count = Helpers::count_post_media($post_id);
        
        if ($media_count > 0) {
            $this->delete_attachments($post_id);
            $this->delete_acf_media($post_id);
            $this->delete_embedded_media($post_id);
        }
        
        // Log the deletion
        self::log_deletion($post_id, 'deleted', $media_count);
        
        do_action('ultimate_media_deletion_after_delete', $post_id);
    }

    /**
     * Handle trashed posts (if configured to delete media on trash)
     */
    public function handle_trashed_post($post_id) {
        if (get_option('umd_delete_on_trash', false)) {
            $this->delete_associated_media($post_id);
        }
    }

    /**
     * Delete standard attachments
     */
    private function delete_attachments($post_id) {
        $attachments = Helpers::get_post_attachment_ids($post_id);
        
        foreach ($attachments as $attachment_id) {
            if (!self::is_media_used_elsewhere($attachment_id, $post_id)) {
                wp_delete_attachment($attachment_id, true);
            } else {
                self::log_skipped_media($post_id, $attachment_id, 'attachment_in_use');
            }
        }
    }

    /**
     * Delete ACF media fields
     */
    private function delete_acf_media($post_id) {
        if (!function_exists('get_fields')) {
            return;
        }

        $fields = get_fields($post_id);
        if (empty($fields)) {
            return;
        }

        array_walk_recursive($fields, function($value) use ($post_id) {
            if (is_numeric($value)) {
                $this->delete_media_if_unused($value, $post_id, 'acf_field');
            } elseif (is_array($value) && isset($value['ID'])) {
                $this->delete_media_if_unused($value['ID'], $post_id, 'acf_field');
            } elseif (is_string($value) && Helpers::is_image_url($value)) {
                $attachment_id = attachment_url_to_postid($value);
                if ($attachment_id) {
                    $this->delete_media_if_unused($attachment_id, $post_id, 'acf_embedded');
                } else {
                    Helpers::delete_file_by_url($value);
                }
            }
        });
    }

    /**
     * Delete embedded media from content
     */
    private function delete_embedded_media($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $content_sources = [$post->post_content, $post->post_excerpt];
        
        if (function_exists('get_fields')) {
            foreach (get_fields($post_id) as $field_value) {
                if (is_string($field_value)) {
                    $content_sources[] = $field_value;
                }
            }
        }

        foreach ($content_sources as $content) {
            if (empty($content)) {
                continue;
            }
            
            // Match img tags
            preg_match_all('/<img[^>]+src=([\'"])(?<src>.+?)\1/', $content, $matches);
            foreach ($matches['src'] ?? [] as $image_url) {
                $this->delete_media_by_url($image_url, $post_id);
            }
            
            // Match WordPress image shortcodes
            preg_match_all('/\[(?:gallery|image|wp_image)[^\]]*\]/', $content, $shortcodes);
            foreach ($shortcodes[0] ?? [] as $shortcode) {
                if (preg_match('/ids=["\']([^"\']+)["\']/', $shortcode, $ids_match)) {
                    $ids = explode(',', $ids_match[1]);
                    foreach ($ids as $id) {
                        $this->delete_media_if_unused((int)$id, $post_id, 'shortcode');
                    }
                }
            }
        }
    }

    /**
     * Delete media by URL with fallback to direct file deletion
     */
    private function delete_media_by_url($url, $post_id) {
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            $this->delete_media_if_unused($attachment_id, $post_id, 'embedded');
        } else {
            Helpers::delete_file_by_url($url);
        }
    }

    /**
     * Delete media if not used elsewhere
     */
    private function delete_media_if_unused($attachment_id, $post_id, $context) {
        if (!self::is_media_used_elsewhere($attachment_id, $post_id)) {
            wp_delete_attachment($attachment_id, true);
        } else {
            self::log_skipped_media($post_id, $attachment_id, $context.'_in_use');
        }
    }

    /**
     * Check if media is used outside the current post with comprehensive checks
     */
    public static function is_media_used_elsewhere($attachment_id, $excluding_post_id) {
        global $wpdb;
        
        $attachment_url = wp_get_attachment_url($attachment_id);
        if (!$attachment_url) {
            return false;
        }

        // Check as featured image in other posts (excluding revisions/inherited posts)
        $as_featured = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_thumbnail_id' 
             AND meta_value = %d 
             AND post_id != %d
             AND post_id IN (
                 SELECT ID FROM {$wpdb->posts}
                 WHERE post_type NOT IN ('revision', 'attachment')
                 AND post_status NOT IN ('inherit', 'auto-draft')
             )
             LIMIT 1",
            $attachment_id,
            $excluding_post_id
        ));
        
        if ($as_featured) {
            return true;
        }

        // Check in content of other posts (excluding revisions/inherited posts)
        $filename = wp_basename($attachment_url);
        $in_content = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE (post_content LIKE %s OR post_content LIKE %s)
             AND ID != %d
             AND post_type NOT IN ('revision', 'attachment')
             AND post_status NOT IN ('inherit', 'auto-draft')
             LIMIT 1",
            '%' . $wpdb->esc_like($attachment_url) . '%',
            '%' . $wpdb->esc_like($filename) . '%',
            $excluding_post_id
        ));
        
        if ($in_content) {
            return true;
        }

        // Check in ACF fields of other posts (excluding hidden meta and revisions)
        if (function_exists('acf_get_field_groups')) {
            $in_acf = $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id 
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE (pm.meta_value = %d OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s)
                 AND pm.post_id != %d
                 AND pm.meta_key NOT LIKE '\_%'
                 AND p.post_type NOT IN ('revision', 'attachment')
                 AND p.post_status NOT IN ('inherit', 'auto-draft')
                 LIMIT 1",
                $attachment_id,
                '%' . $wpdb->esc_like('"' . $attachment_id . '"') . '%',
                '%' . $wpdb->esc_like($attachment_url) . '%',
                $excluding_post_id
            ));
            
            if ($in_acf) {
                return true;
            }
        }

        // Check in term meta (for category/tag images)
        $in_term_meta = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta}
             WHERE meta_key IN ('thumbnail_id', 'image')
             AND meta_value = %d
             LIMIT 1",
            $attachment_id
        ));
        
        if ($in_term_meta) {
            return true;
        }

        // Check in options (site logo, header image, etc.)
        $in_options = $wpdb->get_var($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_value = %d
             AND option_name NOT LIKE '%transient%'
             LIMIT 1",
            $attachment_id
        ));
        
        if ($in_options) {
            return true;
        }

        return false;
    }

    /**
     * Clean up orphaned postmeta
     */
    public function clean_orphaned_postmeta() {
        global $wpdb;
        
        $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.ID IS NULL"
        );
    }

    /**
     * Clean up attachment files when deleted
     */
    public function cleanup_attachment_files($attachment_id, $force_delete) {
        if ($force_delete) {
            $file = get_attached_file($attachment_id);
            if ($file && file_exists($file)) {
                Helpers::delete_file_and_variations($file);
            }
        }
    }

    /**
     * Clean up post revisions when parent is deleted
     */
    public function clean_post_revisions($post_id) {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'])) {
            return;
        }

        $revisions = wp_get_post_revisions($post_id);
        foreach ($revisions as $revision) {
            wp_delete_post($revision->ID, true);
        }
    }

    /**
     * Log media deletions
     */
    public static function log_deletion($post_id, $action = 'deleted', $media_count = null) {
        global $wpdb;
        
        if (is_null($media_count)) {
            $media_count = Helpers::count_post_media($post_id);
        }
        
        $wpdb->insert($wpdb->prefix . 'umd_logs', [
            'post_id' => $post_id,
            'user_id' => get_current_user_id(),
            'media_count' => $media_count,
            'details' => maybe_serialize([
                'post_title' => get_the_title($post_id),
                'post_type' => get_post_type($post_id),
                'action' => $action
            ]),
            'created_at' => current_time('mysql')
        ]);
    }

    /**
     * Log skipped media deletions
     */
    public static function log_skipped_media($post_id, $attachment_id, $reason) {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'umd_logs', [
            'post_id' => $post_id,
            'user_id' => get_current_user_id(),
            'media_count' => 0,
            'details' => maybe_serialize([
                'attachment_id' => $attachment_id,
                'action' => 'skipped',
                'reason' => $reason,
                'post_title' => get_the_title($post_id),
                'post_type' => get_post_type($post_id),
                'attachment_url' => wp_get_attachment_url($attachment_id)
            ]),
            'created_at' => current_time('mysql')
        ]);
    }

    /**
     * Daily maintenance cleanup
     */
    public function daily_cleanup() {
        global $wpdb;
        
        // Clean up logs older than 30 days
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}umd_logs 
             WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        );
        
        // Clean up orphaned metadata
        $this->clean_orphaned_postmeta();
        
        // Clear any cached data
        wp_cache_flush();
    }

    /**
     * Handle version upgrades
     */
    public function version_check() {
        $current_version = get_option('umd_version', '1.0.0');
        
        if (version_compare($current_version, UMD_VERSION, '<')) {
            $this->run_upgrades($current_version);
            update_option('umd_version', UMD_VERSION);
        }
    }

    /**
     * Run version-specific upgrades
     */
    private function run_upgrades($from_version) {
        // Create tables if needed
        if (version_compare($from_version, '2.0.0', '<')) {
            $this->create_tables();
        }
        
        // Future upgrade paths can be added here
        // if (version_compare($from_version, '2.1.0', '<')) {
        //     $this->migrate_to_new_feature();
        // }
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'umd_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            media_count int(11) NOT NULL DEFAULT 0,
            details longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Plugin activation handler
     */
    public static function activate($network_wide = false) {
        if (is_multisite() && $network_wide) {
            self::network_activate();
        } else {
            self::single_activate();
        }
        
        update_option('umd_version', UMD_VERSION);
    }

    /**
     * Network activation
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
        $core = new self();
        $core->create_tables();
        
        // Add capabilities
        foreach (['administrator', 'editor'] as $role_name) {
            if ($role = get_role($role_name)) {
                $role->add_cap('delete_with_media');
                $role->add_cap('view_media_deletion_logs');
            }
        }
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('umd_daily_cleanup');
    }

    /**
     * Plugin uninstallation
     */
    public static function uninstall() {
        global $wpdb;
        
        // Remove all plugin options
        delete_option('umd_version');
        delete_option('umd_settings');
        delete_option('umd_delete_on_trash');
        
        // Check user preference for logs
        $keep_logs = get_option('umd_keep_logs_on_uninstall', 'no');
        
        if ($keep_logs === 'no') {
            // Delete from options table
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_umd_logs%'");
            
            // Delete from custom logs table
            $table_name = $wpdb->prefix . 'umd_logs';
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
                $wpdb->query("DROP TABLE IF EXISTS $table_name");
            }
            
            // Delete any transients
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_umd_%'");
            $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_umd_%'");
        }
        
        // Clean up our temporary option
        delete_option('umd_keep_logs_on_uninstall');
        
        // Remove scheduled events
        wp_clear_scheduled_hook('umd_daily_cleanup');
        
        // Remove capabilities
        foreach (['administrator', 'editor'] as $role_name) {
            if ($role = get_role($role_name)) {
                $role->remove_cap('delete_with_media');
                $role->remove_cap('view_media_deletion_logs');
            }
        }
        
        // Allow other cleanup through action
        do_action('ultimate_media_deletion_uninstall', $keep_logs);
        
        // Clear any cached data
        wp_cache_flush();
    }
}