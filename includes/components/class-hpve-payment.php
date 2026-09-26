<?php
/**
 * Payment component: the WooCommerce product, the order and subscription hooks, the Paid Verification settings.
 *
 * Payment never approves. A paid order marks the buyer's request paid and prioritised (or creates a
 * draft and emails them to send documents); a subscription renewal restarts the existing expiry clock;
 * a refund or cancellation is recorded and shown, and never removes a badge. Every decision is a pure
 * call into Verification_Expiry\Logic\Hpve_Payment_Rules so the tests cover it with arrays.
 *
 * The hooks are attached from init, never the constructor: a class_exists() at plugins_loaded
 * autoloads the class and its early translation notices (resources/hivepress-framework.md).
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;
use Verification_Expiry\Logic\Hpve_Payment_Rules as Rules;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration.
 *
 * @class Hpve_Payment
 */
final class Hpve_Payment extends Component {

	/**
	 * Order and subscription meta pointing at the request. Written with update_meta_data(), never
	 * as post meta: core forces HPOS off today and may not for ever.
	 */
	const ORDER_META = '_hpve_request_id';

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		add_action( 'init', [ $this, 'register_hooks' ], 20 );

		add_filter( 'hivepress/v1/settings', [ $this, 'add_settings' ], 35 );

		parent::__construct( $args );
	}

	/**
	 * Attaches the WooCommerce and Subscriptions hooks once both are known to be loaded.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_changed', [ $this, 'update_order_status' ], 10, 4 );
		add_action( 'woocommerce_order_fully_refunded', [ $this, 'refund_order' ], 10, 2 );

		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			add_action( 'woocommerce_subscription_status_updated', [ $this, 'update_subscription_status' ], 10, 3 );
			add_action( 'woocommerce_subscription_renewal_payment_complete', [ $this, 'renew_subscription' ], 10, 2 );
		}
	}

	/*
	--------------------------------------------------------------------------
	Products.
	--------------------------------------------------------------------------
	*/

	/**
	 * The one-off product ID, or 0.
	 *
	 * @return int
	 */
	public function get_product_id() {
		$value = hivepress()->hpve_request->get_option( 'product_id', '' );

		return is_numeric( $value ) ? absint( $value ) : 0;
	}

	/**
	 * The subscription product ID, or 0.
	 *
	 * @return int
	 */
	public function get_subscription_product_id() {
		$value = hivepress()->hpve_request->get_option( 'subscription_product_id', '' );

		return is_numeric( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Both configured product IDs, without zeros.
	 *
	 * @return array
	 */
	public function get_product_ids() {
		return array_values( array_filter( [ $this->get_product_id(), $this->get_subscription_product_id() ] ) );
	}

	/**
	 * Whether a product is set and WooCommerce is here to sell it.
	 *
	 * @return bool
	 */
	public function has_product() {
		return class_exists( 'WooCommerce' ) && $this->get_product_id() > 0 && function_exists( 'wc_get_product' ) && wc_get_product( $this->get_product_id() );
	}

	/**
	 * The product's price as text, for the card.
	 *
	 * @return string
	 */
	public function get_price_text() {
		if ( ! $this->has_product() ) {
			return '';
		}

		$product = wc_get_product( $this->get_product_id() );

		// Cast: WC_Product::get_price() answers a string and wc_price() wants a float.
		return html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) $product->get_price() ) ), ENT_QUOTES, 'UTF-8' );
	}

	/*
	--------------------------------------------------------------------------
	Orders.
	--------------------------------------------------------------------------
	*/

	/**
	 * Marks a request paid when an order containing the product is paid; records refunds and cancellations.
	 *
	 * @param int       $order_id Order ID.
	 * @param string    $from Previous status.
	 * @param string    $to New status.
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function update_order_status( $order_id, $from, $to, $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$decision = Rules::for_order( hivepress()->woocommerce->get_order_product_ids( $order ), $from, $to, $this->get_product_ids() );

		if ( 'ignore' === $decision ) {
			return;
		}

		if ( 'paid' === $decision ) {
			$this->mark_order_paid( $order );

			return;
		}

		$request = $this->get_request_for_order( $order );

		if ( $request ) {
			hivepress()->hpve_request->apply_event( $request, 'refund' === $decision ? 'refund' : 'cancel', [ 'actor' => $this->get_actor() ] );
		}
	}

	/**
	 * Records a full refund made without a status change.
	 *
	 * @param int $order_id Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public function refund_order( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$request = $this->get_request_for_order( $order );

		if ( $request && 'refunded' !== (string) $request->get_payment_state() ) {
			hivepress()->hpve_request->apply_event( $request, 'refund', [ 'actor' => $this->get_actor() ] );
		}
	}

	/**
	 * Marks the buyer's request paid, creating one when they have none. Idempotent per order.
	 *
	 * The buyer is $order->get_user_id(), never the order's post_author, which Marketplace rewrites
	 * to the Vendor (resources/hivepress-data.md).
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	protected function mark_order_paid( $order ) {
		if ( absint( $order->get_meta( self::ORDER_META ) ) ) {
			return;
		}

		$user_id = (int) $order->get_user_id();

		if ( ! $user_id ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Verification Expiry for HivePress: order #' . (int) $order->get_id() . ' contains the verification product but has no customer account, so no request could be marked paid.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a guest checkout cannot be linked; the report tells the owner to require an account.
			}

			return;
		}

		$component = hivepress()->hpve_request;
		$request   = $component->get_for_user( $user_id );
		$created   = false;

		if ( ! $request ) {
			$request = $component->get_or_create( $user_id );
			$created = true;
		}

		if ( ! $request ) {
			return;
		}

		$component->apply_event(
			$request,
			'paid',
			[
				'order_id' => (int) $order->get_id(),
				'actor'    => $this->get_actor(),
			]
		);

		// Written as a string because WC_Data::update_meta_data() takes array|string. Every read of
		// this key goes through absint(), so the stored type makes no difference to behaviour.
		$order->update_meta_data( self::ORDER_META, (string) (int) $request->get_id() );
		$order->save();

		if ( $created || 'draft' === (string) $request->get_status() ) {
			$component->send_email( 'paid', $request );
		}
	}

	/**
	 * The request an order paid for.
	 *
	 * @param \WC_Order $order Order.
	 * @return object|null
	 */
	protected function get_request_for_order( $order ) {
		$request_id = absint( $order->get_meta( self::ORDER_META ) );

		return $request_id ? hivepress()->hpve_request->get_request( $request_id ) : null;
	}

	/*
	--------------------------------------------------------------------------
	Subscriptions.
	--------------------------------------------------------------------------
	*/

	/**
	 * Treats the first activation like a paid order and records every later status.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $to New status.
	 * @param string           $from Previous status.
	 * @return void
	 */
	public function update_subscription_status( $subscription, $to, $from ) {
		if ( ! $subscription || ! is_a( $subscription, 'WC_Subscription' ) ) {
			return;
		}

		$product_id = $this->get_subscription_product_id();

		if ( ! $product_id ) {
			return;
		}

		/*
		 * get_items() with no argument returns line items, which really are WC_Order_Item_Product,
		 * but WooCommerce types the return as the parent WC_Order_Item, which has no
		 * get_product_id(). The instanceof makes that true rather than assumed, so a future
		 * WooCommerce that hands back a fee or shipping line here skips it instead of fataling.
		 */
		$item_ids = [];

		foreach ( $subscription->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$item_ids[] = (int) $item->get_product_id();
			}
		}

		if ( ! in_array( $product_id, $item_ids, true ) ) {
			return;
		}

		$first    = ! absint( $subscription->get_meta( self::ORDER_META ) );
		$decision = Rules::for_subscription( $to, $from, $first );

		if ( 'ignore' === $decision ) {
			return;
		}

		$component = hivepress()->hpve_request;

		if ( 'activate' === $decision ) {
			$user_id = (int) $subscription->get_user_id();

			if ( ! $user_id ) {
				return;
			}

			$request = $component->get_for_user( $user_id );
			$created = false;

			if ( ! $request ) {
				$request = $component->get_or_create( $user_id );
				$created = true;
			}

			if ( ! $request ) {
				return;
			}

			$parent = $subscription->get_parent();

			$component->apply_event(
				$request,
				'subscription_active',
				[
					'subscription_id' => (int) $subscription->get_id(),
					'order_id'        => $parent ? (int) $parent->get_id() : 0,
					'actor'           => $this->get_actor(),
				]
			);

			$subscription->update_meta_data( self::ORDER_META, (string) (int) $request->get_id() );
			$subscription->save();

			$vendor_id = (int) $request->get_vendor__id();

			if ( ! $vendor_id ) {
				$vendor    = $component->get_vendor_for_user( $user_id );
				$vendor_id = $vendor ? (int) $vendor->get_id() : 0;
			}

			if ( $vendor_id ) {
				update_post_meta( $vendor_id, Hpve_Request::META_SUBSCRIPTION_ID, (int) $subscription->get_id() );
			}

			if ( $created || 'draft' === (string) $request->get_status() ) {
				$component->send_email( 'paid', $request );
			}

			return;
		}

		$request_id = absint( $subscription->get_meta( self::ORDER_META ) );
		$request    = $request_id ? $component->get_request( $request_id ) : null;

		if ( ! $request ) {
			return;
		}

		if ( 'reactivate' === $decision ) {
			$component->log( $request->get_id(), 'subscription_active', esc_html__( 'Subscription active again.', 'verification-expiry-for-hivepress' ), [ 'actor' => $this->get_actor() ] );

			return;
		}

		$component->apply_event( $request, 'subscription_' . $decision, [ 'actor' => $this->get_actor() ] );
	}

	/**
	 * Restarts the expiry clock when a renewal is paid, for a verified Vendor only.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param \WC_Order        $last_order The renewal order.
	 * @return void
	 */
	public function renew_subscription( $subscription, $last_order ) {
		if ( ! $subscription || ! is_a( $subscription, 'WC_Subscription' ) ) {
			return;
		}

		$request_id = absint( $subscription->get_meta( self::ORDER_META ) );

		if ( ! $request_id ) {
			return;
		}

		$component = hivepress()->hpve_request;
		$request   = $component->get_request( $request_id );

		if ( ! $request ) {
			return;
		}

		$vendor_id = (int) Models\Vendor::query()->filter( [ 'hpve_subscription_id' => (int) $subscription->get_id() ] )->get_first_id();

		if ( ! $vendor_id ) {
			$vendor_id = (int) $request->get_vendor__id();
		}

		$verified = $vendor_id && get_post_meta( $vendor_id, 'hp_verified', true );

		if ( 'extend' === Rules::for_renewal( (bool) $verified ) ) {
			$until = hivepress()->hpve_verification->start_clock( $vendor_id );

			$component->apply_event(
				$request,
				'renewal',
				[
					'actor'   => $this->get_actor(),
					'message' => '' !== $until
						/* translators: %s: a date. */
						? sprintf( esc_html__( 'Renewal paid, verified until %s.', 'verification-expiry-for-hivepress' ), hivepress()->hpve_verification->format_date( $until ) )
						: esc_html__( 'Renewal paid; the verification does not expire.', 'verification-expiry-for-hivepress' ),
				]
			);

			return;
		}

		$component->apply_event(
			$request,
			'renewal',
			[
				'actor'   => $this->get_actor(),
				'message' => esc_html__( 'Renewal paid, but the Vendor is not verified, so there was nothing to extend.', 'verification-expiry-for-hivepress' ),
			]
		);
	}

	/**
	 * The actor recorded on every line WooCommerce writes.
	 *
	 * @return array
	 */
	protected function get_actor() {
		return [
			'name'    => 'WooCommerce',
			'user_id' => 0,
			'email'   => '',
		];
	}

	/*
	--------------------------------------------------------------------------
	Settings.
	--------------------------------------------------------------------------
	*/

	/**
	 * Adds the Paid Verification section.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings( $settings ) {
		if ( ! isset( $settings[ Hpve_Verification::SETTINGS_TAB ]['sections'] ) ) {
			return $settings;
		}

		$has_wc  = class_exists( 'WooCommerce' );
		$has_wcs = function_exists( 'wcs_get_subscriptions_for_order' );

		$description = esc_html__( 'Choose a WooCommerce product and Vendors see a Buy button; a paid request is marked Paid and sorted first in the queue. Payment never approves anybody: documents are still checked. Use a virtual, non-downloadable product whose author is an administrator without a Vendor profile, so the order is not credited to a Vendor as a sale. A subscription renewal restarts the verification period; a refund is recorded on the request but never removes a badge. Guest checkout cannot be linked to an account, so require an account for this product.', 'verification-expiry-for-hivepress' );

		if ( ! $has_wc ) {
			$description .= ' ' . esc_html__( 'WooCommerce is not active, so these settings do nothing until it is.', 'verification-expiry-for-hivepress' );
		}

		$fields = [
			HPVE_OPTION_PREFIX . 'product_id' => [
				'label'       => esc_html__( 'Verification Product', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'The product a Vendor buys for a one-off verification. The list is loaded fresh each time this tab opens. Leave blank for free verification only.', 'verification-expiry-for-hivepress' ),
				'type'        => 'select',
				'options'     => 'posts',
				'option_args' => [ 'post_type' => 'product' ],
				'_order'      => 10,
			],
		];

		if ( $has_wcs ) {
			$fields[ HPVE_OPTION_PREFIX . 'subscription_product_id' ] = [
				'label'       => esc_html__( 'Subscription Product', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'Optional. A subscription product whose renewals restart the verification period. Leave blank if verification is not a subscription.', 'verification-expiry-for-hivepress' ),
				'type'        => 'select',
				'options'     => 'posts',
				'option_args' => [ 'post_type' => 'product' ],
				'_order'      => 20,
			];
		} else {
			$description .= ' ' . esc_html__( 'A subscription product can be chosen once WooCommerce Subscriptions is active.', 'verification-expiry-for-hivepress' );
		}

		$fields[ HPVE_OPTION_PREFIX . 'payment_required' ] = [
			'label'       => esc_html__( 'Payment Required', 'verification-expiry-for-hivepress' ),
			'caption'     => esc_html__( 'Vendors must pay before they can send documents', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'With this ticked and a product chosen, an unpaid Vendor sees a Buy button instead of the form. Unticked, payment is optional and only affects the queue order.', 'verification-expiry-for-hivepress' ),
			'type'        => 'checkbox',
			'_order'      => 30,
		];

		$fields[ HPVE_OPTION_PREFIX . 'paid_priority' ] = [
			'label'       => esc_html__( 'Paid First', 'verification-expiry-for-hivepress' ),
			'caption'     => esc_html__( 'Sort paid requests to the top of the queue', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'Paid requests are also marked Paid in the list either way.', 'verification-expiry-for-hivepress' ),
			'type'        => 'checkbox',
			'default'     => true,
			'_order'      => 40,
		];

		$settings[ Hpve_Verification::SETTINGS_TAB ]['sections'][ HPVE_OPTION_PREFIX . 'payment' ] = [
			'title'       => esc_html__( 'Paid Verification', 'verification-expiry-for-hivepress' ),
			'description' => $description,
			'_order'      => 50,
			'fields'      => $fields,
		];

		return $settings;
	}
}
