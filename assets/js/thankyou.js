/* global victoriabank_mia_thankyou_page */
(function ($) {
    'use strict';

    var pollInterval = 5000; // ms
    var pollTimer = null;
    var countdownTimer = null;
    var isPolling = false;
    var pollFailCount = 0;
    var pollFailMax = 3;

    var $container   = $('#' + victoriabank_mia_thankyou_page.container_id);
    var $qrSection   = $('#' + victoriabank_mia_thankyou_page.qr_section_id);
    var $successMsg  = $('#' + victoriabank_mia_thankyou_page.success_id);
    var $expiredMsg  = $('#' + victoriabank_mia_thankyou_page.expired_id);
    var $countdownEl = $('#' + victoriabank_mia_thankyou_page.countdown_id);
    var $spinner     = $('#' + victoriabank_mia_thankyou_page.spinner_id);

    if (!$container.length) {
        return;
    }

    /**
     * Show the paid / success state.
     */
    function showSuccess() {
        stopAll();
        $qrSection.hide();
        $expiredMsg.hide();
        $successMsg.css('display', 'flex');
    }

    /**
     * Show the expired state.
     */
    function showExpired() {
        stopAll();
        $qrSection.hide();
        $successMsg.hide();
        $expiredMsg.css('display', 'flex');
    }

    /**
     * Stop all timers.
     */
    function stopAll() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
        $spinner.hide();
    }

    /**
     * Format seconds as MM:SS.
     *
     * @param {number} totalSeconds
     * @return {string}
     */
    function formatTime(totalSeconds) {
        if (totalSeconds < 0) { totalSeconds = 0; }
        var minutes = Math.floor(totalSeconds / 60);
        var seconds = Math.floor(totalSeconds % 60);
        return (
            (minutes < 10 ? '0' : '') + minutes +
            ':' +
            (seconds < 10 ? '0' : '') + seconds
        );
    }

    /**
     * Tick countdown — called every second.
     */
    function tickCountdown() {
        var remaining = victoriabank_mia_thankyou_page.expires_at - Math.floor(Date.now() / 1000);

        if (remaining <= 0) {
            $countdownEl.text('00:00');
            showExpired();
            return;
        }

        $countdownEl.text(formatTime(remaining));
    }

    /**
     * Poll the server to check whether the order has been paid.
     */
    function pollOrderStatus() {
        if (isPolling) { return; }
        isPolling = true;

        $.post(
            victoriabank_mia_thankyou_page.ajax_url,
            {
                action: victoriabank_mia_thankyou_page.action_status,
                order_id: victoriabank_mia_thankyou_page.order_id,
                order_key: victoriabank_mia_thankyou_page.order_key,
                nonce: victoriabank_mia_thankyou_page.nonce,
            },
            function (data) {
                pollFailCount = 0;
                if (data && data.success && data.data && data.data.is_paid) {
                    showSuccess();
                }
            }
        ).fail(function () {
            pollFailCount++;
            if (pollFailCount >= pollFailMax) {
                stopAll();
            }
        }).always(function () {
            isPolling = false;
        });
    }

    // Kick off countdown and polling immediately.
    tickCountdown();
    countdownTimer = setInterval(tickCountdown, 1000);
    pollOrderStatus();
    pollTimer = setInterval(pollOrderStatus, pollInterval);
}(jQuery));
