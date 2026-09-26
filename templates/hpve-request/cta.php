<?php
/**
 * The "Get verified" call to action on the Vendor dashboard and account settings page.
 *
 * @package Verification_Expiry\Templates
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>
<div class="hp-block hpve-cta">
	<p>
		<?php if ( ! empty( $expired ) ) : ?>
			<?php esc_html_e( 'Your verified badge has expired. Send your documents again to get it back.', 'verification-expiry-for-hivepress' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'A verified badge shows clients that we have checked who you are.', 'verification-expiry-for-hivepress' ); ?>
		<?php endif; ?>
	</p>
	<a href="<?php echo esc_url( isset( $verification_url ) ? $verification_url : '' ); ?>" class="hp-button button button--primary"><span><?php esc_html_e( 'Get verified', 'verification-expiry-for-hivepress' ); ?></span></a>
</div>
