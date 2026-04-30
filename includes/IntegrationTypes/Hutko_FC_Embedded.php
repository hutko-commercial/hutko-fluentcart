<?php
/**
 * Embedded payment form integration trait (FluentCart).
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Hutko_FC_Embedded {

	/**
	 * Obtain a checkout token from hutko API to be consumed by
	 * the embedded widget on the FluentCart checkout page.
	 *
	 * @param array $payment_params
	 * @return string
	 * @throws Exception
	 */
	protected function embeddedCheckoutToken( array $payment_params ): string {
		return Hutko_FC_API::getCheckoutToken( $payment_params );
	}

	/**
	 * Scripts/styles for embedded mode. Enqueued by AbstractPaymentGateway::enqueue()
	 * through getEnqueueScriptSrc()/getEnqueueStyleSrc() on checkout render.
	 *
	 * @return array
	 */
	protected function embeddedScriptSrc(): array {
		return array(
			array(
				'handle' => 'hutko-fc-checkout-vue',
				'src'    => 'https://pay.hutko.org/latest/checkout-vue/checkout.js',
			),
			array(
				'handle'    => 'hutko-fc-checkout-handler',
				'src'       => HUTKO_FC_URL . 'assets/js/hutko-fc-checkout.js',
				'deps'      => array( 'hutko-fc-checkout-vue' ),
				'in_footer' => true,
			),
		);
	}

	protected function embeddedStyleSrc(): array {
		return array(
			array(
				'handle' => 'hutko-fc-checkout-vue-css',
				'src'    => 'https://pay.hutko.org/latest/checkout-vue/checkout.css',
			),
			array(
				'handle' => 'hutko-fc-embedded-css',
				'src'    => HUTKO_FC_URL . 'assets/css/hutko_embedded.css',
				'deps'   => array( 'hutko-fc-checkout-vue-css' ),
			),
		);
	}
}
