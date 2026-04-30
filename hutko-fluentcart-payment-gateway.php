<?php
/**
 * Plugin Name: Hutko payment for FluentCart
 * Plugin URL: https://hutko.org/uk/tools/integrations/wordpress/fluentcart/
 * Description: Hutko Payment Gateway for FluentCart.
 * Author: Hutko
 * Author URI: https://hutko.org
 * Requires at least: 5.8
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * Version: 1.0.0
 * Text Domain: hutko-fluentcart-payment-gateway
 * Domain Path: /languages
 *
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HUTKO_FC_DIR', dirname( __FILE__ ) );
define( 'HUTKO_FC_BASE_FILE', __FILE__ );
define( 'HUTKO_FC_URL', plugin_dir_url( __FILE__ ) );
define( 'HUTKO_FC_VERSION', '1.0.0' );
define( 'HUTKO_FC_MIN_PHP_VER', '7.4' );
define( 'HUTKO_FC_GATEWAY_SLUG', 'hutko' );

add_action( 'plugins_loaded', 'hutko_fluentcart_gateway_bootstrap' );

if ( ! class_exists( 'Hutko_FC' ) ) {
	/**
	 * Main plugin class.
	 */
	class Hutko_FC {

		/**
		 * @var Hutko_FC|null
		 */
		private static $instance = null;

		public static function getInstance() {
			if ( null === static::$instance ) {
				static::$instance = new static();
			}
			return static::$instance;
		}

		private function __construct() {
			if ( ! $this->isFluentCartActive() ) {
				add_action( 'admin_notices', array( $this, 'noticeFluentCartMissing' ) );
				return;
			}

			if ( version_compare( phpversion(), HUTKO_FC_MIN_PHP_VER, '<' ) ) {
				add_action( 'admin_notices', array( $this, 'noticePhpVersion' ) );
				return;
			}

			require_once HUTKO_FC_DIR . '/includes/class-hutko-fc-api.php';
			require_once HUTKO_FC_DIR . '/includes/class-hutko-fc-settings.php';
			require_once HUTKO_FC_DIR . '/includes/IntegrationTypes/Hutko_FC_Hosted.php';
			require_once HUTKO_FC_DIR . '/includes/IntegrationTypes/Hutko_FC_Embedded.php';
			require_once HUTKO_FC_DIR . '/includes/Subscriptions/class-hutko-fc-subscriptions.php';
			require_once HUTKO_FC_DIR . '/includes/class-hutko-fc-gateway.php';

			add_action( 'fluent_cart/register_payment_methods', array( $this, 'registerGateway' ) );
			add_action( 'admin_menu', array( $this, 'registerAdminMenu' ) );
			add_action( 'admin_bar_menu', array( $this, 'registerAdminBarNode' ), 100 );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'pluginActionLinks' ) );

			load_plugin_textdomain(
				'hutko-fluentcart-payment-gateway',
				false,
				basename( HUTKO_FC_DIR ) . '/languages/'
			);
		}

		public function registerGateway( $args ) {
			if ( empty( $args['gatewayManager'] ) ) {
				return;
			}
			$args['gatewayManager']->register( HUTKO_FC_GATEWAY_SLUG, new Hutko_FC_Gateway() );
		}

		private function settingsUrl() {
			return admin_url( 'admin.php?page=fluent-cart#/settings/payments/' . HUTKO_FC_GATEWAY_SLUG );
		}

		public function registerAdminMenu() {
			add_menu_page(
				'Hutko',
				'Hutko',
				'manage_options',
				'hutko-fc-settings',
				array( $this, 'renderRedirectPage' ),
				HUTKO_FC_URL . 'assets/img/hutko-icon.svg',
				58
			);
		}

		public function renderRedirectPage() {
			$url = $this->settingsUrl();
			echo '<script>window.location.replace(' . wp_json_encode( $url ) . ');</script>';
			echo '<div class="wrap"><p>'
				. sprintf(
					wp_kses( /* translators: %s: settings URL */
						__( 'Redirecting to <a href="%s">Hutko settings</a>&hellip;', 'hutko-fluentcart-payment-gateway' ),
						array( 'a' => array( 'href' => true ) )
					),
					esc_url( $url )
				)
				. '</p></div>';
		}

		public function registerAdminBarNode( $wp_admin_bar ) {
			if ( ! current_user_can( 'manage_options' ) || ! is_admin_bar_showing() ) {
				return;
			}
			$wp_admin_bar->add_node(
				array(
					'id'    => 'hutko-fc-settings',
					'title' => 'Hutko',
					'href'  => $this->settingsUrl(),
				)
			);
		}

		public function pluginActionLinks( $links ) {
			$plugin_links = array(
				sprintf(
					'<a href="%1$s">%2$s</a>',
					admin_url( 'admin.php?page=fluent-cart#/settings/payments/' . HUTKO_FC_GATEWAY_SLUG ),
					__( 'Settings', 'hutko-fluentcart-payment-gateway' )
				),
			);
			return array_merge( $plugin_links, $links );
		}

		public function isFluentCartActive() {
			return defined( 'FLUENTCART_VERSION' ) || in_array(
				'fluent-cart/fluent-cart.php',
				apply_filters( 'active_plugins', get_option( 'active_plugins' ) ),
				true
			);
		}

		public function noticeFluentCartMissing() {
			echo '<div class="notice notice-error"><p>'
				. esc_html__(
					'hutko payment for FluentCart requires the FluentCart plugin to be installed and active.',
					'hutko-fluentcart-payment-gateway'
				)
				. '</p></div>';
		}

		public function noticePhpVersion() {
			/* translators: 1) required PHP version 2) current PHP version */
			$message = sprintf(
				__( 'The minimum PHP version required for Hutko FluentCart Payment Gateway is %1$s. You are running %2$s.', 'hutko-fluentcart-payment-gateway' ),
				HUTKO_FC_MIN_PHP_VER,
				phpversion()
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}

		public function __wakeup() {
			throw new Exception( 'Cannot unserialize singleton' );
		}
	}
}

function hutko_fluentcart_gateway_bootstrap() {
	return Hutko_FC::getInstance();
}
