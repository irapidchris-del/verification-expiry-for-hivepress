<?php
/**
 * Payment box on the review screen.
 *
 * @package Verification_Expiry\Templates
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*
 * Set by Hpve_Review::render_box() before this file is included.
 */
/** @var \HivePress\Models\Hpve_Request $request */
/** @var \HivePress\Components\Hpve_Review $review */

$hpve_order_id        = (int) $request->get_order_id();
$hpve_subscription_id = (int) $request->get_subscription_id();
?>
<div class="hpve-payment">
	<p><?php echo esc_html( $review->get_payment_text( $request ) ); ?></p>
	<?php if ( $hpve_order_id && function_exists( 'wc_get_order' ) && wc_get_order( $hpve_order_id ) ) : ?>
		<p><a href="<?php echo esc_url( wc_get_order( $hpve_order_id )->get_edit_order_url() ); ?>"><?php echo esc_html( sprintf( /* translators: %s: the order number. */ __( 'Order #%s', 'verification-expiry-for-hivepress' ), $hpve_order_id ) ); ?></a></p>
	<?php endif; ?>
	<?php if ( $hpve_subscription_id && function_exists( 'wcs_get_subscription' ) && wcs_get_subscription( $hpve_subscription_id ) ) : ?>
		<p><a href="<?php echo esc_url( wcs_get_subscription( $hpve_subscription_id )->get_edit_order_url() ); ?>"><?php echo esc_html( sprintf( /* translators: %s: the subscription number. */ __( 'Subscription #%s', 'verification-expiry-for-hivepress' ), $hpve_subscription_id ) ); ?></a></p>
	<?php endif; ?>
	<?php if ( 'refunded' === (string) $request->get_payment_state() ) : ?>
		<p class="description"><?php esc_html_e( 'The refund did not change the verification. Untick the Verified box on the Vendor if it should.', 'verification-expiry-for-hivepress' ); ?></p>
	<?php endif; ?>
</div>
