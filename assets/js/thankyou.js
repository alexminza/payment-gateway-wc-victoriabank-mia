/* global QRCode, victoriabank_mia_thankyou_page */
(function ($) {
    'use strict';

    const pollInterval = parseInt(victoriabank_mia_thankyou_page.poll_interval, 10) || 10000; // ms
    const countdownIntervalVisible = 1000; // ms — smooth MM:SS tick when shown
    const countdownIntervalHidden = 15000; // ms — only needed to trigger expiry when hidden
    const reloadDelay = 2000; // ms — show success message briefly before reloading
    const pollFailMax = 10;

    let pollTimer = null;
    let countdownTimer = null;
    let isPolling = false;
    let pollFailCount = 0;

    const $container   = $(`#${victoriabank_mia_thankyou_page.container_id}`);
    const $qrSection   = $(`#${victoriabank_mia_thankyou_page.qr_section_id}`);
    const $qrCode      = $(`#${victoriabank_mia_thankyou_page.qr_code_id}`);
    const $successMsg  = $(`#${victoriabank_mia_thankyou_page.success_id}`);
    const $expiredMsg  = $(`#${victoriabank_mia_thankyou_page.expired_id}`);
    const $countdownEl = $(`#${victoriabank_mia_thankyou_page.countdown_id}`);
    const $spinner     = $(`#${victoriabank_mia_thankyou_page.spinner_id}`);

    if (!$container.length) {
        return;
    }

    /**
     * Render the desktop QR code.
     */
    function renderQRCode() {
        $qrCode.empty();

        new QRCode(
            $qrCode.get(0),
            {
                text: victoriabank_mia_thankyou_page.qr_text,
                width: 200,
                height: 200,
            }
        );
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
        setTimeout(reloadPage, reloadDelay);
    }

    function reloadPage() {
        window.location.reload();
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
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }

        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }

        $spinner.hide();
    }

    /**
     * Format seconds as MM:SS.
     *
     * @param {number} totalSeconds
     * @return {string}
     */
    function formatTime(totalSeconds) {
        if (totalSeconds < 0) {
            totalSeconds = 0;
        }

        const minutes = Math.floor(totalSeconds / 60);
        const seconds = Math.floor(totalSeconds % 60);
        const mm = String(minutes).padStart(2, '0');
        const ss = String(seconds).padStart(2, '0');
        return `${mm}:${ss}`;
    }

    /**
     * Tick countdown — called every countdownInterval. Updates the countdown
     * element if present in the DOM (the element may be hidden in the template)
     * and flips to the expired state once the TTL runs out.
     */
    function tickCountdown() {
        const remaining = victoriabank_mia_thankyou_page.expires_at - Math.floor(Date.now() / 1000);

        if (remaining <= 0) {
            if ($countdownEl.length) {
                $countdownEl.text('00:00');
            }

            showExpired();
            return;
        }

        if ($countdownEl.length) {
            $countdownEl.text(formatTime(remaining));
        }
    }

    /**
     * Stop polling without changing the visible payment state. Used when the
     * AJAX endpoint becomes unreachable — the QR may still be valid and the
     * payment notification callback is the source of truth, so we just hide
     * the in-progress indicator instead of flipping to expired.
     */
    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }

        $spinner.hide();
    }

    /**
     * Handle a successful order status poll.
     *
     * @param {Object} data
     */
    function handlePollSuccess(data) {
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

    /**
     * Handle a failed order status poll.
     *
     * @param {Object} jqXHR
     * @param {string} textStatus
     * @param {string} errorThrown
     */
    function handlePollFailure(jqXHR, textStatus, errorThrown) {
        pollFailCount++;
        if (pollFailCount >= pollFailMax) {
            if (window.console && console.error) {
                console.error(
                    `Order status polling disabled after ${pollFailMax} consecutive failures.`,
                    { status: textStatus, error: errorThrown }
                );
            }
            stopPolling();
        }
    }

    /**
     * Handle order status poll completion.
     */
    function handlePollComplete() {
        isPolling = false;
    }

    /**
     * Poll the server to check whether the order has been paid.
     */
    function pollOrderStatus() {
        if (isPolling) {
            return;
        }

        isPolling = true;

        $.post(
            victoriabank_mia_thankyou_page.ajax_url,
            {
                action: victoriabank_mia_thankyou_page.action_status,
                order_id: victoriabank_mia_thankyou_page.order_id,
                order_key: victoriabank_mia_thankyou_page.order_key,
                nonce: victoriabank_mia_thankyou_page.nonce,
            },
            handlePollSuccess
        ).fail(handlePollFailure).always(handlePollComplete);
    }

    // Kick off countdown and polling immediately. Use a faster tick when the
    // countdown element is visible (so MM:SS updates smoothly), and a slower
    // tick otherwise — expiry still flips the UI either way.
    const countdownInterval = ($countdownEl.length && $countdownEl.is(':visible'))
        ? countdownIntervalVisible
        : countdownIntervalHidden;

    if (!Boolean(victoriabank_mia_thankyou_page.is_mobile)) {
        renderQRCode();
    }

    tickCountdown();
    countdownTimer = setInterval(tickCountdown, countdownInterval);

    pollOrderStatus();
    pollTimer = setInterval(pollOrderStatus, pollInterval);
}(jQuery));
