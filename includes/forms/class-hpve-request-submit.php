<?php
/**
 * Request submit form.
 *
 * A model form over the request, so it carries data-model and data-id (forms/class-model-form.php:43-56,
 * core 1.7.31), which is what core's uploader reads to name the parent of each file. The upload
 * fields are disabled by inheritance, so the REST save never writes them; the submit action validates
 * the required types by querying attachments and checks the consent box server-side.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Forms;

use HivePress\Helpers as hp;
use Verification_Expiry\Logic\Hpve_Document_Types as Doc_Types;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sends a request for review.
 */
class Hpve_Request_Submit extends Model_Form {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'model' => 'hpve_request',
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Form arguments.
	 */
	public function __construct( $args = [] ) {
		$model      = hp\get_array_value( $args, 'model' );
		$request_id = is_object( $model ) ? (int) $model->get_id() : 0;
		$attempts   = is_object( $model ) ? (int) $model->get_attempts() : 0;
		$outcome    = is_object( $model ) ? (string) $model->get_outcome() : '';

		$fields = [];
		$order  = 10;

		foreach ( hivepress()->hpve_request->get_document_types() as $row ) {
			$label = $row['label'];

			// The field itself can never be required (uploads save through their own endpoint, so
			// the form never sees a value), which makes HivePress append "(optional)" to every
			// label. Up to 2.1.0 a required type ALSO said "(required)", so Photo ID read
			// "Photo ID (required) (optional)". HivePress's own convention is the fix: a required
			// field carries no marker, an optional one says "(optional)". The optional status is
			// removed for required types only; submit_request() still enforces them server-side.
			$statuses = $row['required'] ? [ 'optional' => null ] : [];

			$help = $row['help'];

			$help .= ( '' !== $help ? ' ' : '' ) . sprintf(
				/* translators: 1: file types such as JPG, PNG, PDF; 2: a size such as 10 MB; 3: number of files. */
				esc_html__( '%1$s, up to %2$s each, %3$s file(s) at most.', 'verification-expiry-for-hivepress' ),
				strtoupper( implode( ', ', $row['formats'] ) ),
				size_format( Doc_Types::max_bytes( $row ) ),
				number_format_i18n( $row['max_files'] )
			);

			$fields[ Doc_Types::field_name( $row['key'] ) ] = [
				'label'       => $label,
				'description' => $help,
				'caption'     => esc_html__( 'Select files', 'verification-expiry-for-hivepress' ),
				'request_id'  => $request_id,
				'editable'    => true,
				'required'    => false,
				'statuses'    => $statuses,
				'_order'      => $order,
			];

			$order += 10;
		}

		/*
		 * The typed reference a register provider needs (a company number, a VAT number). Added
		 * only while such a provider is BOTH chosen and configured, because the field is the whole
		 * input for that check and asking for it under any other provider would be a box nobody
		 * can act on. It is a real model field, so an applicant correcting a typo gets their own
		 * value back rather than an empty box.
		 */
		$reference_provider = hivepress()->hpve_provider->get_reference_provider();

		if ( $reference_provider ) {
			$fields['business_ref'] = [
				'label'       => $reference_provider->get_reference_label(),
				'description' => $reference_provider->get_reference_help(),
				'type'        => 'text',
				'max_length'  => 64,
				'required'    => true,
				'_order'      => 90,
			];
		}

		$fields['applicant_note'] = [
			'label'       => esc_html__( 'Anything we should know?', 'verification-expiry-for-hivepress' ),
			'placeholder' => esc_html__( 'Optional. For example, if your certificate is in a previous name.', 'verification-expiry-for-hivepress' ),
			'_order'      => 100,
		];

		$consent_caption = esc_html__( 'I confirm these documents are mine and I agree to them being checked and stored securely until they are deleted.', 'verification-expiry-for-hivepress' );

		$privacy_url = get_privacy_policy_url();

		if ( $privacy_url ) {
			$consent_caption .= ' <a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Privacy policy', 'verification-expiry-for-hivepress' ) . '</a>';
		}

		$fields['consent'] = [
			'label'     => esc_html__( 'Consent', 'verification-expiry-for-hivepress' ),
			'caption'   => $consent_caption,
			'type'      => 'checkbox',
			'required'  => true,
			'_separate' => true,
			'_order'    => 110,
		];

		$args = hp\merge_arrays(
			[
				'action'   => hivepress()->router->get_url( 'hpve_request_submit_action', [ 'request_id' => $request_id ] ),
				'method'   => 'POST',
				'redirect' => true,
				'fields'   => $fields,

				// "Send again" only answers a reviewer (more information, not approved); a renewal or
				// a return after an expiry is a fresh send, even though the attempt count is not zero.
				'button'   => [
					'label' => $attempts && in_array( $outcome, [ 'needs_info', 'rejected' ], true ) ? esc_html__( 'Send again', 'verification-expiry-for-hivepress' ) : esc_html__( 'Send for review', 'verification-expiry-for-hivepress' ),
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
