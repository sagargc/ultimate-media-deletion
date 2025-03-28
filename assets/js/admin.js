/**
 * Ultimate Media Deletion Admin JS
 * 
 * Handles admin interface enhancements
 */

jQuery(document).ready(function($) {
    // Bulk action confirmation
    $('select[name="action"], select[name="action2"]').on('change', function() {
        if ($(this).val() === 'delete_with_media') {
            if (!confirm(ultimateMediaDeletion.confirmBulkDelete)) {
                $(this).val('-1');
            }
        }
    });
});