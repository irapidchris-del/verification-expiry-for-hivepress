<?php
/**
 * The read-only list of documents shown while a request is pending.
 *
 * @package Verification_Expiry\Templates
 */

use Verification_Expiry\Logic\Hpve_Document_Types as Doc_Types;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpve_documents = isset( $documents ) ? (array) $documents : [];
$hpve_types     = isset( $types ) ? (array) $types : [];

if ( ! $hpve_documents ) {
	return;
}
?>
<div class="hp-block hpve-documents">
	<h3 class="hp-section__title"><?php esc_html_e( 'Your documents', 'verification-expiry-for-hivepress' ); ?></h3>
	<div class="hpve-files">
		<?php
		foreach ( $hpve_documents as $hpve_document ) :
			$hpve_key  = Doc_Types::key_from_field( (string) $hpve_document->get_parent_field() );
			$hpve_row  = '' !== $hpve_key ? Doc_Types::find( $hpve_types, $hpve_key ) : null;
			$hpve_size = absint( get_post_meta( $hpve_document->get_id(), 'hp_hpve_doc_size', true ) );
			?>
			<div class="hpve-file">
				<i class="hp-icon fas fa-fw <?php echo 'application/pdf' === (string) $hpve_document->get_mime_type() ? 'fa-file-pdf' : 'fa-file-image'; ?>"></i>
				<span class="hpve-file__name"><?php echo esc_html( $hpve_row ? $hpve_row['label'] . ': ' : '' ); ?><?php echo esc_html( get_the_title( $hpve_document->get_id() ) ); ?></span>
				<?php if ( $hpve_size ) : ?>
					<span class="hpve-file__size hp-meta"><?php echo esc_html( size_format( $hpve_size ) ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( hivepress()->hpve_storage->get_gate_url( $hpve_document->get_id() ) ); ?>" target="_blank" rel="noopener" class="hp-link hpve-file__view"><span><?php esc_html_e( 'View', 'verification-expiry-for-hivepress' ); ?></span></a>
			</div>
		<?php endforeach; ?>
	</div>
</div>
