jQuery(function($) {
    // Add confirmation for bulk actions
    $('select[name="action"], select[name="action2"]').on('change', function() {
        if ('delete_with_media' === $(this).val()) {
            if (!confirm(ultimateMediaDeletion.confirmMessage)) {
                $(this).val('-1');
            }
        }
    });
});