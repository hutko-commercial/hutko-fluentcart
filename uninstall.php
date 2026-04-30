<?php
/**
 * Uninstall script for hutko FluentCart Payment Gateway.
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'fluent_cart_payment_settings_hutko' );
delete_option( 'hutko_fluentcart_version' );

$timestamp = wp_next_scheduled( 'hutko_fc_process_renewals' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'hutko_fc_process_renewals' );
}
