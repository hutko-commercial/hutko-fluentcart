/**
 * hutko embedded widget initialization (FluentCart standalone page).
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

( function () {
	'use strict';

	if ( typeof window.hutkoPaymentArguments === 'undefined' ) {
		return;
	}

	function init() {
		if ( typeof window.hutko !== 'function' ) {
			window.setTimeout( init, 50 );
			return;
		}
		window.hutko( '#hutko-checkout-container', window.hutkoPaymentArguments );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
