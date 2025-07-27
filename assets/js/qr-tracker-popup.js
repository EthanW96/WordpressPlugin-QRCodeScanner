/**
 * QR Tracker Popup JavaScript
 * Handles popup display and interaction
 */
(function($) {
    'use strict';

    // Popup state management
    let popupShown = false;
    let popupTimer = null;

    /**
     * Initialize popup functionality
     */
    function initPopup() {
        if (!qrTrackerPopup.showPopup) {
            return;
        }

        // Auto-show popup after delay
        if (qrTrackerPopup.settings && qrTrackerPopup.settings.auto_show) {
            const delay = qrTrackerPopup.settings.delay || 1000;
            popupTimer = setTimeout(showPopup, delay);
        }

        // Bind close button event
        $(document).on('click', '#qr-tracker-popup-close', hidePopup);
        
        // Bind escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && popupShown) {
                hidePopup();
            }
        });

        // No click outside to close functionality - popup only closes via X button or Escape key
    }

    /**
     * Show the popup
     */
    function showPopup() {
        if (popupShown) {
            return;
        }

        const $popup = $('#qr-tracker-popup');
        if ($popup.length === 0) {
            console.warn('QR Tracker popup element not found');
            return;
        }

        // Apply position class
        const position = qrTrackerPopup.settings ? qrTrackerPopup.settings.position : 'bottom-right';
        $popup.removeClass('bottom-right bottom-left top-right top-left').addClass(position);

        // Apply theme class
        const theme = qrTrackerPopup.settings ? qrTrackerPopup.settings.theme : 'default';
        if (theme !== 'default') {
            $popup.addClass('theme-' + theme);
        }

        // Show popup with animation
        $popup.fadeIn(300);
        popupShown = true;

        // Set focus to close button for accessibility
        setTimeout(function() {
            $('#qr-tracker-popup-close').focus();
        }, 350);

        // Track popup view if analytics is available
        if (typeof gtag !== 'undefined') {
            gtag('event', 'qr_popup_view', {
                'event_category': 'QR Tracker',
                'event_label': 'Popup Displayed'
            });
        }
    }

    /**
     * Hide the popup
     */
    function hidePopup() {
        if (!popupShown) {
            return;
        }

        const $popup = $('#qr-tracker-popup');
        $popup.fadeOut(200, function() {
            popupShown = false;
        });

        // Clear any pending timer
        if (popupTimer) {
            clearTimeout(popupTimer);
            popupTimer = null;
        }

        // Track popup close if analytics is available
        if (typeof gtag !== 'undefined') {
            gtag('event', 'qr_popup_close', {
                'event_category': 'QR Tracker',
                'event_label': 'Popup Closed'
            });
        }
    }

    /**
     * Update popup messages via AJAX
     */
    function updatePopupMessages() {
        if (!qrTrackerPopup.ajaxUrl) {
            return;
        }

        $.ajax({
            url: qrTrackerPopup.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qr_tracker_get_messages',
                nonce: qrTrackerPopup.nonce
            },
            success: function(response) {
                if (response.success && response.message_1 && response.message_2) {
                    $('#qr-tracker-message-1').html(response.message_1);
                    $('#qr-tracker-message-2').html(response.message_2);
                }
            },
            error: function(xhr, status, error) {
                console.warn('Failed to update QR popup messages:', error);
            }
        });
    }

    /**
     * Public API for external use
     */
    window.QRTrackerPopup = {
        show: showPopup,
        hide: hidePopup,
        updateMessages: updatePopupMessages
    };

    /**
     * Initialize when document is ready
     */
    $(document).ready(function() {
        initPopup();
    });

    /**
     * Handle page visibility changes - removed auto-close for accessibility
     */
    // Removed auto-close on page visibility change to preserve important information

    /**
     * Handle window resize
     */
    $(window).on('resize', function() {
        if (popupShown) {
            // Reposition popup if needed
            const $popup = $('#qr-tracker-popup');
            const position = qrTrackerPopup.settings ? qrTrackerPopup.settings.position : 'bottom-right';
            $popup.removeClass('bottom-right bottom-left top-right top-left').addClass(position);
        }
    });

})(jQuery); 