/* global victoriabank_mia_thankyou_page */
( function ( $ ) {
	'use strict';

	var orderId      = victoriabank_mia_thankyou_page.order_id;
	var nonce        = victoriabank_mia_thankyou_page.nonce;      // check-order-status nonce
	var expiresAt    = victoriabank_mia_thankyou_page.expires_at; // Unix timestamp (seconds)
	var ajaxUrl      = victoriabank_mia_thankyou_page.ajax_url;
	var actionStatus = victoriabank_mia_thankyou_page.action_status;

	var pollInterval   = 5000; // ms
	var pollTimer      = null;
	var countdownTimer = null;

	var $container   = $( '#victoriabank_mia-order-qrcode' );
	var $qrSection   = $( '#victoriabank_mia-qr-section' );
	var $successMsg  = $( '#victoriabank_mia-success-message' );
	var $expiredMsg  = $( '#victoriabank_mia-expired-message' );
	var $countdownEl = $( '#victoriabank_mia-countdown' );

	if ( ! $container.length ) {
		return;
	}

	/**
	 * Show the paid / success state.
	 */
	function showSuccess() {
		stopAll();
		$qrSection.hide();
		$expiredMsg.hide();
		$successMsg.show();
	}

	/**
	 * Show the expired state.
	 */
	function showExpired() {
		stopAll();
		$qrSection.hide();
		$successMsg.hide();
		$expiredMsg.show();
	}

	/**
	 * Stop all timers.
	 */
	function stopAll() {
		if ( pollTimer )      { clearInterval( pollTimer );      pollTimer = null; }
		if ( countdownTimer ) { clearInterval( countdownTimer ); countdownTimer = null; }
	}

	/**
	 * Format seconds as MM:SS.
	 *
	 * @param {number} totalSeconds
	 * @return {string}
	 */
	function formatTime( totalSeconds ) {
		if ( totalSeconds < 0 ) { totalSeconds = 0; }
		var minutes = Math.floor( totalSeconds / 60 );
		var seconds = Math.floor( totalSeconds % 60 );
		return (
			( minutes < 10 ? '0' : '' ) + minutes +
			':' +
			( seconds < 10 ? '0' : '' ) + seconds
		);
	}

	/**
	 * Tick countdown — called every second.
	 */
	function tickCountdown() {
		var remaining = expiresAt - Math.floor( Date.now() / 1000 );

		if ( remaining <= 0 ) {
			$countdownEl.text( '00:00' );
			showExpired();
			return;
		}

		$countdownEl.text( formatTime( remaining ) );
	}

	/**
	 * Poll the server to check whether the order has been paid.
	 */
	function pollOrderStatus() {
		$.post(
			ajaxUrl,
			{
				action:   actionStatus,
				order_id: orderId,
				nonce:    nonce,
			},
			function ( data ) {
				if ( data && data.success && data.data && data.data.paid ) {
					showSuccess();
				}
			}
		);
	}

	// Kick off countdown and polling immediately.
	tickCountdown();
	countdownTimer = setInterval( tickCountdown, 1000 );
	pollOrderStatus();
	pollTimer = setInterval( pollOrderStatus, pollInterval );
}( jQuery ) );
