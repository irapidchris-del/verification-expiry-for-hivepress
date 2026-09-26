<?php
/**
 * Applicant box on the review screen.
 *
 * @package Verification_Expiry\Templates
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*
 * Set by Hpve_Review::render_box() before this file is included.
 */
/** @var \HivePress\Models\Hpve_Request $request */

$hpve_user      = get_userdata( (int) $request->get_user__id() );
$hpve_vendor_id = (int) $request->get_vendor__id();
?>
<div class="hpve-applicant">
	<?php if ( $hpve_user ) : ?>
		<p>
			<strong><?php echo esc_html( $hpve_user->display_name ); ?></strong><br>
			<a href="mailto:<?php echo esc_attr( $hpve_user->user_email ); ?>"><?php echo esc_html( $hpve_user->user_email ); ?></a><br>
			<span class="description">
				<?php
				/* translators: %s: a date. */
				echo esc_html( sprintf( __( 'Registered %s', 'verification-expiry-for-hivepress' ), mysql2date( get_option( 'date_format' ), $hpve_user->user_registered ) ) );
				?>
			</span>
		</p>
	<?php endif; ?>

	<?php if ( $hpve_vendor_id ) : ?>
		<p>
			<a href="<?php echo esc_url( get_edit_post_link( $hpve_vendor_id ) ); ?>"><?php echo esc_html( get_the_title( $hpve_vendor_id ) ); ?></a><br>
			<span class="description">
				<?php
				$hpve_until = (string) get_post_meta( $hpve_vendor_id, \HivePress\Components\Hpve_Verification::META_UNTIL, true );

				if ( get_post_meta( $hpve_vendor_id, 'hp_verified', true ) ) {
					/* translators: %s: date. */
					echo esc_html( '' !== $hpve_until ? sprintf( __( 'Verified until %s', 'verification-expiry-for-hivepress' ), hivepress()->hpve_verification->format_date( $hpve_until ) ) : __( 'Verified', 'verification-expiry-for-hivepress' ) );
				} else {
					esc_html_e( 'Not verified', 'verification-expiry-for-hivepress' );
				}

				$hpve_listings = \HivePress\Models\Listing::query()->filter(
					[
						'vendor'     => $hpve_vendor_id,
						'status__in' => [ 'draft', 'pending', 'publish' ],
					]
				)->get_count();

				/* translators: %d: number of Listings. */
				echo esc_html( ' ' . sprintf( _n( '%d Listing', '%d Listings', $hpve_listings, 'verification-expiry-for-hivepress' ), $hpve_listings ) );
				?>
			</span>
		</p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No Vendor profile yet. The request can be approved once the applicant has added a Listing.', 'verification-expiry-for-hivepress' ); ?></p>
	<?php endif; ?>
</div>
