<?php
/**
 * Decision box on the review screen.
 *
 * Every button posts to admin-post.php with the request's own nonce; the handler checks it and
 * the edit_post capability before anything changes. Variables come from Hpve_Review::render_box().
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
/** @var string $state */

$hpve_id        = (int) $request->get_id();
$hpve_status    = (string) $request->get_status();
$hpve_vendor_id = (int) $request->get_vendor__id();
$hpve_expiry    = hivepress()->hpve_verification;
$hpve_period    = $hpve_vendor_id ? $hpve_expiry->resolve_period( $hpve_vendor_id ) : '';
$hpve_periods   = \HivePress\Components\Hpve_Verification::get_periods();
$hpve_until     = $hpve_vendor_id ? $hpve_expiry->calculate_until( $hpve_period ) : '';
?>
<div class="hpve-decision">
	<p class="hpve-decision__summary">
		<?php echo $review->render_pill( $state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_pill(). ?>
		<?php if ( $request->get_submitted_time() ) : ?>
			<span class="description">
				<?php
				/* translators: %s: a date and time. */
				echo esc_html( sprintf( __( 'Submitted %s', 'verification-expiry-for-hivepress' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $request->get_submitted_time() ) ) );
				?>
			</span>
		<?php endif; ?>
		<?php if ( $request->get_provider_status() && 'stripe_identity' === (string) $request->get_provider() ) : ?>
			<span class="description"><?php echo esc_html( 'Stripe: ' . $request->get_provider_status() . ( $request->get_provider_error() ? ' (' . $request->get_provider_error() . ')' : '' ) ); ?></span>
		<?php endif; ?>
	</p>

	<?php if ( 'pending' === $hpve_status ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hpve-decision__form">
			<input type="hidden" name="action" value="hpve_review">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $hpve_id ); ?>">
			<?php wp_nonce_field( 'hpve_review_' . $hpve_id ); ?>

			<div class="hpve-decision__block">
				<button type="submit" name="decision" value="approve" class="button button-primary" <?php disabled( ! $hpve_vendor_id ); ?>><?php esc_html_e( 'Approve', 'verification-expiry-for-hivepress' ); ?></button>
				<span class="description">
					<?php if ( ! $hpve_vendor_id ) : ?>
						<?php esc_html_e( 'This applicant has no Vendor profile yet, so there is no Verified box to tick.', 'verification-expiry-for-hivepress' ); ?>
					<?php elseif ( '' !== $hpve_until ) : ?>
						<?php
						/* translators: 1: a date, 2: a period such as "1 year". */
						echo esc_html( sprintf( __( 'Verified until %1$s (Vendor\'s period: %2$s).', 'verification-expiry-for-hivepress' ), $hpve_expiry->format_date( $hpve_until ), isset( $hpve_periods[ $hpve_period ] ) ? $hpve_periods[ $hpve_period ] : $hpve_period ) );
						?>
					<?php else : ?>
						<?php esc_html_e( 'The verification will not expire (no period is set for this Vendor or the site).', 'verification-expiry-for-hivepress' ); ?>
					<?php endif; ?>
				</span>
			</div>

			<div class="hpve-decision__block">
				<label for="hpve_note"><strong><?php esc_html_e( 'Ask for more information', 'verification-expiry-for-hivepress' ); ?></strong></label>
				<textarea id="hpve_note" name="hpve_note" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'What do you need from them? This is emailed to the applicant.', 'verification-expiry-for-hivepress' ); ?>"></textarea>
				<button type="submit" name="decision" value="needs_info" class="button"><?php esc_html_e( 'Ask for more information', 'verification-expiry-for-hivepress' ); ?></button>
			</div>

			<div class="hpve-decision__block">
				<label for="hpve_reason"><strong><?php esc_html_e( 'Reject', 'verification-expiry-for-hivepress' ); ?></strong></label>
				<textarea id="hpve_reason" name="hpve_reason" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Reason (sent to the applicant). Required.', 'verification-expiry-for-hivepress' ); ?>"></textarea>
				<button type="submit" name="decision" value="reject" class="button hpve-decision__reject" disabled><?php esc_html_e( 'Reject', 'verification-expiry-for-hivepress' ); ?></button>
			</div>
		</form>
	<?php else : ?>
		<?php if ( $request->get_note() ) : ?>
			<p><strong><?php esc_html_e( 'Note sent:', 'verification-expiry-for-hivepress' ); ?></strong><br><?php echo esc_html( $request->get_note() ); ?></p>
		<?php endif; ?>
		<?php if ( $request->get_reason() ) : ?>
			<p><strong><?php esc_html_e( 'Reason sent:', 'verification-expiry-for-hivepress' ); ?></strong><br><?php echo esc_html( $request->get_reason() ); ?></p>
		<?php endif; ?>
		<?php if ( 'trash' !== $hpve_status && ( 'publish' === $hpve_status || in_array( (string) $request->get_outcome(), [ 'needs_info', 'rejected' ], true ) ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hpve_review">
				<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $hpve_id ); ?>">
				<?php wp_nonce_field( 'hpve_review_' . $hpve_id ); ?>
				<button type="submit" name="decision" value="reopen" class="button"><?php esc_html_e( 'Re-open for review', 'verification-expiry-for-hivepress' ); ?></button>
				<?php if ( 'publish' === $hpve_status ) : ?>
					<span class="description"><?php esc_html_e( 'Unticks the Verified box on the Vendor until a new decision is made.', 'verification-expiry-for-hivepress' ); ?></span>
				<?php endif; ?>
			</form>
		<?php elseif ( 'draft' === $hpve_status ) : ?>
			<p class="description"><?php esc_html_e( 'The applicant has not sent this request for review yet.', 'verification-expiry-for-hivepress' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
