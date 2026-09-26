<?php
/**
 * Documents box on the review screen.
 *
 * Every preview and link goes through the gate; nothing here prints a file path.
 *
 * @package Verification_Expiry\Templates
 */

use Verification_Expiry\Logic\Hpve_Document_Types as Doc_Types;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*
 * Set by Hpve_Review::render_box() before this file is included.
 */
/** @var \HivePress\Models\Hpve_Request $request */

$hpve_id        = (int) $request->get_id();
$hpve_types     = hivepress()->hpve_request->get_document_types( true );
$hpve_documents = hivepress()->hpve_request->get_documents( $hpve_id );
$hpve_by_key    = [];

foreach ( $hpve_documents as $hpve_document ) {
	$hpve_key = Doc_Types::key_from_field( (string) $hpve_document->get_parent_field() );

	if ( '' !== $hpve_key ) {
		$hpve_by_key[ $hpve_key ][] = $hpve_document;
	}
}
?>
<div class="hpve-documents">
	<?php if ( 'stripe_identity' === (string) $request->get_provider() && $request->get_provider_ref() ) : ?>
		<p>
			<?php esc_html_e( 'Stripe holds the identity document and selfie for this request.', 'verification-expiry-for-hivepress' ); ?>
			<a href="<?php echo esc_url( 'https://dashboard.stripe.com/' . ( $request->is_provider_livemode() ? '' : 'test/' ) . 'identity/verification-sessions/' . rawurlencode( (string) $request->get_provider_ref() ) ); ?>" target="_blank" rel="noopener" class="button"><?php esc_html_e( 'Open in Stripe Dashboard', 'verification-expiry-for-hivepress' ); ?></a>
		</p>
	<?php endif; ?>

	<?php if ( $request->get_docs_deleted_time() ) : ?>
		<p class="description">
			<?php
			/* translators: %s: a date. */
			echo esc_html( sprintf( __( 'Documents were deleted on %s.', 'verification-expiry-for-hivepress' ), wp_date( get_option( 'date_format' ), (int) $request->get_docs_deleted_time() ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php foreach ( $hpve_types as $hpve_row ) : ?>
		<div class="hpve-doc-type">
			<h4>
				<?php echo esc_html( $hpve_row['label'] ); ?>
				<?php if ( $hpve_row['required'] ) : ?>
					<span class="description"><?php esc_html_e( '(required)', 'verification-expiry-for-hivepress' ); ?></span>
				<?php endif; ?>
				<?php if ( ! $hpve_row['enabled'] ) : ?>
					<span class="description"><?php esc_html_e( '(no longer asked for)', 'verification-expiry-for-hivepress' ); ?></span>
				<?php endif; ?>
			</h4>
			<?php if ( empty( $hpve_by_key[ $hpve_row['key'] ] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing uploaded.', 'verification-expiry-for-hivepress' ); ?></p>
			<?php else : ?>
				<?php
				foreach ( $hpve_by_key[ $hpve_row['key'] ] as $hpve_document ) :
					$hpve_url   = hivepress()->hpve_storage->get_gate_url( $hpve_document->get_id() );
					$hpve_size  = absint( get_post_meta( $hpve_document->get_id(), 'hp_hpve_doc_size', true ) );
					$hpve_image = 0 === strpos( (string) $hpve_document->get_mime_type(), 'image/' );
					?>
					<div class="hpve-doc">
						<?php if ( $hpve_image ) : ?>
							<a href="<?php echo esc_url( $hpve_url ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $hpve_url ); ?>" alt="" class="hpve-doc__preview"></a>
						<?php endif; ?>
						<div class="hpve-doc__meta">
							<a href="<?php echo esc_url( $hpve_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $hpve_document->get_id() ) ); ?></a>
							<span class="description">
								<?php echo esc_html( $hpve_size ? size_format( $hpve_size ) : '' ); ?>
								<?php echo esc_html( get_the_date( get_option( 'date_format' ), $hpve_document->get_id() ) ); ?>
							</span>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php if ( $request->get_applicant_note() ) : ?>
		<blockquote class="hpve-doc-note"><strong><?php esc_html_e( 'From the applicant:', 'verification-expiry-for-hivepress' ); ?></strong><br><?php echo esc_html( $request->get_applicant_note() ); ?></blockquote>
	<?php endif; ?>

	<?php if ( $hpve_documents ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hpve-documents__delete" onsubmit="return window.confirm( <?php echo esc_attr( wp_json_encode( __( 'Delete every document on this request now? This cannot be undone.', 'verification-expiry-for-hivepress' ) ) ); ?> );">
			<input type="hidden" name="action" value="hpve_delete_documents">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $hpve_id ); ?>">
			<?php wp_nonce_field( 'hpve_delete_documents_' . $hpve_id ); ?>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete all documents now', 'verification-expiry-for-hivepress' ); ?></button>
		</form>
	<?php endif; ?>
</div>
