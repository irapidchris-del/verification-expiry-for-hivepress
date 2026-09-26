<?php
/**
 * Verification request model.
 *
 * Post type "hp_hpve_request": core derives the alias from the class name (models/class-post.php:29,
 * core 1.7.31) and registers it from includes/configs/post-types.php under the key "hpve_request".
 * Registering the fields here is what makes core fire hivepress/v1/models/hpve_request/update_{field}
 * for every meta write and lets Hpve_Request::query()->filter() see them (queries/class-query.php:206).
 *
 * Every _external field carries an explicit _alias of "hp_hpve_{name}": without one core would derive
 * "hp_{name}" (models/class-model.php:136-137), and the review screen, the uninstaller and the log
 * reader all address the meta by the prefixed key. Every text the applicant types is a field with
 * html => false, so it is stripped at save; output is
 * still escaped. The status is a plain text field rather than a select on purpose: a select is
 * validated against its options on every save of the whole object, so a status core moves the post to
 * that is not in the list (auto-draft, for one) would block every later save.
 *
 * The one document upload field per enabled type is added by the request component from the
 * settings, through hivepress/v1/models/hpve_request, so the list follows the settings screen.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Models;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A Vendor's verification request.
 *
 * Every getter and setter is magic (Model::__call); the tags below describe the ones this plugin
 * calls so PHPStan can check them.
 *
 * @method int|null get_user__id()
 * @method \HivePress\Models\User|null get_user()
 * @method int|null get_vendor__id()
 * @method \HivePress\Models\Vendor|null get_vendor()
 * @method string|null get_status()
 * @method string|null get_title()
 * @method string|null get_created_date()
 * @method string|null get_outcome()
 * @method string|null get_note()
 * @method string|null get_reason()
 * @method string|null get_applicant_note()
 * @method int|null get_consent_time()
 * @method int|null get_submitted_time()
 * @method int|null get_reviewed_time()
 * @method int|null get_docs_deleted_time()
 * @method int|null get_reviewer__id()
 * @method int|null get_attempts()
 * @method bool|null is_paid()
 * @method int|null get_order_id()
 * @method int|null get_subscription_id()
 * @method string|null get_payment_state()
 * @method string|null get_provider()
 * @method string|null get_business_ref()
 * @method string|null get_provider_ref()
 * @method string|null get_provider_status()
 * @method string|null get_provider_error()
 * @method int|null get_provider_attempts()
 * @method bool|null is_provider_livemode()
 * @method int|null get_priority()
 * @method self set_user( mixed $value )
 * @method self set_vendor( mixed $value )
 * @method self set_status( mixed $value )
 * @method self set_title( mixed $value )
 * @method self set_outcome( mixed $value )
 * @method self set_note( mixed $value )
 * @method self set_reason( mixed $value )
 * @method self set_applicant_note( mixed $value )
 * @method self set_consent_time( mixed $value )
 * @method self set_submitted_time( mixed $value )
 * @method self set_reviewed_time( mixed $value )
 * @method self set_docs_deleted_time( mixed $value )
 * @method self set_reviewer( mixed $value )
 * @method self set_attempts( mixed $value )
 * @method self set_paid( mixed $value )
 * @method self set_order_id( mixed $value )
 * @method self set_subscription_id( mixed $value )
 * @method self set_payment_state( mixed $value )
 * @method self set_provider( mixed $value )
 * @method self set_business_ref( mixed $value )
 * @method self set_provider_ref( mixed $value )
 * @method self set_provider_status( mixed $value )
 * @method self set_provider_error( mixed $value )
 * @method self set_provider_attempts( mixed $value )
 * @method self set_provider_livemode( mixed $value )
 * @method self set_priority( mixed $value )
 */
class Hpve_Request extends Post {

