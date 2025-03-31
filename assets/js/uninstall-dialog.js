jQuery(document).ready(function($) {
    // Initialize uninstall dialog
    $('#umd-uninstall-dialog').dialog({
        modal: true,
        title: umdUninstallData.dialogTitle,
        width: 500,
        dialogClass: 'umd-uninstall-dialog',
        buttons: [
            {
                text: umdUninstallData.keepLogsText,
                click: function() {
                    processUninstall('yes');
                }
            },
            {
                text: umdUninstallData.deleteAllText,
                class: 'button-primary',
                click: function() {
                    if (confirm(umdUninstallData.deleteConfirm)) {
                        processUninstall('no');
                    }
                }
            }
        ]
    });

    function processUninstall(keepLogs) {
        $(this).dialog('close');
        
        $.post(umdUninstallData.ajaxurl, {
            action: 'umd_process_uninstall',
            keep_logs: keepLogs,
            _ajax_nonce: umdUninstallData.nonce
        }, function(response) {
            if (response.success) {
                // Show success message and redirect
                showSuccessMessage(response.data.redirect);
            }
        }).fail(function() {
            alert('An error occurred during uninstallation.');
        });
    }

    function showSuccessMessage(redirectUrl) {
        // Create a styled success message
        const $message = $('<div>').css({
            'position': 'fixed',
            'top': '20px',
            'right': '20px',
            'background': '#46b450',
            'color': 'white',
            'padding': '15px',
            'border-radius': '3px',
            'box-shadow': '0 1px 3px rgba(0,0,0,0.2)',
            'z-index': '99999'
        }).text(umdUninstallData.successMessage);
        
        $('body').append($message);
        
        // Fade out after 3 seconds and redirect
        $message.delay(3000).fadeOut(400, function() {
            $(this).remove();
            window.location.href = redirectUrl;
        });
    }

    // Check for and show success message
    if (umdUninstallData.showSuccess) {
        showSuccessMessage(window.location.href.replace(/[?&]umd_uninstalled=1/, ''));
    }
});