/* global victoriabank_mia_thankyou */
( function () {
	'use strict';

	var config = victoriabank_mia_thankyou;

	var orderId      = config.order_id;
	var nonce        = config.nonce;      // check-order-status nonce
	var expiresAt    = config.expires_at; // Unix timestamp (seconds)
	var ajaxUrl      = config.ajax_url;
	var actionStatus = config.action_status;

	var pollInterval   = 5000; // ms
	var pollTimer      = null;
	var countdownTimer = null;

	var container   = document.getElementById( 'victoriabank_mia-order-qrcode' );
	var qrSection   = document.getElementById( 'victoriabank_mia-qr-section' );
	var successMsg  = document.getElementById( 'victoriabank_mia-success-message' );
	var expiredMsg  = document.getElementById( 'victoriabank_mia-expired-message' );
	var countdownEl = document.getElementById( 'victoriabank_mia-countdown' );

	if ( ! container ) {
		return;
	}

	/**
	 * Show the paid / success state.
	 */
	function showSuccess() {
		stopAll();
		if ( qrSection )  { qrSection.style.display  = 'none'; }
		if ( expiredMsg ) { expiredMsg.style.display  = 'none'; }
		if ( successMsg ) { successMsg.style.display  = 'block'; }
	}

	/**
	 * Show the expired state.
	 */
	function showExpired() {
		stopAll();
		if ( qrSection )  { qrSection.style.display  = 'none'; }
		if ( successMsg ) { successMsg.style.display  = 'none'; }
		if ( expiredMsg ) { expiredMsg.style.display  = 'block'; }
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
			if ( countdownEl ) { countdownEl.textContent = '00:00'; }
			showExpired();
			return;
		}

		if ( countdownEl ) { countdownEl.textContent = formatTime( remaining ); }
	}

	/**
	 * Poll the server to check whether the order has been paid.
	 */
	function pollOrderStatus() {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );

		xhr.onreadystatechange = function () {
			if ( xhr.readyState !== 4 ) { return; }
			if ( xhr.status !== 200 ) { return; }

			try {
				var data = JSON.parse( xhr.responseText );
				if ( data && data.success && data.data && data.data.paid ) {
					showSuccess();
				}
			} catch ( e ) {
				// Silently ignore JSON parse errors.
			}
		};

		xhr.send(
			'action=' + encodeURIComponent( actionStatus ) +
			'&order_id=' + encodeURIComponent( orderId ) +
			'&nonce=' + encodeURIComponent( nonce )
		);
	}

	// Kick off countdown and polling immediately.
	tickCountdown();
	countdownTimer = setInterval( tickCountdown, 1000 );
	pollOrderStatus();
	pollTimer = setInterval( pollOrderStatus, pollInterval );
}() );
