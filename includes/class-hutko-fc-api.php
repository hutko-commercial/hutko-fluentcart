<?php
/**
 * Hutko API helper class (FluentCart).
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hutko_FC_API {

	const TEST_MERCHANT_ID         = 1700002;
	const TEST_MERCHANT_SECRET_KEY = 'test';

	private static $ApiUrl = 'https://pay.hutko.org/api/';

	private static $merchantID;
	private static $secretKey;

	public static function getMerchantID() {
		return self::$merchantID;
	}

	public static function setMerchantID( $merchantID ) {
		self::$merchantID = $merchantID;
	}

	public static function setSecretKey( $secretKey ) {
		self::$secretKey = $secretKey;
	}

	public static function getSecretKey() {
		return self::$secretKey;
	}

	public static function getCheckoutUrl( $request_data ) {
		$response = self::sendToAPI( 'checkout/url', $request_data );
		return $response->checkout_url;
	}

	public static function getCheckoutToken( $request_data ) {
		$response = self::sendToAPI( 'checkout/token', $request_data );
		return $response->token;
	}

	public static function reverse( $request_data ) {
		return self::sendToAPI( 'reverse/order_id', $request_data );
	}

	public static function capture( $request_data ) {
		return self::sendToAPI( 'capture/order_id', $request_data );
	}

	public static function recurring( $request_data ) {
		return self::sendToAPI( 'recurring', $request_data );
	}

	public static function sendToAPI( $endpoint, $request_data ) {
		$request_data['merchant_id'] = self::getMerchantID();
		$request_data['signature']   = self::getSignature( $request_data, self::getSecretKey() );

		$response = wp_safe_remote_post(
			self::$ApiUrl . $endpoint,
			array(
				'headers' => array( 'Content-type' => 'application/json;charset=UTF-8' ),
				'body'    => wp_json_encode( array( 'request' => $request_data ) ),
				'timeout' => 70,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			/* translators: %s: HTTP response code */
			throw new Exception( sprintf( __( 'Hutko API Return code is %s. Please try again later.', 'hutko-fluentcart-payment-gateway' ), $response_code ) );
		}

		$result = json_decode( $response['body'] );

		if ( empty( $result->response ) && empty( $result->response->response_status ) ) {
			throw new Exception( __( 'Unknown Hutko API answer.', 'hutko-fluentcart-payment-gateway' ) );
		}

		if ( 'success' !== $result->response->response_status ) {
			throw new Exception( $result->response->error_message );
		}

		return $result->response;
	}

	public static function getSignature( $data, $password, $encoded = true ) {
		$data = array_filter(
			$data,
			function ( $var ) {
				return '' !== $var && null !== $var;
			}
		);
		ksort( $data );

		$str = $password;
		foreach ( $data as $k => $v ) {
			$str .= '|' . $v;
		}

		return $encoded ? sha1( $str ) : $str;
	}

	public static function validateRequest( $request_body ) {
		if ( empty( $request_body ) ) {
			throw new Exception( __( 'Empty request body.', 'hutko-fluentcart-payment-gateway' ) );
		}

		$received_merchant_id = isset( $request_body['merchant_id'] ) ? (int) $request_body['merchant_id'] : null;

		if ( (int) self::$merchantID !== $received_merchant_id ) {
			throw new Exception(
				sprintf(
					/* translators: 1) expected merchant id 2) received merchant id */
					__( 'Merchant data is incorrect. Expected: %1$s, Received: %2$s', 'hutko-fluentcart-payment-gateway' ),
					self::$merchantID,
					$request_body['merchant_id'] ?? 'NULL'
				)
			);
		}

		$request_signature = $request_body['signature'] ?? '';
		unset( $request_body['response_signature_string'] );
		unset( $request_body['signature'] );

		if ( $request_signature !== self::getSignature( $request_body, self::$secretKey ) ) {
			throw new Exception( __( 'Signature is not valid', 'hutko-fluentcart-payment-gateway' ) );
		}
	}
}
