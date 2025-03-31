<?php
namespace UltimateMediaDeletion;

defined('ABSPATH') || exit;

class Admin {
    /**
     * Initialize admin functionality
     */
    public function __construct() {
        $this->init_notices();
        $this->init_assets();
        $this->init_bulk_actions();
        $this->init_settings();
        $this->init_uninstall();
        
        add_action('wp_ajax_umd_process_uninstall', [$this, 'ajax_process_uninstall']);
        add_action('admin_init', [$this, 'register_post_type_support']);
    }

    /**
     * Initialize admin notices
     */
    private function init_notices() {
        add_action('admin_notices', [$this, 'show_admin_notices']);
    }

    /**
     * Initialize assets
     */
    private function init_assets() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_footer', [$this, 'single_delete_confirmation']);
    }

    /**
     * Initialize bulk actions
     */
    private function init_bulk_actions() {
        add_action('admin_init', [$this, 'register_bulk_actions_for_all_types']);
    }

    /**
     * Initialize settings
     */
    private function init_settings() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Initialize uninstall functionality
     */
    private function init_uninstall() {
        add_action('admin_init', [$this, 'handle_uninstall_request']);
        add_filter('plugin_action_links_' . UMD_PLUGIN_BASENAME, [$this, 'add_plugin_action_links']);
    }

    /**
     * Register bulk actions for all supported post types
     */
    public function register_bulk_actions_for_all_types() {
        $post_types = get_post_types(['show_ui' => true], 'objects');
        
        foreach ($post_types as $post_type) {
            if (post_type_supports($post_type->name, 'thumbnail') || post_type_supports($post_type->name, 'editor')) {
                add_filter("bulk_actions-edit-{$post_type->name}", [$this, 'register_bulk_actions']);
                add_filter("handle_bulk_actions-edit-{$post_type->name}", [$this, 'handle_bulk_actions'], 10, 3);
            }
        }
    }

    /**
     * Show all admin notices
     */
    public function show_admin_notices() {
        $this->media_deletion_warning();
        $this->bulk_action_notice();
        $this->show_uninstall_notice();
    }

    /**
     * Show media deletion warning for single post
     */
    private function media_deletion_warning() {
        global $post;

        if (!isset($_GET['action']) || !in_array($_GET['action'], ['delete', 'trash']) || !$post) {
            return;
        }

        $media_count = Helpers::count_post_media($post->ID);
        if ($media_count > 0) {
            $message = $_GET['action'] === 'trash' 
                ? __('Warning: Trashing this will delete %d attached media items.', 'ultimate-media-deletion')
                : __('Warning: This will permanently delete %d attached media items.', 'ultimate-media-deletion');
            
            echo '<div class="notice notice-warning"><p>',
                sprintf(_n($message, str_replace('%d', '%d', $message), $media_count),
            '</p></div>';
        }
    }

    /**
     * Show bulk action results notice
     */
    private function bulk_action_notice() {
        if (!empty($_REQUEST['bulk_action']) && $_REQUEST['bulk_action'] === 'delete_with_media') {
            $count = intval($_REQUEST['deleted_with_media'] ?? 0);
            
            echo '<div class="notice notice-success is-dismissible"><p>',
                sprintf(
                    _n(
                        'Deleted %d item with all attached media.',
                        'Deleted %d items with all attached media.',
                        $count,
                        'ultimate-media-deletion'
                    ),
                    $count
                ),
            '</p></div>';
        }
    }

    /**
     * Show uninstall success notice
     */
    private function show_uninstall_notice() {
        if (!empty($_GET['umd_uninstalled'])) {
            echo '<div class="notice notice-success is-dismissible"><p>',
                __('Ultimate Media Deletion was successfully uninstalled.', 'ultimate-media-deletion'),
            '</p></div>';
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        // Load on all admin pages where needed
        if (
            'edit.php' === $hook || 
            strpos($hook, 'ultimate-media-deletion') !== false ||
            'plugins.php' === $hook
        ) {
            $this->enqueue_core_assets();
        }
        
        // Load uninstall dialog only when needed
        if ('plugins.php' === $hook && isset($_GET['keep_logs_prompt'])) {
            $this->enqueue_uninstall_assets();
        }
    }

    /**
     * Enqueue core admin assets
     */
    private function enqueue_core_assets() {
        wp_enqueue_style(
            'umd-admin-css',
            UMD_PLUGIN_URL . 'assets/css/admin.css',
            [],
            UMD_VERSION
        );

        wp_enqueue_script(
            'umd-admin-js',
            UMD_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'wp-util'],
            UMD_VERSION,
            true
        );

        wp_localize_script('umd-admin-js', 'umd_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('umd_admin_nonce'),
            'i18n' => [
                'confirm_delete' => __('Are you sure you want to delete this media?', 'ultimate-media-deletion'),
                'processing' => __('Processing...', 'ultimate-media-deletion')
            ]
        ]);
    }

    /**
     * Enqueue uninstall dialog assets
     */
    private function enqueue_uninstall_assets() {
        wp_enqueue_style(
            'umd-uninstall-dialog',
            UMD_PLUGIN_URL . 'assets/css/uninstall-dialog.css',
            ['wp-jquery-ui-dialog'],
            UMD_VERSION
        );

        wp_enqueue_script(
            'umd-uninstall-dialog',
            UMD_PLUGIN_URL . 'assets/js/uninstall-dialog.js',
            ['jquery', 'jquery-ui-dialog', 'wp-util'],
            UMD_VERSION,
            true
        );

        wp_localize_script('umd-uninstall-dialog', 'umdUninstallData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('umd_uninstall_nonce'),
            'dialog_title' => __('Ultimate Media Deletion Uninstall', 'ultimate-media-deletion'),
            'keep_logs_text' => __('Keep Logs', 'ultimate-media-deletion'),
            'delete_all_text' => __('Delete All Data', 'ultimate-media-deletion'),
            'delete_confirm' => __('This will permanently delete ALL logs and cannot be undone. Continue?', 'ultimate-media-deletion')
        ]);
    }

    /**
     * Register bulk actions
     */
    public function register_bulk_actions($bulk_actions) {
        if (current_user_can('delete_posts')) {
            $bulk_actions['delete_with_media'] = __('Delete Permanently with Media', 'ultimate-media-deletion');
        }
        return $bulk_actions;
    }

    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ('delete_with_media' !== $doaction || !current_user_can('delete_posts')) {
            return $redirect_to;
        }

        $deleted = 0;
        
        foreach ($post_ids as $post_id) {
            if (current_user_can('delete_post', $post_id) && wp_delete_post($post_id, true)) {
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
        
        $current_screen = get_current_screen();
        if (!$current_screen || !in_array($current_screen->base, ['edit', 'post'])) {
            return;
        }

        $post_id = isset($_GET['post']) ? $_GET['post'] : ($post->ID ?? 0);
        $action = $_GET['action'] ?? '';

        if (!in_array($action, ['delete', 'trash']) {
            return;
        }

        $media_count = Helpers::count_post_media($post_id);
        if ($media_count > 0) {
            $message = $action === 'trash' 
                ? __('Trashing this will delete %d media items. Continue?', 'ultimate-media-deletion')
                : __('This will permanently delete %d media items. Continue?', 'ultimate-media-deletion');
            ?>
            <script>
            jQuery(document).ready(function($) {
                $('form#delete-post, form#posts-filter').on('submit', function(e) {
                    if ($('select[name="action"]').val() === 'trash' || 
                        $('select[name="action2"]').val() === 'trash' ||
                        window.location.href.indexOf('action=trash') > -1) {
                        return confirm('<?php printf(_n($message, str_replace('%d', '%d', $message), $media_count); ?>');
                    }
                    return true;
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
        $menu_slug = 'ultimate-media-deletion';
        
        add_menu_page(
            __('Media Deletion', 'ultimate-media-deletion'),
            __('Media Deletion', 'ultimate-media-deletion'),
            'manage_options',
            $menu_slug,
            [$this, 'render_settings_page'],
            'dashicons-trash',
            80
        );
        
        add_submenu_page(
            $menu_slug,
            __('Settings', 'ultimate-media-deletion'),
            __('Settings', 'ultimate-media-deletion'),
            'manage_options',
            $menu_slug,
            [$this, 'render_settings_page']
        );
        
        add_submenu_page(
            $menu_slug,
            __('Logs', 'ultimate-media-deletion'),
            __('Logs', 'ultimate-media-deletion'),
            'view_media_deletion_logs',
            'ultimate-media-deletion-logs',
            [$this, 'render_logs_page']
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
     * Render logs page
     */
    public function render_logs_page() {
        if (!current_user_can('view_media_deletion_logs')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'ultimate-media-deletion'));
        }
        
        include UMD_PLUGIN_DIR . 'templates/admin/logs.php';
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
        
        $this->add_settings_field(
            'delete_on_trash',
            __('Delete Media on Trash', 'ultimate-media-deletion'),
            __('Delete associated media when posts are trashed (not just when permanently deleted)', 'ultimate-media-deletion'),
            'checkbox'
        );
        
        $this->add_settings_field(
            'delete_acf_media',
            __('Delete ACF Media', 'ultimate-media-deletion'),
            __('Delete media referenced in ACF fields', 'ultimate-media-deletion'),
            'checkbox'
        );
        
        $this->add_settings_field(
            'delete_embedded',
            __('Delete Embedded Media', 'ultimate-media-deletion'),
            __('Delete media embedded in content', 'ultimate-media-deletion'),
            'checkbox'
        );
    }

    /**
     * Add settings field helper
     */
    private function add_settings_field($name, $title, $description, $type = 'checkbox') {
        add_settings_field(
            $name,
            $title,
            [$this, 'render_settings_field'],
            'ultimate-media-deletion',
            'umd_general_section',
            [
                'name' => $name,
                'label' => $description,
                'type' => $type
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
     * Render settings field
     */
    public function render_settings_field($args) {
        $options = get_option('umd_settings');
        $value = $options[$args['name']] ?? '';
        
        switch ($args['type']) {
            case 'checkbox':
                echo '<label><input type="checkbox" name="umd_settings[' . esc_attr($args['name']) . ']" value="1" ' . checked(1, $value, false) . '> ',
                    esc_html($args['label']),
                '</label>';
                break;
                
            // Add other field types as needed
        }
    }

    /**
     * Add uninstall link to plugin actions
     */
    public function add_plugin_action_links($actions) {
        if (current_user_can('delete_plugins')) {
            $actions['uninstall'] = sprintf(
                '<a href="%s" aria-label="%s" style="color:#a00;">%s</a>',
                wp_nonce_url(
                    admin_url('plugins.php?umd_uninstall=1&keep_logs_prompt=1'),
                    'umd_uninstall_nonce'
                ),
                esc_attr__('Uninstall Ultimate Media Deletion', 'ultimate-media-deletion'),
                esc_html__('Uninstall', 'ultimate-media-deletion')
            );
        }
        return $actions;
    }

    /**
     * Handle uninstall requests
     */
    public function handle_uninstall_request() {
        if (isset($_GET['umd_uninstall']) && current_user_can('delete_plugins')) {
            check_admin_referer('umd_uninstall_nonce');
            
            if (wp_doing_ajax()) {
                $this->ajax_process_uninstall();
                return;
            }
            
            $keep_logs = isset($_GET['keep_logs']) && $_GET['keep_logs'] === 'yes' ? 'yes' : 'no';
            update_option('umd_keep_logs_on_uninstall', $keep_logs);
            
            Core::uninstall();
            
            wp_redirect(admin_url('plugins.php?umd_uninstalled=1'));
            exit;
        }
    }

    /**
     * Handle AJAX uninstall request
     */
    public function ajax_process_uninstall() {
        check_ajax_referer('umd_uninstall_nonce');
        
        if (!current_user_can('delete_plugins')) {
            wp_send_json_error(__('You do not have permission to uninstall plugins.', 'ultimate-media-deletion'));
        }
        
        $keep_logs = isset($_POST['keep_logs']) && $_POST['keep_logs'] === 'yes' ? 'yes' : 'no';
        update_option('umd_keep_logs_on_uninstall', $keep_logs);
        
        Core::uninstall();
        
        wp_send_json_success([
            'redirect' => admin_url('plugins.php?umd_uninstalled=1')
        ]);
    }
}