<?php
namespace UltimateMediaDeletion;

defined('ABSPATH') || exit;

class Admin {
    /**
     * Initialize admin functionality
     */
    public function __construct() {
        // Notices and UI
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer', [$this, 'single_delete_confirmation']);
        
        // Bulk actions
        add_filter('bulk_actions-edit-post', [$this, 'register_bulk_actions']);
        add_filter('bulk_actions-edit-page', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_actions'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_actions'], 10, 3);
        
        // Settings
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Show all admin notices
     */
    public function show_admin_notices() {
        $this->media_deletion_warning();
        $this->bulk_action_notice();
    }

    /**
     * Show media deletion warning for single post
     */
    private function media_deletion_warning() {
        global $post;

        if (!isset($_GET['action']) || 'delete' !== $_GET['action'] || !$post) {
            return;
        }

        $media_count = Helpers::count_post_media($post->ID);
        if ($media_count > 0) {
            echo '<div class="notice notice-warning"><p>',
                sprintf(
                    _n(
                        'Warning: This will permanently delete %d attached media item.',
                        'Warning: This will permanently delete %d attached media items.', 
                        $media_count,
                        'ultimate-media-deletion'
                    ),
                    $media_count
                ),
            '</p></div>';
        }
    }

    /**
     * Show bulk action results notice
     */
    private function bulk_action_notice() {
        if (!empty($_REQUEST['bulk_action']) && 'delete_with_media' === $_REQUEST['bulk_action']) {
            $count = intval($_REQUEST['deleted_with_media'] ?? 0);
            
            echo '<div class="notice notice-success is-dismissible"><p>',
                sprintf(
                    _n(
                        'Deleted %d post with all attached media.',
                        'Deleted %d posts with all attached media.',
                        $count,
                        'ultimate-media-deletion'
                    ),
                    $count
                ),
            '</p></div>';
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if ('edit.php' !== $hook && strpos($hook, 'ultimate-media-deletion') === false) {
            return;
        }
        
        wp_enqueue_script(
            'umd-admin-js',
            UMD_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-util'],
            UMD_VERSION,
            true
        );
        
        wp_enqueue_style(
            'umd-admin-css',
            UMD_PLUGIN_URL . 'assets/css/admin.css',
            [],
            UMD_VERSION
        );
        
        wp_localize_script('umd-admin-js', 'umd_admin', [
            'confirm_delete' => __('Are you sure you want to delete this media?', 'ultimate-media-deletion'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('umd_admin_nonce')
        ]);
    }

    /**
     * Register bulk actions
     */
    public function register_bulk_actions($bulk_actions) {
        if (current_user_can('delete_with_media')) {
            $bulk_actions['delete_with_media'] = __('Delete with media', 'ultimate-media-deletion');
        }
        return $bulk_actions;
    }

    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ('delete_with_media' !== $doaction || !current_user_can('delete_with_media')) {
            return $redirect_to;
        }

        $deleted = 0;
        $core = new Core();
        
        foreach ($post_ids as $post_id) {
            $core->delete_all_post_media($post_id);
            if (wp_delete_post($post_id, true)) {
                $deleted++;
            }
        }

        return add_query_arg([
            'deleted_with_media' => $deleted,
            'bulk_action' => 'delete_with_media'
        ], remove_query_arg(['deleted', 'ids'], $redirect_to));
    }

    /**
     * Add confirmation dialog for single post deletion
     */
    public function single_delete_confirmation() {
        global $post;
        
        if (!isset($_GET['action']) || 'delete' !== $_GET['action'] || !$post) {
            return;
        }

        $media_count = Helpers::count_post_media($post->ID);
        if ($media_count > 0) {
            ?>
            <script>
            jQuery(document).ready(function($) {
                $('form#delete-post').on('submit', function(e) {
                    return confirm('<?php 
                        printf(
                            _n(
                                'This will delete %d media item. Are you sure?',
                                'This will delete %d media items. Are you sure?',
                                $media_count,
                                'ultimate-media-deletion'
                            ),
                            $media_count
                        );
                    ?>');
                });
            });
            </script>
            <?php
        }
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Media Deletion', 'ultimate-media-deletion'),
            __('Media Deletion', 'ultimate-media-deletion'),
            'manage_options',
            'ultimate-media-deletion',
            [$this, 'render_settings_page'],
            'dashicons-trash',
            80
        );
        
        add_submenu_page(
            'ultimate-media-deletion',
            __('Settings', 'ultimate-media-deletion'),
            __('Settings', 'ultimate-media-deletion'),
            'manage_options',
            'ultimate-media-deletion-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ultimate-media-deletion'));
        }
        
        include UMD_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('umd_settings_group', 'umd_settings');
        
        add_settings_section(
            'umd_general_section',
            __('General Settings', 'ultimate-media-deletion'),
            [$this, 'render_general_section'],
            'ultimate-media-deletion'
        );
        
        add_settings_field(
            'delete_acf_media',
            __('Delete ACF Media', 'ultimate-media-deletion'),
            [$this, 'render_checkbox_field'],
            'ultimate-media-deletion',
            'umd_general_section',
            [
                'name' => 'delete_acf_media',
                'label' => __('Delete media referenced in ACF fields', 'ultimate-media-deletion')
            ]
        );
        
        add_settings_field(
            'delete_embedded',
            __('Delete Embedded Media', 'ultimate-media-deletion'),
            [$this, 'render_checkbox_field'],
            'ultimate-media-deletion',
            'umd_general_section',
            [
                'name' => 'delete_embedded',
                'label' => __('Delete media embedded in content', 'ultimate-media-deletion')
            ]
        );
    }

    /**
     * Render settings section
     */
    public function render_general_section() {
        echo '<p>', __('Configure how media deletion should be handled.', 'ultimate-media-deletion'), '</p>';
    }

    /**
     * Render checkbox field
     */
    public function render_checkbox_field($args) {
        $options = get_option('umd_settings');
        $checked = isset($options[$args['name']]) ? checked(1, $options[$args['name']], false) : '';
        
        echo '<label><input type="checkbox" name="umd_settings[', esc_attr($args['name']), ']" value="1" ', $checked, '> ',
            esc_html($args['label']),
        '</label>';
    }
}