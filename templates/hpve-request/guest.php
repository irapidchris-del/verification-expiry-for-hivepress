<?php
/**
 * The verification block for a visitor who is not signed in.
 *
 * @package Verification_Expiry\Templates
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>
<div class="hp-block hpve-card hpve-card--guest">
	<div class="hpve-card__header">
		<h2 class="hp-section__title"><?php esc_html_e( 'Verification', 'verification-expiry-for-hivepress' ); ?></h2>
	</div>
	<p><?php esc_html_e( 'Sign in to start your verification.', 'verification-expiry-for-hivepress' ); ?></p>
	<div class="hpve-card__actions">
		<a href="<?php echo esc_url( isset( $login_url ) ? $login_url : '' ); ?>" class="hp-button button button--primary"><span><?php esc_html_e( 'Sign in', 'verification-expiry-for-hivepress' ); ?></span></a>
		<a href="<?php echo esc_url( isset( $register_url ) ? $register_url : '' ); ?>" class="hp-button button button--secondary"><span><?php esc_html_e( 'Register', 'verification-expiry-for-hivepress' ); ?></span></a>
	</div>
</div>