	/**
	 * Class constructor.
	 *
	 * @param array $args Model arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'fields' => [
					'user'              => [
						'type'     => 'id',
						'required' => true,
						'_alias'   => 'post_author',
						'_model'   => 'user',
					],

					'vendor'            => [
						'type'   => 'id',
						'_alias' => 'post_parent',
						'_model' => 'vendor',
					],

					'status'            => [
						'type'       => 'text',
						'max_length' => 128,
						'_alias'     => 'post_status',
					],

					'title'             => [
						'type'       => 'text',
						'max_length' => 256,
						'_alias'     => 'post_title',
					],

					'created_date'      => [
						'type'   => 'date',
						'format' => 'Y-m-d H:i:s',
						'_alias' => 'post_date',
					],

					'priority'          => [
						'type'      => 'number',
						'min_value' => 0,
						'max_value' => 1,
						'_alias'    => 'menu_order',
					],

					'outcome'           => [
						'type'       => 'text',
						'max_length' => 32,
						'_alias'     => 'hp_hpve_outcome',
						'_external'  => true,
					],

					'note'              => [
						'type'       => 'textarea',
						'max_length' => 2000,
						'html'       => false,
						'_alias'     => 'hp_hpve_note',
						'_external'  => true,
					],

					'reason'            => [
						'type'       => 'textarea',
						'max_length' => 2000,
						'html'       => false,
						'_alias'     => 'hp_hpve_reason',
						'_external'  => true,
					],

					'applicant_note'    => [
						'label'      => esc_html__( 'Anything we should know?', 'verification-expiry-for-hivepress' ),
						'type'       => 'textarea',
						'max_length' => 500,
						'html'       => false,
						'_alias'     => 'hp_hpve_applicant_note',
						'_external'  => true,
					],

					'consent_time'      => [
						'type'      => 'number',
						'_alias'    => 'hp_hpve_consent_time',
						'_external' => true,
					],

					'submitted_time'    => [
						'type'      => 'number',
						'_alias'    => 'hp_hpve_submitted_time',
						'_external' => true,
					],

					'reviewed_time'     => [
						'type'      => 'number',
						'_alias'    => 'hp_hpve_reviewed_time',
						'_external' => true,
					],

					'docs_deleted_time' => [
						'type'      => 'number',
						'_alias'    => 'hp_hpve_docs_deleted_time',
						'_external' => true,
					],

					'reviewer'          => [
						'type'      => 'id',
						'_alias'    => 'hp_hpve_reviewer',
						'_external' => true,
						'_model'    => 'user',
					],

					'attempts'          => [
						'type'      => 'number',
						'min_value' => 0,
						'_alias'    => 'hp_hpve_attempts',
						'_external' => true,
					],

					'paid'              => [
						'type'      => 'checkbox',
						'_alias'    => 'hp_hpve_paid',
						'_external' => true,
					],

					'order_id'          => [
						'type'      => 'number',
						'min_value' => 0,
						'_alias'    => 'hp_hpve_order_id',
						'_external' => true,
					],

					'subscription_id'   => [
						'type'      => 'number',
						'min_value' => 0,
						'_alias'    => 'hp_hpve_subscription_id',
						'_external' => true,
					],

					'payment_state'     => [
						'type'       => 'text',
						'max_length' => 32,
						'_alias'     => 'hp_hpve_payment_state',
						'_external'  => true,
					],

					'provider'          => [
						'type'       => 'text',
						'max_length' => 32,
						'_alias'     => 'hp_hpve_provider',
						'_external'  => true,
					],

					/*
					 * What the applicant typed for a register provider: a company number, a VAT
					 * number. Kept apart from provider_ref, which holds what the PROVIDER gave back
					 * (a Stripe session id, the normalised reference actually looked up). Merging
					 * the two would mean a failed lookup overwriting the applicant's own input, so
					 * the form would come back blank exactly when they need to correct a typo.
					 */
					'business_ref'      => [
						'type'       => 'text',
						'max_length' => 64,
						'_alias'     => 'hp_hpve_business_ref',
						'_external'  => true,
					],

					'provider_ref'      => [
						'type'       => 'text',
						'max_length' => 128,
						'_alias'     => 'hp_hpve_provider_ref',
						'_external'  => true,
					],

					'provider_status'   => [
						'type'       => 'text',
						'max_length' => 64,
						'_alias'     => 'hp_hpve_provider_status',
						'_external'  => true,
					],

					'provider_error'    => [
						'type'       => 'text',
						'max_length' => 128,
						'_alias'     => 'hp_hpve_provider_error',
						'_external'  => true,
					],

					'provider_attempts' => [
						'type'      => 'number',
						'min_value' => 0,
						'_alias'    => 'hp_hpve_provider_attempts',
						'_external' => true,
					],

					'provider_livemode' => [
						'type'      => 'checkbox',
						'_alias'    => 'hp_hpve_provider_livemode',
						'_external' => true,
					],

					// Reserved for Listing-level requests in a later version.
					'subject'           => [
						'type'       => 'text',
						'max_length' => 32,
						'_alias'     => 'hp_hpve_subject',
						'_external'  => true,
					],

					'listing_id'        => [
						'type'      => 'number',
						'min_value' => 0,
						'_alias'    => 'hp_hpve_listing_id',
						'_external' => true,
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
