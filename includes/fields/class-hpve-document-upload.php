<?php
/**
 * Document upload field.
 *
 * A subclass of core's Attachment_Upload whose class meta name is "hpve_document_upload", so core's
 * own upload endpoint refuses it (controllers/class-attachment.php:155 accepts only the exact name
 * "attachment_upload") and the plugin's own route, which checks ownership, status, payment, the
 * per-type size and format, takes the file instead. Core's uploader script is untouched: it reads
 * data-url, data-max-size and data-messages off the input (assets/js/common.js:841-884).
 *
 * The label meta is null so the type never appears in the attribute "Field Type" list.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Fields;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Private document upload.
 */
class Hpve_Document_Upload extends Attachment_Upload {

	/**
	 * Per-type size limit in bytes.
	 *
	 * @var int
	 */
	protected $max_bytes = 0;

	/**
	 * Document type key.
	 *
	 * @var string
	 */
	protected $doc_key = '';

	/**
	 * The request the files belong to, so existing files can be listed.
	 *
	 * @var int
	 */
	protected $request_id = 0;

	/**
	 * Whether the current viewer may remove files.
	 *
	 * @var bool
	 */
	protected $editable = true;

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			$meta,
			[
				'label'    => null,
				'settings' => [],
			]
		);

		parent::init( $meta );
	}

	/**
	 * Sets the request ID.
	 *
	 * @param int $request_id Request ID.
	 */
	protected function set_request_id( $request_id ) {
		$this->request_id = absint( $request_id );
	}

	/**
	 * Sets the editable flag.
	 *
	 * @param bool $editable Editable.
	 */
	protected function set_editable( $editable ) {
		$this->editable = (bool) $editable;
	}

	/**
	 * Gets the document type key.
	 *
	 * @return string
	 */
	public function get_doc_key() {
		return $this->doc_key;
	}

	/**
	 * Gets the size limit in bytes.
	 *
	 * @return int
	 */
	public function get_max_bytes() {
		return $this->max_bytes;
	}

	/**
	 * The files already uploaded for this request and type.
	 *
	 * @return array
	 */
	protected function get_attachments() {
		if ( ! $this->request_id ) {
			return [];
		}

		return Models\Attachment::query()->filter(
			[
				'parent_model' => 'hpve_request',
				'parent_field' => $this->name,
				'parent'       => $this->request_id,
			]
		)->order( [ 'id' => 'asc' ] )
		->get()
		->serialize();
	}

	/**
	 * Renders the field: the file rows, the messages box and the upload button.
	 *
	 * @return string
	 */
	public function render() {
		$output = '<div ' . hp\html_attributes( $this->attributes ) . '>';

		$id = $this->name . '_' . uniqid();

		$output .= '<div class="hpve-files">';

		foreach ( $this->get_attachments() as $attachment ) {
			$output .= $this->render_attachment( $attachment );
		}

		$output .= '</div>';

		$output .= '<div class="hp-form__messages hp-form__messages--error" data-component="messages"></div>';

		if ( $this->editable ) {
			$max_size = $this->max_bytes > 0 ? min( $this->max_bytes, wp_max_upload_size() ) : wp_max_upload_size();

			$output .= '<label for="' . esc_attr( $id ) . '">';

			$output .= ( new Button(
				[
					'label' => $this->caption,
				]
			) )->render();

			$output .= ( new File(
				[
					'name'       => $this->name,
					'multiple'   => true,
					'formats'    => $this->formats,
					'disabled'   => true,

					'attributes' => [
						'id'             => $id,
						'data-component' => 'file-upload',
						'data-name'      => hp\unprefix( $this->name ),
						'data-url'       => esc_url( hivepress()->router->get_url( 'hpve_document_upload_action' ) ),
						'data-max-size'  => $max_size,
						'data-max-files' => $this->max_files,

						'data-messages'  => wp_json_encode(
							[
								/* translators: %s: a file size such as 10 MB. */
								'max_size'  => sprintf( esc_html__( 'The file size must not exceed %s.', 'verification-expiry-for-hivepress' ), size_format( $max_size ) ),
								/* translators: %s: number of files. */
								'max_files' => sprintf( esc_html__( 'Only up to %s files can be uploaded for this document.', 'verification-expiry-for-hivepress' ), number_format_i18n( $this->max_files ) ),
							]
						),
					],
				]
			) )->render();

			$output .= '</label>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Renders one file row: name, size, a View link through the gate and a delete cross.
	 *
	 * The delete cross reuses core's file-delete component, which sends a DELETE to data-url with
	 * the REST nonce and removes the row (assets/js/common.js:1352-1366).
	 *
	 * @param object $attachment Attachment object.
	 * @return string
	 */
	public function render_attachment( $attachment ) {
		$size = absint( get_post_meta( $attachment->get_id(), 'hp_hpve_doc_size', true ) );
		$name = (string) get_the_title( $attachment->get_id() );

		$output = '<div class="hpve-file" data-id="' . esc_attr( $attachment->get_id() ) . '">';

		$output .= '<i class="hp-icon fas fa-fw ' . ( 'application/pdf' === (string) $attachment->get_mime_type() ? 'fa-file-pdf' : 'fa-file-image' ) . '"></i>';
		$output .= '<span class="hpve-file__name">' . esc_html( $name ) . '</span>';

		if ( $size ) {
			$output .= '<span class="hpve-file__size hp-meta">' . esc_html( size_format( $size ) ) . '</span>';
		}

		$output .= '<a href="' . esc_url( hivepress()->hpve_storage->get_gate_url( $attachment->get_id() ) ) . '" target="_blank" rel="noopener" class="hp-link hpve-file__view"><span>' . esc_html__( 'View', 'verification-expiry-for-hivepress' ) . '</span></a>';

		if ( $this->editable ) {
			$output .= '<a href="#" title="' . esc_attr__( 'Remove', 'verification-expiry-for-hivepress' ) . '" class="hp-field__button hp-field__button--delete hpve-file__delete" data-component="file-delete" data-url="' . esc_url( hivepress()->router->get_url( 'hpve_document_delete_action', [ 'attachment_id' => $attachment->get_id() ] ) ) . '"><i class="hp-icon fas fa-times"></i></a>';
		}

		$output .= '</div>';

		return $output;
	}
}
