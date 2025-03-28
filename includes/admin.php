<?php
namespace UltimateMediaDeletion;

class Admin {
    public function __construct() {
        add_action('admin_notices', [$this, 'media_deletion_admin_notice']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        add_filter('bulk_actions-edit-post', [$this, 'register_bulk_actions']);
        add_filter('bulk_actions-edit-page', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_actions'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_admin_notice']);
        add_action('admin_footer', [$this, 'single_delete_confirmation']);
    }

    public function media_deletion_admin_notice() {
        global $post;

        if (!isset($_GET['action']) || 'delete' !== $_GET['action'] || !$post) {
            return;
        }

        $media_count = Helpers::count_post_media($post->ID);
        if ($media_count > 0) {
            echo '<div class="notice notice-warning"><p>' .
                sprintf(
                    _n(
                        'Warning: This will permanently delete %d attached media item.',
                        'Warning: This will permanently delete %d attached media items.', 
                        $media_count,
                        'ultimate-media-deletion'
                    ),
                    $media_count
                ) .
                '</p></div>';
        }
    }

    public function admin_enqueue_scripts($hook) {
        if ('edit.php' !== $hook) return;
        
        wp_enqueue_script(
            'ultimate-media-deletion-admin',
            UMD_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            UMD_VERSION,
            true
        );
    }

    public function register_bulk_actions($bulk_actions) {
        $bulk_actions['delete_with_media'] = __('Delete with media', 'ultimate-media-deletion');
        return $bulk_actions;
    }

    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ('delete_with_media' !== $doaction) {
            return $redirect_to;
        }

        $core = new Core();
        $deleted = 0;
        
        foreach ($post_ids as $post_id) {
            $core->delete_all_post_media($post_id);
            if (wp_delete_post($post_id, true)) {
                $deleted++;
            }
        }

        return add_query_arg([
            'deleted_with_media' => $deleted,
            'bulk_action' => 'delete_with_media'
        ], $redirect_to);
    }

    public function bulk_action_admin_notice() {
        if (!empty($_REQUEST['bulk_action']) && 'delete_with_media' === $_REQUEST['bulk_action']) {
            $count = intval($_REQUEST['deleted_with_media'] ?? 0);
            
            echo '<div class="notice notice-success is-dismissible"><p>' .
                sprintf(
                    _n(
                        'Deleted %d post with all attached media.',
                        'Deleted %d posts with all attached media.',
                        $count,
                        'ultimate-media-deletion'
                    ),
                    $count
                ) .
                '</p></div>';
        }
    }

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
                $('form#delete-post').submit(function(e) {
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
}