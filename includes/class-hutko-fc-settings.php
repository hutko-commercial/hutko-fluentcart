<?php
/**
 * hutko Settings class (FluentCart).
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class Hutko_FC_Settings extends BaseGatewaySettings {

	public $settings;
	public $methodHandler = 'fluent_cart_payment_settings_hutko';

	public function __construct() {
		parent::__construct();
		$settings = $this->getCachedSettings();
		$defaults = static::getDefaults();

		if ( ! is_array( $settings ) || empty( $settings ) ) {
			$settings = $defaults;
		} else {
			$settings = wp_parse_args( $settings, $defaults );
		}

		$this->settings = apply_filters( 'hutko_fc/settings', $settings );
	}

	public static function getDefaults(): array {
		return array(
			'is_active'         => 'no',
			'test_mode'         => 'no',
			'merchant_id'       => '',
			'secret_key'        => '',
			'integration_type'  => 'hosted',
			'recurrent_payment' => 'no',
			'payment_mode'      => 'live',
		);
	}

	public function isActive(): bool {
		return isset( $this->settings['is_active'] ) && $this->settings['is_active'] === 'yes';
	}

	public function isTestMode(): bool {
		return isset( $this->settings['test_mode'] ) && $this->settings['test_mode'] === 'yes';
	}

	public function getMode() {
		if ( $this->isTestMode() ) {
			return 'test';
		}
		return ( new StoreSettings() )->get( 'order_mode' );
	}

	public function getMerchantId() {
		if ( $this->isTestMode() ) {
			return Hutko_FC_API::TEST_MERCHANT_ID;
		}
		return (int) ( $this->settings['merchant_id'] ?? 0 );
	}

	public function getSecretKey() {
		if ( $this->isTestMode() ) {
			return Hutko_FC_API::TEST_MERCHANT_SECRET_KEY;
		}
		return $this->settings['secret_key'] ?? '';
	}

	public function getIntegrationType(): string {
		$type = $this->settings['integration_type'] ?? 'hosted';
		return in_array( $type, array( 'hosted', 'embedded' ), true ) ? $type : 'hosted';
	}

	public function isRecurrentEnabled(): bool {
		return isset( $this->settings['recurrent_payment'] ) && $this->settings['recurrent_payment'] === 'yes';
	}

	public function get( $key = '' ) {
		$settings = $this->settings;

		if ( $key && isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}
		return $settings;
	}
}
