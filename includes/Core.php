<?php
namespace UltimateMediaDeletion;

defined('ABSPATH') || exit;

class Core {
    /**
     * Initialize plugin core functionality
     */
    public function __construct() {
        // Register cleanup hooks early
        add_action('init', [$this, 'register_cleanup_hooks']);
        
        // Media deletion hooks
        add_action('before_delete_post', [$this, 'delete_all_post_media']);

        // Enhanced bulk actions
        add_filter('bulk_actions-edit-post', [$this, 'register_bulk_actions']);
        add_filter('bulk_actions-edit-page', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_action'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_admin_notice']);
        
        // Add bulk actions for custom post types that support attachments
        add_action('admin_init', [$this, 'register_bulk_actions_for_custom_types']);
        
        // Scheduled tasks
        add_action('umd_daily_cleanup', [__CLASS__, 'daily_cleanup']);
        
        // Admin UI
        add_action('admin_notices', [__CLASS__, 'show_activation_notice']);
        
        // Version check
        add_action('init', [$this, 'version_check']);
    }

    /**
     * Register bulk actions for custom post types
     */
    public function register_bulk_actions_for_custom_types() {
        $post_types = get_post_types([
            'show_ui' => true,
            '_builtin' => false
        ], 'objects');
        
        foreach ($post_types as $post_type) {
            if (post_type_supports($post_type->name, 'thumbnail') || post_type_supports($post_type->name, 'editor')) {
                add_filter("bulk_actions-edit-{$post_type->name}", [$this, 'register_bulk_actions']);
                add_filter("handle_bulk_actions-edit-{$post_type->name}", [$this, 'handle_bulk_action'], 10, 3);
            }
        }
    }

    /**
     * Handle bulk action with improved permission checks
     */
    public function handle_bulk_action($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'delete_with_media') {
            return $redirect_to;
        }
        
        $deleted_count = 0;
        $skipped_count = 0;
        $skipped_details = [];
        
        // Check if user has bulk delete capability
        if (!current_user_can('delete_posts')) {
            wp_die(__('You do not have permission to bulk delete posts.', 'ultimate-media-deletion'));
        }
        
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $post_type = get_post_type($post_id);
            
            // Check post-specific capabilities
            if (!current_user_can('delete_post', $post_id)) {
                $skipped_details[] = [
                    'id' => $post_id,
                    'title' => get_the_title($post_id),
                    'reason' => 'permission_denied'
                ];
                $skipped_count++;
                continue;
            }
            
            // Check if post exists and isn't already deleted
            $post = get_post($post_id);
            if (!$post || $post->post_status === 'trash') {
                $skipped_details[] = [
                    'id' => $post_id,
                    'title' => get_the_title($post_id),
                    'reason' => 'already_deleted'
                ];
                $skipped_count++;
                continue;
            }
            
            // Force delete the post (bypass trash) which will trigger before_delete_post
            $result = wp_delete_post($post_id, true);
            
            if ($result) {
                $deleted_count++;
                
                // Ensure media is deleted (extra safeguard)
                $this->delete_all_post_media($post_id);
            } else {
                $skipped_details[] = [
                    'id' => $post_id,
                    'title' => get_the_title($post_id),
                    'reason' => 'deletion_failed'
                ];
                $skipped_count++;
            }
        }
        
        // Store results in transient for the notice
        set_transient('umd_bulk_delete_results', [
            'deleted' => $deleted_count,
            'skipped' => $skipped_count,
            'skipped_details' => $skipped_details,
            'post_type' => !empty($post) ? $post->post_type : 'post'
        ], 30);
        
        return remove_query_arg(['umd_bulk_deleted', 'umd_bulk_skipped'], $redirect_to);
    }

    /**
     * Show detailed bulk action notice
     */
    public function bulk_action_admin_notice() {
        $results = get_transient('umd_bulk_delete_results');
        if (!$results) return;
        
        delete_transient('umd_bulk_delete_results');
        
        $deleted = $results['deleted'];
        $skipped = $results['skipped'];
        $post_type = $results['post_type'];
        $skipped_details = $results['skipped_details'];
        
        if ($deleted > 0) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . sprintf(
                _n(
                    '%d %s deleted permanently with all associated media.',
                    '%d %s deleted permanently with all associated media.',
                    $deleted,
                    'ultimate-media-deletion'
                ),
                $deleted,
                get_post_type_object($post_type)->labels->singular_name
            ) . '</p>';
            
            if ($skipped > 0) {
                echo '<p>' . sprintf(
                    _n(
                        '%d %s could not be deleted.',
                        '%d %s could not be deleted.',
                        $skipped,
                        'ultimate-media-deletion'
                    ),
                    $skipped,
                    get_post_type_object($post_type)->labels->singular_name
                ) . '</p>';
                
                // Show detailed reasons in debug mode or for admins
                if (defined('WP_DEBUG') && WP_DEBUG || current_user_can('manage_options')) {
                    echo '<ul style="margin-left:20px;list-style:disc;">';
                    foreach ($skipped_details as $detail) {
                        $reason = $this->get_skip_reason_message($detail['reason']);
                        echo '<li>' . sprintf(
                            __('%s (ID: %d) - %s', 'ultimate-media-deletion'),
                            esc_html($detail['title']),
                            $detail['id'],
                            $reason
                        ) . '</li>';
                    }
                    echo '</ul>';
                }
            }
            
            echo '</div>';
        } elseif ($skipped > 0) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . sprintf(
                __('No %s were deleted. %d %s could not be deleted.', 'ultimate-media-deletion'),
                get_post_type_object($post_type)->labels->name,
                $skipped,
                $skipped === 1 ? 
                    get_post_type_object($post_type)->labels->singular_name :
                    get_post_type_object($post_type)->labels->name
            ) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Get user-friendly skip reason message
     */
    private function get_skip_reason_message($reason_code) {
        $messages = [
            'permission_denied' => __('Permission denied', 'ultimate-media-deletion'),
            'already_deleted' => __('Already deleted', 'ultimate-media-deletion'),
            'deletion_failed' => __('Deletion failed', 'ultimate-media-deletion'),
            'media_in_use' => __('Media in use elsewhere', 'ultimate-media-deletion')
        ];
        
        return $messages[$reason_code] ?? __('Unknown reason', 'ultimate-media-deletion');
    }


    /**
     * Register cleanup hooks
     */
    public function register_cleanup_hooks() {
        // Clean revisions when parent post is deleted
        add_action('before_delete_post', [$this, 'clean_post_revisions']);
        
        // Clean orphaned metadata after post deletion
        add_action('deleted_post', [$this, 'clean_orphaned_postmeta']);
    }

    /**
     * Handle post media deletion with enhanced safety checks
     */
    public function delete_all_post_media($post_id) {
        if (!is_admin() || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Process all media types with usage checks
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
     * Delete standard attachments with usage verification
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
            if (!$this->is_media_used_elsewhere($attachment_id, $post_id)) {
                wp_delete_attachment($attachment_id, true);
            } else {
                self::log_skipped_media($post_id, $attachment_id, 'standard_attachment_in_use');
            }
        }
    }

    /**
     * Clean revisions when parent post is deleted
     */
    public function clean_post_revisions($post_id) {
        $post = get_post($post_id);
        
        // Only clean revisions for non-published posts
        if ($post && !in_array($post->post_status, ['publish', 'draft', 'auto-draft'])) {
            $revisions = wp_get_post_revisions($post_id);
            foreach ($revisions as $revision) {
                wp_delete_post($revision->ID, true);
            }
        }
    }

    /**
     * Clean orphaned postmeta after post deletion
     */
    public function clean_orphaned_postmeta($deleted_post_id) {
        global $wpdb;
        
        $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.ID IS NULL"
        );
    }

    /**
     * Enhanced media usage checker
     */
    private function is_media_used_elsewhere($attachment_id, $excluding_post_id) {
        global $wpdb;

        $attachment_url = wp_get_attachment_url($attachment_id);
        if (!$attachment_url) return false;

        // Check as featured image (excluding revisions/inherited posts)
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
        
        if ($as_featured) return true;

        // Check in post content (both URL and filename)
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

        if ($in_content) return true;

        // Check in ACF fields (excluding hidden meta and revisions)
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

            if ($in_acf) return true;
        }

        return false;
    }
  
    /**
     * Recursively delete ACF media fields with orphan check
     */
    private function delete_acf_media_recursive($post_id) {
        $fields = get_fields($post_id);
        if (empty($fields)) return;

        array_walk_recursive($fields, function($value) use ($post_id) {
            if (is_numeric($value)) {
                if (!$this->is_media_used_elsewhere((int)$value, $post_id)) {
                    wp_delete_attachment((int)$value, true);
                } else {
                    self::log_skipped_media($post_id, (int)$value, 'acf_media_in_use');
                }
            } elseif (is_array($value) && isset($value['ID'])) {
                if (!$this->is_media_used_elsewhere($value['ID'], $post_id)) {
                    wp_delete_attachment($value['ID'], true);
                } else {
                    self::log_skipped_media($post_id, $value['ID'], 'acf_media_in_use');
                }
            } elseif (is_string($value) && Helpers::is_image_url($value)) {
                $attachment_id = attachment_url_to_postid($value);
                if ($attachment_id && !$this->is_media_used_elsewhere($attachment_id, $post_id)) {
                    wp_delete_attachment($attachment_id, true);
                } elseif ($attachment_id) {
                    self::log_skipped_media($post_id, $attachment_id, 'acf_embedded_media_in_use');
                }
            }
        });
    }

    /**
     * Delete images from HTML content with orphan check
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
                $attachment_id = attachment_url_to_postid($image_url);
                if ($attachment_id && !$this->is_media_used_elsewhere($attachment_id, $post_id)) {
                    wp_delete_attachment($attachment_id, true);
                } elseif ($attachment_id) {
                    self::log_skipped_media($post_id, $attachment_id, 'embedded_media_in_use');
                }
            }
        }
    }

    /**
     * Log media usage check results for debugging
     */
    private function log_media_check($attachment_id, $excluding_post_id, $featured_count, $content_count, $acf_count) {
        error_log(sprintf(
            'Media Check - Attachment: %d, Excluding: %d, Featured: %d, Content: %d, ACF: %d',
            $attachment_id,
            $excluding_post_id,
            $featured_count,
            $content_count,
            $acf_count
        ));
    }


    /**
     * Log skipped media deletions
     */
    private static function log_skipped_media($post_id, $attachment_id, $reason) {
        global $wpdb;
        
        $wpdb->insert($wpdb->prefix . 'umd_logs', [
            'post_id' => $post_id,
            'user_id' => get_current_user_id(),
            'media_count' => 0, // 0 indicates skipped
            'details' => maybe_serialize([
                'attachment_id' => $attachment_id,
                'action' => 'skipped',
                'reason' => $reason,
                'post_title' => get_the_title($post_id),
                'post_type' => get_post_type($post_id),
                'attachment_url' => wp_get_attachment_url($attachment_id)
            ])
        ]);
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

    /**
     * Plugin uninstallation handler
     */
    public static function uninstall() {
        global $wpdb;
        
        // Always delete these
        delete_option('ultimate_media_deletion_settings');
        delete_option('umd_version');
        wp_clear_scheduled_hook('umd_daily_cleanup');
        
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
        
        // Remove capabilities
        foreach (['administrator', 'editor'] as $role_name) {
            if ($role = get_role($role_name)) {
                $role->remove_cap('delete_with_media');
                $role->remove_cap('view_media_deletion_logs');
            }
        }
        
        // Allow other cleanup through action
        do_action('ultimate_media_deletion_uninstall', $keep_logs);

        // Set transient for success message
        // set_transient('umd_uninstall_success', '1', 30);
        
        // Return to plugins page
        // wp_redirect(admin_url('plugins.php'));
        // Add this at the end before the redirect:
        // wp_redirect(admin_url('plugins.php?umd_uninstalled=1'));
        // exit;
    }
}