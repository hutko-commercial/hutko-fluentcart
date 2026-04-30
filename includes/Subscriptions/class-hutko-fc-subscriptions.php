<?php
/**
 * hutko subscription module (FluentCart).
 *
 * hutko does not manage subscription state remotely — renewals are
 * driven by our own daily cron that re-charges via rectoken.
 *
 * @package Hutko_FluentCart_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;

class Hutko_FC_Subscriptions extends AbstractSubscriptionModule {

	public function cancel( $vendorSubscriptionId, $args = array() ) {
		return array(
			'status'      => Status::SUBSCRIPTION_CANCELED,
			'canceled_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public function reSyncSubscriptionFromRemote( Subscription $subscriptionModel ) {
		return $subscriptionModel;
	}

	public function cancelSubscription( $data, $order, $subscription ) {
		$subscription->status      = Status::SUBSCRIPTION_CANCELED;
		$subscription->canceled_at = gmdate( 'Y-m-d H:i:s' );
		$subscription->save();

		return array(
			'status'      => Status::SUBSCRIPTION_CANCELED,
			'canceled_at' => $subscription->canceled_at,
		);
	}

	public function pauseSubscription( $data, $order, $subscription ) {
		$subscription->status = Status::SUBSCRIPTION_PAUSED;
		$subscription->save();
		return array( 'status' => Status::SUBSCRIPTION_PAUSED );
	}

	public function resumeSubscription( $data, $order, $subscription ) {
		$subscription->status = Status::SUBSCRIPTION_ACTIVE;
		$subscription->save();
		return array( 'status' => Status::SUBSCRIPTION_ACTIVE );
	}

	public function cancelAutoRenew( $subscription ) {
		if ( ! $subscription ) {
			return;
		}
		$subscription->next_billing_date = null;
		$subscription->save();
	}
}
