/* global victoriabank_mia_thankyou_page */
(function ($) {
    'use strict';

    var pollInterval = parseInt(victoriabank_mia_thankyou_page.poll_interval, 10) || 10000; // ms
    var countdownIntervalVisible = 1000; // ms — smooth MM:SS tick when shown
    var countdownIntervalHidden = 15000; // ms — only needed to trigger expiry when hidden
    var reloadDelay = 2000; // ms — show success message briefly before reloading
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
        // Reload so WooCommerce re-renders the order-received page with the
        // now-paid order state (clears the pending-payment title and the
        // Pay/Cancel order actions).
        setTimeout(function () { window.location.reload(); }, reloadDelay);
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
     * Tick countdown — called every countdownInterval. Updates the countdown
     * element if present in the DOM (the element may be hidden in the template)
     * and flips to the expired state once the TTL runs out.
     */
    function tickCountdown() {
        var remaining = victoriabank_mia_thankyou_page.expires_at - Math.floor(Date.now() / 1000);

        if (remaining <= 0) {
            if ($countdownEl.length) { $countdownEl.text('00:00'); }
            showExpired();
            return;
        }

        if ($countdownEl.length) { $countdownEl.text(formatTime(remaining)); }
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
                if (data && data.success && data.data) {
                    if (data.data.is_paid) {
                        showSuccess();
                    } else if (data.data.needs_payment === false) {
                        // Terminal non-paid state (cancelled / failed / refunded) —
                        // treat like expiry so the retry button is surfaced.
                        showExpired();
                    }
                }
            }
        ).fail(function () {
            pollFailCount++;
            if (pollFailCount >= pollFailMax) {
                showExpired();
            }
        }).always(function () {
            isPolling = false;
        });
    }

    // Kick off countdown and polling immediately. Use a faster tick when the
    // countdown element is visible (so MM:SS updates smoothly), and a slower
    // tick otherwise — expiry still flips the UI either way.
    var countdownInterval = ($countdownEl.length && $countdownEl.is(':visible'))
        ? countdownIntervalVisible
        : countdownIntervalHidden;
    tickCountdown();
    countdownTimer = setInterval(tickCountdown, countdownInterval);
    pollOrderStatus();
    pollTimer = setInterval(pollOrderStatus, pollInterval);
}(jQuery));
