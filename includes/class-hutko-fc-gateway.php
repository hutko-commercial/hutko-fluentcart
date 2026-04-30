<?php
/**
 * hutko payment gateway (FluentCart).
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;

class Hutko_FC_Gateway extends AbstractPaymentGateway {

	use Hutko_FC_Hosted;
	use Hutko_FC_Embedded;

	public array $supportedFeatures = array( 'payment', 'refund', 'webhook', 'subscriptions' );

	const HUTKO_ORDER_APPROVED   = 'approved';
	const HUTKO_ORDER_DECLINED   = 'declined';
	const HUTKO_ORDER_EXPIRED    = 'expired';
	const HUTKO_ORDER_PROCESSING = 'processing';
	const HUTKO_ORDER_CREATED    = 'created';
	const HUTKO_ORDER_REVERSED   = 'reversed';

	const META_HUTKO_ORDER_ID      = 'hutko_order_id';
	const META_HUTKO_CHECKOUT_TOKEN = 'hutko_checkout_token';
	const META_RECTOKEN            = 'hutko_rectoken';
	const META_RECTOKEN_LIFETIME   = 'hutko_rectoken_lifetime';
	const META_RECURRENT_CHARGED   = 'hutko_recurrent_charged_total';

	public function __construct() {
		parent::__construct( new Hutko_FC_Settings(), new Hutko_FC_Subscriptions() );
		$this->syncApiCredentials();
	}

	public function boot() {
		add_action( 'fluent_cart_action_hutko_embedded', array( $this, 'renderEmbeddedPage' ), 10, 1 );

		add_action( 'hutko_fc_process_renewals', array( $this, 'processRenewals' ) );
		if ( ! wp_next_scheduled( 'hutko_fc_process_renewals' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'hutko_fc_process_renewals' );
		}

		add_action( 'wp_ajax_hutko_fc_manual_charge', array( $this, 'ajaxManualCharge' ) );
	}

	private function syncApiCredentials(): void {
		Hutko_FC_API::setMerchantID( $this->settings->getMerchantId() );
		Hutko_FC_API::setSecretKey( $this->settings->getSecretKey() );
	}

	public function meta(): array {
		return array(
			'title'              => 'Hutko',
			'route'              => HUTKO_FC_GATEWAY_SLUG,
			'slug'               => HUTKO_FC_GATEWAY_SLUG,
			'label'              => 'Hutko',
			'admin_title'        => 'Hutko',
			'description'        => __( 'Cards, Apple Pay, Google Pay via Hutko', 'hutko-fluentcart-payment-gateway' ),
			'logo'               => HUTKO_FC_URL . 'assets/img/oplata_logo_cards.svg',
			'icon'               => HUTKO_FC_URL . 'assets/img/oplata_logo_cards.svg',
			'brand_color'        => '#1873B4',
			'upcoming'           => false,
			'status'             => $this->settings->isActive(),
			'supported_features' => $this->supportedFeatures,
		);
	}

	public function makePaymentFromPaymentInstance( PaymentInstance $paymentInstance ) {
		try {
			$this->syncApiCredentials();

			$payment_params = $this->getPaymentParams( $paymentInstance );

			$meta = is_array( $paymentInstance->transaction->meta ) ? $paymentInstance->transaction->meta : array();
			$meta[ self::META_HUTKO_ORDER_ID ] = $payment_params['order_id'];

			if ( 'embedded' === $this->settings->getIntegrationType() ) {
				$token                              = Hutko_FC_API::getCheckoutToken( $payment_params );
				$meta[ self::META_HUTKO_CHECKOUT_TOKEN ] = $token;
				$paymentInstance->transaction->meta      = $meta;
				$paymentInstance->transaction->save();

				$redirect = add_query_arg(
					array(
						'fluent-cart' => 'hutko_embedded',
						'trx'         => $paymentInstance->transaction->uuid,
					),
					home_url( '/' )
				);
			} else {
				$paymentInstance->transaction->meta = $meta;
				$paymentInstance->transaction->save();

				$redirect = Hutko_FC_API::getCheckoutUrl( $payment_params );
			}

			return array(
				'status'      => 'success',
				'message'     => __( 'Redirecting to Hutko...', 'hutko-fluentcart-payment-gateway' ),
				'redirect_to' => $redirect,
			);
		} catch ( Exception $e ) {
			return array(
				'status'  => 'failed',
				'message' => $e->getMessage(),
			);
		}
	}

	private function getPaymentParams( PaymentInstance $paymentInstance ): array {
		$order       = $paymentInstance->order;
		$transaction = $paymentInstance->transaction;

		$hutko_order_id = $transaction->id . '_' . time();

		$params = array(
			'order_id'            => $hutko_order_id,
			'order_desc'          => sprintf(
				/* translators: %s: order invoice number */
				__( 'Order #%s', 'hutko-fluentcart-payment-gateway' ),
				$order->invoice_no ? $order->invoice_no : $order->id
			),
			'amount'              => (int) $transaction->total,
			'currency'            => strtoupper( $transaction->currency ),
			'lang'                => substr( get_bloginfo( 'language' ), 0, 2 ),
			'sender_email'        => $order->email ? $order->email : '',
			'response_url'        => $this->getSuccessUrl( $transaction ),
			'server_callback_url' => site_url( '?fluent-cart=fct_payment_listener_ipn&method=' . HUTKO_FC_GATEWAY_SLUG ),
		);

		if ( $this->settings->isRecurrentEnabled() || $paymentInstance->subscription ) {
			$params['required_rectoken'] = 'Y';
		}

		return apply_filters( 'hutko_fc/payment_params', $params, $paymentInstance );
	}

	public function handleIPN(): void {
		$requestBody = null;
		try {
			$this->syncApiCredentials();

			$rawInput    = file_get_contents( 'php://input' );
			$requestBody = ! empty( $rawInput ) ? json_decode( $rawInput, true ) : null;

			if ( empty( $requestBody ) && ! empty( $_POST ) ) {
				$requestBody = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}

			if ( empty( $requestBody ) || ! is_array( $requestBody ) ) {
				throw new Exception( 'No valid callback data received' );
			}

			foreach ( array( 'merchant_id', 'payment_id', 'card_bin', 'amount', 'actual_amount' ) as $field ) {
				if ( isset( $requestBody[ $field ] ) && is_numeric( $requestBody[ $field ] ) ) {
					$requestBody[ $field ] = (string) $requestBody[ $field ];
				}
			}

			Hutko_FC_API::validateRequest( $requestBody );

			if ( ! empty( $requestBody['reversal_amount'] ) || ( isset( $requestBody['tran_type'] ) && 'reverse' === $requestBody['tran_type'] ) ) {
				status_header( 200 );
				exit;
			}

			$hutkoOrderId  = isset( $requestBody['order_id'] ) ? (string) $requestBody['order_id'] : '';
			$transactionId = (int) strstr( $hutkoOrderId, '_', true );

			$transaction = OrderTransaction::query()->find( $transactionId );
			if ( ! $transaction ) {
				throw new Exception( 'Transaction not found for order_id: ' . $hutkoOrderId );
			}

			$order = Order::query()->find( $transaction->order_id );
			if ( ! $order ) {
				throw new Exception( 'Order not found for transaction: ' . $transactionId );
			}

			do_action( 'hutko_fc/receive_valid_callback', $requestBody, $order, $transaction );

			$hutkoStatus = $requestBody['order_status'] ?? '';

			switch ( $hutkoStatus ) {
				case self::HUTKO_ORDER_APPROVED:
					if ( ! empty( $requestBody['rectoken'] ) ) {
						$this->storeRectoken( $order, $transaction, $requestBody['rectoken'], $requestBody['rectoken_lifetime'] ?? '' );
					}

					$transactionData = array(
						'vendor_charge_id'    => (string) ( $requestBody['payment_id'] ?? '' ),
						'status'              => Status::TRANSACTION_SUCCEEDED,
						'total'               => (int) ( $requestBody['amount'] ?? $transaction->total ),
						'payment_method_type' => sanitize_text_field( $requestBody['payment_system'] ?? '' ),
						'card_last_4'         => $this->parseCardLast4( $requestBody['masked_card'] ?? '' ),
						'card_brand'          => sanitize_text_field( $requestBody['card_type'] ?? '' ),
					);
					$this->updateOrderDataByOrder( $order, $transactionData, $transaction );
					break;

				case self::HUTKO_ORDER_CREATED:
				case self::HUTKO_ORDER_PROCESSING:
					break;

				case self::HUTKO_ORDER_DECLINED:
				case self::HUTKO_ORDER_EXPIRED:
					$transactionData = array(
						'status'           => Status::TRANSACTION_FAILED,
						'vendor_charge_id' => (string) ( $requestBody['payment_id'] ?? '' ),
					);
					$this->updateOrderDataByOrder( $order, $transactionData, $transaction );
					break;

				default:
					throw new Exception( 'Unhandled hutko order status: ' . $hutkoStatus );
			}

			status_header( 200 );
			exit;
		} catch ( Exception $e ) {
			if ( function_exists( 'fluent_cart_error_log' ) ) {
				fluent_cart_error_log(
					'Hutko IPN Error',
					$e->getMessage() . ' | Body: ' . wp_json_encode( $requestBody )
				);
			}
			wp_send_json( array( 'error' => $e->getMessage() ), 400 );
		}
	}

	private function parseCardLast4( string $masked ): string {
		if ( empty( $masked ) ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', $masked );
		return substr( $digits, -4 );
	}

	private function storeRectoken( $order, $transaction, string $rectoken, string $lifetime ): void {
		$meta = is_array( $transaction->meta ) ? $transaction->meta : array();
		$meta[ self::META_RECTOKEN ]          = sanitize_text_field( $rectoken );
		$meta[ self::META_RECTOKEN_LIFETIME ] = sanitize_text_field( $lifetime );
		$transaction->meta                    = $meta;
		$transaction->save();

		// also propagate to parent subscription if present
		$subscription = Subscription::query()->where( 'parent_order_id', $order->id )->first();
		if ( $subscription ) {
			$config                      = is_array( $subscription->config ) ? $subscription->config : array();
			$config[ self::META_RECTOKEN ]          = sanitize_text_field( $rectoken );
			$config[ self::META_RECTOKEN_LIFETIME ] = sanitize_text_field( $lifetime );
			$subscription->config                   = $config;
			$subscription->vendor_subscription_id   = $subscription->vendor_subscription_id ?: sanitize_text_field( $rectoken );
			$subscription->save();
		}
	}

	public function getOrderInfo( array $data ) {
		wp_send_json(
			array(
				'status'       => 'success',
				'message'      => __( 'Order info retrieved!', 'hutko-fluentcart-payment-gateway' ),
				'data'         => array(),
				'payment_args' => array(
					'integration_type' => $this->settings->getIntegrationType(),
					'merchant_id'      => (string) $this->settings->getMerchantId(),
				),
			),
			200
		);
	}

	public function processRefund( $transaction, $amount, $args ) {
		try {
			$this->syncApiCredentials();

			$meta           = is_array( $transaction->meta ) ? $transaction->meta : array();
			$hutko_order_id = $meta[ self::META_HUTKO_ORDER_ID ] ?? '';
			if ( empty( $hutko_order_id ) ) {
				return new \WP_Error(
					'hutko_refund',
					__( 'No Hutko order ID stored on this transaction.', 'hutko-fluentcart-payment-gateway' )
				);
			}

			if ( ! $amount ) {
				return new \WP_Error(
					'hutko_refund',
					__( 'Refund amount is required.', 'hutko-fluentcart-payment-gateway' )
				);
			}

			$reverse = Hutko_FC_API::reverse(
				array(
					'order_id' => $hutko_order_id,
					'amount'   => (int) round( $amount ),
					'currency' => strtoupper( $transaction->currency ),
					'comment'  => substr( (string) Arr::get( $args, 'reason', '' ), 0, 1024 ),
				)
			);

			$status = $reverse->reverse_status ?? '';
			if ( in_array( $status, array( 'approved', 'processing' ), true ) ) {
				$refundId = isset( $reverse->reverse_id ) ? (string) $reverse->reverse_id : ( $hutko_order_id . '_refund_' . time() );
				return $refundId;
			}

			return new \WP_Error(
				'hutko_refund',
				sprintf(
					/* translators: %s: reverse status returned by Hutko */
					__( 'Refund failed. Hutko status: %s', 'hutko-fluentcart-payment-gateway' ),
					$status
				)
			);
		} catch ( Exception $e ) {
			return new \WP_Error( 'hutko_refund', $e->getMessage() );
		}
	}

	public function fields(): array {
		return array(
			'is_active'         => array(
				'type'  => 'checkbox',
				'label' => __( 'Enable Hutko', 'hutko-fluentcart-payment-gateway' ),
				'value' => $this->settings->get( 'is_active' ),
			),
			'test_mode'         => array(
				'type'        => 'checkbox',
				'label'       => __( 'Test mode', 'hutko-fluentcart-payment-gateway' ),
				'value'       => $this->settings->get( 'test_mode' ),
				'description' => __( 'Use Hutko test merchant credentials.', 'hutko-fluentcart-payment-gateway' ),
			),
			'merchant_id'       => array(
				'type'        => 'text',
				'label'       => __( 'Merchant ID', 'hutko-fluentcart-payment-gateway' ),
				'value'       => $this->settings->get( 'merchant_id' ),
				'description' => __( 'Given to merchant by Hutko.', 'hutko-fluentcart-payment-gateway' ),
			),
			'secret_key'        => array(
				'type'        => 'text',
				'label'       => __( 'Secret Key', 'hutko-fluentcart-payment-gateway' ),
				'value'       => $this->settings->get( 'secret_key' ),
				'description' => __( 'Given to merchant by Hutko.', 'hutko-fluentcart-payment-gateway' ),
			),
			'integration_type'  => array(
				'type'        => 'radio',
				'label'       => __( 'Checkout mode', 'hutko-fluentcart-payment-gateway' ),
				'value'       => $this->settings->get( 'integration_type' ),
				'options'     => array(
					'hosted'   => __( 'Hosted (redirect)', 'hutko-fluentcart-payment-gateway' ),
					'embedded' => __( 'Embedded (on-site widget)', 'hutko-fluentcart-payment-gateway' ),
				),
				'description' => __( 'Choose between redirecting to Hutko or embedding its widget on your site.', 'hutko-fluentcart-payment-gateway' ),
			),
			'recurrent_payment' => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable recurring / subscription support', 'hutko-fluentcart-payment-gateway' ),
				'value'       => $this->settings->get( 'recurrent_payment' ),
				'description' => __( 'Request a rectoken during payment so admins or the auto-renewal cron can re-charge.', 'hutko-fluentcart-payment-gateway' ),
			),
			'webhook_desc'      => array(
				'type'  => 'html_attr',
				'label' => __( 'Callback URL', 'hutko-fluentcart-payment-gateway' ),
				'value' => '<p>' . __( 'The server-callback URL is sent automatically with every payment request — you do not need to configure anything in the Hutko merchant portal.', 'hutko-fluentcart-payment-gateway' ) . '</p>'
					. '<p>' . __( 'Shown here for reference / as a fallback if you want to hardcode it in Hutko:', 'hutko-fluentcart-payment-gateway' ) . '</p>'
					. '<code>' . esc_url( site_url( '?fluent-cart=fct_payment_listener_ipn&method=' . HUTKO_FC_GATEWAY_SLUG ) ) . '</code>',
			),
		);
	}

	public static function validateSettings( $data ): array {
		if ( ( $data['test_mode'] ?? 'no' ) === 'yes' ) {
			return array(
				'status'  => 'success',
				'message' => __( 'Test mode enabled.', 'hutko-fluentcart-payment-gateway' ),
			);
		}

		if ( empty( $data['merchant_id'] ) || empty( $data['secret_key'] ) ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'Merchant ID and Secret Key are required.', 'hutko-fluentcart-payment-gateway' ),
			);
		}

		if ( ! is_numeric( $data['merchant_id'] ) ) {
			return array(
				'status'  => 'failed',
				'message' => __( 'Merchant ID must be numeric.', 'hutko-fluentcart-payment-gateway' ),
			);
		}

		return array(
			'status'  => 'success',
			'message' => __( 'Hutko settings validated.', 'hutko-fluentcart-payment-gateway' ),
		);
	}

	public function getEnqueueScriptSrc( $hasSubscription = 'no' ): array {
		if ( 'embedded' !== $this->settings->getIntegrationType() ) {
			return array();
		}
		return $this->embeddedScriptSrc();
	}

	public function getEnqueueStyleSrc(): array {
		if ( 'embedded' !== $this->settings->getIntegrationType() ) {
			return array();
		}
		return $this->embeddedStyleSrc();
	}

	public function getTransactionUrl( $url, $data ): string {
		$transaction = Arr::get( $data, 'transaction' );
		if ( ! $transaction || empty( $transaction->vendor_charge_id ) ) {
			return $url;
		}
		return 'https://portal.hutko.org/#/transactions/payments/info/' . $transaction->vendor_charge_id . '/general';
	}

	/**
	 * Renders the embedded payment page. Triggered by ?fluent-cart=hutko_embedded&trx=UUID.
	 */
	public function renderEmbeddedPage( $data ) {
		$trx_hash = isset( $_GET['trx'] ) ? sanitize_text_field( wp_unslash( $_GET['trx'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $trx_hash ) ) {
			wp_die( esc_html__( 'Missing transaction reference.', 'hutko-fluentcart-payment-gateway' ) );
		}

		$transaction = OrderTransaction::query()->where( 'uuid', $trx_hash )->first();
		if ( ! $transaction ) {
			wp_die( esc_html__( 'Transaction not found.', 'hutko-fluentcart-payment-gateway' ) );
		}

		$meta  = is_array( $transaction->meta ) ? $transaction->meta : array();
		$token = $meta[ self::META_HUTKO_CHECKOUT_TOKEN ] ?? '';
		if ( empty( $token ) ) {
			wp_die( esc_html__( 'Hutko checkout token is missing.', 'hutko-fluentcart-payment-gateway' ) );
		}

		$args = array(
			'options' => array(
				'full_screen' => false,
				'email'       => true,
				'methods'     => array( 'card', 'wallets' ),
				'active_tab'  => 'card',
			),
			'params'  => array( 'token' => $token ),
		);

		$this->outputEmbeddedTemplate( $args );
		exit;
	}

	private function outputEmbeddedTemplate( array $args ): void {
		$title = esc_html__( 'Complete payment', 'hutko-fluentcart-payment-gateway' );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>"/>
	<meta name="viewport" content="width=device-width,initial-scale=1"/>
	<title><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></title>
	<link rel="stylesheet" href="https://pay.hutko.org/latest/checkout-vue/checkout.css"/>
	<link rel="stylesheet" href="<?php echo esc_url( HUTKO_FC_URL . 'assets/css/hutko_embedded.css' ); ?>"/>
</head>
<body>
	<div id="hutko-checkout-container"></div>
	<script>window.hutkoPaymentArguments = <?php echo wp_json_encode( $args ); ?>;</script>
	<script src="https://pay.hutko.org/latest/checkout-vue/checkout.js"></script>
	<script src="<?php echo esc_url( HUTKO_FC_URL . 'assets/js/hutko-fc-embedded.js' ); ?>"></script>
</body>
</html>
		<?php
	}

	/**
	 * Daily cron: find due subscriptions paid with hutko and charge via rectoken.
	 */
	public function processRenewals(): void {
		$subscriptions = Subscription::query()
			->where( 'status', Status::SUBSCRIPTION_ACTIVE )
			->where( 'current_payment_method', HUTKO_FC_GATEWAY_SLUG )
			->whereNotNull( 'next_billing_date' )
			->where( 'next_billing_date', '<=', gmdate( 'Y-m-d H:i:s' ) )
			->get();

		foreach ( $subscriptions as $subscription ) {
			try {
				$this->renewSubscription( $subscription );
			} catch ( Exception $e ) {
				if ( function_exists( 'fluent_cart_error_log' ) ) {
					fluent_cart_error_log(
						'Hutko Renewal Error',
						sprintf( 'Subscription #%d: %s', $subscription->id, $e->getMessage() )
					);
				}
			}
		}
	}

	private function renewSubscription( Subscription $subscription ): void {
		$config   = is_array( $subscription->config ) ? $subscription->config : array();
		$rectoken = $config[ self::META_RECTOKEN ] ?? '';
		if ( empty( $rectoken ) ) {
			throw new Exception( 'No rectoken stored for subscription' );
		}

		$this->syncApiCredentials();

		$amount_cents = (int) $subscription->recurring_amount;
		if ( $amount_cents <= 0 ) {
			return;
		}

		$result = Hutko_FC_API::recurring(
			array(
				'order_id'   => 'renewal_' . $subscription->id . '_' . time(),
				'order_desc' => sprintf(
					/* translators: %d: subscription id */
					__( 'Renewal charge for subscription #%d', 'hutko-fluentcart-payment-gateway' ),
					$subscription->id
				),
				'amount'     => $amount_cents,
				'currency'   => strtoupper( $subscription->currency ?: 'UAH' ),
				'rectoken'   => $rectoken,
			)
		);

		if ( ( $result->order_status ?? '' ) === self::HUTKO_ORDER_APPROVED ) {
			$subscription->bill_count        = (int) $subscription->bill_count + 1;
			$subscription->next_billing_date = $subscription->guessNextBillingDate( true );
			$subscription->save();

			do_action( 'hutko_fc/subscription_renewed', $subscription, $result );
		} else {
			throw new Exception( 'Recurring charge not approved: ' . ( $result->order_status ?? 'unknown' ) );
		}
	}

	/**
	 * Admin AJAX manual charge using a stored rectoken.
	 */
	public function ajaxManualCharge(): void {
		check_ajax_referer( 'hutko_fc_manual_charge' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hutko-fluentcart-payment-gateway' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$amount   = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;

		if ( ! $order_id || $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order or amount.', 'hutko-fluentcart-payment-gateway' ) ) );
		}

		$order = Order::query()->find( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'hutko-fluentcart-payment-gateway' ) ) );
		}

		$lastTrx = OrderTransaction::query()
			->where( 'order_id', $order->id )
			->where( 'payment_method', HUTKO_FC_GATEWAY_SLUG )
			->whereNotNull( 'meta' )
			->latest()
			->first();

		$meta     = $lastTrx && is_array( $lastTrx->meta ) ? $lastTrx->meta : array();
		$rectoken = $meta[ self::META_RECTOKEN ] ?? '';

		if ( empty( $rectoken ) ) {
			wp_send_json_error( array( 'message' => __( 'No rectoken available for this order.', 'hutko-fluentcart-payment-gateway' ) ) );
		}

		try {
			$this->syncApiCredentials();
			$amount_cents = (int) round( $amount * 100 );

			$result = Hutko_FC_API::recurring(
				array(
					'order_id'   => 'manual_' . $order->id . '_' . time(),
					'order_desc' => sprintf(
						/* translators: %d: order id */
						__( 'Manual charge for order #%d', 'hutko-fluentcart-payment-gateway' ),
						$order->id
					),
					'amount'     => $amount_cents,
					'currency'   => strtoupper( $order->currency ),
					'rectoken'   => $rectoken,
				)
			);

			if ( ( $result->order_status ?? '' ) === self::HUTKO_ORDER_APPROVED ) {
				$charged_before  = isset( $meta[ self::META_RECURRENT_CHARGED ] ) ? (float) $meta[ self::META_RECURRENT_CHARGED ] : 0.0;
				$charged_total   = $charged_before + $amount;
				$meta[ self::META_RECURRENT_CHARGED ] = $charged_total;
				if ( $lastTrx ) {
					$lastTrx->meta = $meta;
					$lastTrx->save();
				}

				wp_send_json_success(
					array(
						'message'       => sprintf(
							/* translators: 1) amount 2) currency 3) payment id */
							__( 'Charge successful: %1$s %2$s. Hutko ID: %3$s', 'hutko-fluentcart-payment-gateway' ),
							$amount,
							$order->currency,
							$result->payment_id ?? ''
						),
						'charged_total' => $charged_total,
					)
				);
			}

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: reverse/order status */
						__( 'Manual charge failed. Status: %s', 'hutko-fluentcart-payment-gateway' ),
						$result->order_status ?? 'unknown'
					),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}
