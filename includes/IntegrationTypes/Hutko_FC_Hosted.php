<?php
/**
 * Hosted payment form integration trait (FluentCart).
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Hutko_FC_Hosted {

	/**
	 * Build checkout_url via hutko API and return a redirect target
	 * for FluentCart's makePaymentFromPaymentInstance().
	 *
	 * @param array $payment_params
	 * @return string
	 * @throws Exception
	 */
	protected function hostedRedirectUrl( array $payment_params ): string {
		return Hutko_FC_API::getCheckoutUrl( $payment_params );
	}
}
