<?php
/**
 * The page a provider's hosted flow sends the applicant back to.
 *
 * @package Verification_Expiry\Templates
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>
<div class="hp-block hpve-card hpve-card--processing">
	<div class="hpve-card__header">
		<h2 class="hp-section__title"><?php esc_html_e( 'Verification', 'verification-expiry-for-hivepress' ); ?></h2>
		<span class="hp-status hp-status--pending"><span><?php esc_html_e( 'Being checked', 'verification-expiry-for-hivepress' ); ?></span></span>
	</div>
	<p><?php esc_html_e( 'Thanks, Stripe is checking your document. This normally takes a few minutes and we will email you when it is done.', 'verification-expiry-for-hivepress' ); ?></p>
	<div class="hpve-card__actions">
		<a href="<?php echo esc_url( hivepress()->router->get_url( 'hpve_verification_page' ) ); ?>" class="hp-button button button--secondary"><span><?php esc_html_e( 'Back to Verification', 'verification-expiry-for-hivepress' ); ?></span></a>
	</div>
</div>
