<div class="wrap">
    <h1><?php esc_html_e('Ultimate Media Deletion Settings', 'ultimate-media-deletion'); ?></h1>
    
    <form method="post" action="options.php">
        <?php
        settings_fields('umd_settings_group');
        do_settings_sections('ultimate-media-deletion');
        submit_button();
        ?>
    </form>
</div>