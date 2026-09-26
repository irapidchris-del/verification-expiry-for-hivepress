<?php
/**
 * WooCommerce decision rules.
 *
 * Pure PHP. The payment component hands these functions arrays and strings read from the order
 * or subscription, so the decisions can be tested without WooCommerce loaded.
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Decides what an order or subscription event means for a request.
 */
final class Hpve_Payment_Rules {

	/**
	 * Statuses that mean the money has arrived.
	 *
	 * A virtual, non-downloadable product settles on "processing" and never reaches
	 * "completed" on its own (resources/hivepress-data.md, Marketplace notes), so both count.
	 */
	const PAID_STATUSES = [ 'processing', 'completed' ];

	/**
	 * What an order status change means.
	 *
	 * @param array  $product_ids Product IDs on the order.
	 * @param string $from Previous status, without the "wc-" prefix.
	 * @param string $to New status.
	 * @param array  $configured_ids The product IDs chosen in settings (0 and '' ignored).
	 * @return string 'paid' | 'refund' | 'cancel' | 'ignore'
	 */
	public static function for_order( array $product_ids, $from, $to, array $configured_ids ) {
		$configured_ids = array_filter( array_map( 'intval', $configured_ids ) );
		$product_ids    = array_map( 'intval', $product_ids );

		if ( ! $configured_ids || ! array_intersect( $product_ids, $configured_ids ) ) {
			return 'ignore';
		}

		$from = (string) $from;
		$to   = (string) $to;

		if ( in_array( $to, self::PAID_STATUSES, true ) ) {
			return in_array( $from, self::PAID_STATUSES, true ) ? 'ignore' : 'paid';
		}

		if ( 'refunded' === $to ) {
			return 'refund';
		}

		if ( in_array( $to, [ 'cancelled', 'failed' ], true ) ) {
			return 'cancel';
		}

		return 'ignore';
	}

	/**
	 * What a subscription status change means.
	 *
	 * @param string $to New status.
	 * @param string $from Previous status.
	 * @param bool   $first_activation Whether this subscription has never been recorded on a request.
	 * @return string 'activate' | 'reactivate' | 'on_hold' | 'cancelled' | 'expired' | 'pending_cancel' | 'ignore'
	 */
	public static function for_subscription( $to, $from, $first_activation ) {
		$to = (string) $to;

		switch ( $to ) {
			case 'active':
				return $first_activation ? 'activate' : 'reactivate';
			case 'on-hold':
				return 'on_hold';
			case 'cancelled':
				return 'cancelled';
			case 'expired':
				return 'expired';
			case 'pending-cancel':
				return 'pending_cancel';
		}

		return 'ignore';
	}

	/**
	 * What a paid renewal means.
	 *
	 * @param bool $vendor_verified Whether the Vendor is verified right now.
	 * @return string 'extend' | 'log'
	 */
	public static function for_renewal( $vendor_verified ) {
		return $vendor_verified ? 'extend' : 'log';
	}

	/**
	 * The payment state stored on the request after an event.
	 *
	 * @param string $event The apply_event() name.
	 * @return string
	 */
	public static function payment_state_for_event( $event ) {
		$map = [
			'paid'                        => 'paid',
			'refund'                      => 'refunded',
			'cancel'                      => 'cancelled',
			'subscription_active'         => 'paid',
			'subscription_on_hold'        => 'on_hold',
			'subscription_cancelled'      => 'cancelled',
			'subscription_expired'        => 'expired',
			'subscription_pending_cancel' => 'paid',
			'renewal'                     => 'paid',
		];

		return isset( $map[ $event ] ) ? $map[ $event ] : '';
	}
}
