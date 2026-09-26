<?php
/**
 * Request controller: the account pages, the document routes, the request actions and the webhook.
 *
 * Every REST route registers with permission_callback __return_true (components/class-router.php:403-417,
 * core 1.7.31), so every action here opens with its own login test and then an ownership test, in
 * that order, and every query is scoped by the current user. The document gate is a front-end route,
 * not a REST one, because a plain link or image carries no nonce and WordPress then treats a REST
 * request as logged out (wp-includes/rest-api.php:1146-1157).
 *
 * Route names are fixed strings, never derived from a class, so renaming a file cannot move a URL.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Controllers;

use HivePress\Helpers as hp;
use HivePress\Blocks;
use HivePress\Models;
use Verification_Expiry\Logic\Hpve_Request_State as State;
use Verification_Expiry\Logic\Hpve_Document_Types as Doc_Types;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Verification routes.
 */
final class Hpve_Request extends Controller {

	/**
	 * One provider start per user per this many seconds.
	 */
	const START_RATE_SECONDS = 60;

	/**
	 * Class constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'routes' => [
					'hpve_verification_resource'    => [
						'path' => '/hpve-verification',
						'rest' => true,
					],

					'hpve_document_upload_action'   => [
						'base'   => 'hpve_verification_resource',
						'path'   => '/documents',
						'method' => 'POST',
						'action' => [ $this, 'upload_document' ],
						'rest'   => true,
					],

					'hpve_document_delete_action'   => [
						'base'   => 'hpve_verification_resource',
						'path'   => '/documents/(?P<attachment_id>\d+)',
						'method' => 'DELETE',
						'action' => [ $this, 'delete_document' ],
						'rest'   => true,
					],

					'hpve_request_submit_action'    => [
						'base'   => 'hpve_verification_resource',
						'path'   => '/requests/(?P<request_id>\d+)/submit',
						'method' => 'POST',
						'action' => [ $this, 'submit_request' ],
						'rest'   => true,
					],

					'hpve_request_start_action'     => [
						'base'   => 'hpve_verification_resource',
						'path'   => '/requests/(?P<request_id>\d+)/start',
						'method' => 'POST',
						'action' => [ $this, 'start_request' ],
						'rest'   => true,
					],

					'hpve_request_redirect_action'  => [
						'base'   => 'hpve_verification_resource',
						'path'   => '/requests/(?P<request_id>\d+)/redirect',
						'method' => 'GET',
						'action' => [ $this, 'redirect_request' ],
						'rest'   => true,
					],

					'hpve_stripe_webhook_action'    => [
						'base'   => 'hpve_verification_resource',
						'path'   => '/stripe-webhook',
						'method' => 'POST',
						'action' => [ $this, 'handle_webhook' ],
						'rest'   => true,
					],

					/*
					 * One address per provider, for everything that is not Stripe. Stripe keeps its
					 * own path above because owners have already pasted it into their Stripe
					 * Dashboard; moving it would silently stop every live site's webhooks the day
					 * they updated.
					 */
					'hpve_provider_webhook_action'  => [
						'base'   => 'hpve_verification_resource',
						'path'   => '/webhook/(?P<provider>[a-z0-9_]+)',
						'method' => 'POST',
						'action' => [ $this, 'handle_provider_webhook' ],
						'rest'   => true,
					],

					'hpve_verification_page'        => [
						'title'    => esc_html__( 'Verification', 'verification-expiry-for-hivepress' ),
						'base'     => 'user_account_page',
						'path'     => '/verification',
						'redirect' => [ $this, 'redirect_verification_page' ],
						'action'   => [ $this, 'render_verification_page' ],
					],

					'hpve_verification_return_page' => [
						'title'    => esc_html__( 'Verification', 'verification-expiry-for-hivepress' ),
						'base'     => 'hpve_verification_page',
						'path'     => '/return',
						'redirect' => [ $this, 'redirect_return_page' ],
						'action'   => [ $this, 'render_return_page' ],
					],

					'hpve_verification_buy_page'    => [
						'base'     => 'hpve_verification_page',
						'path'     => '/buy',
						'redirect' => [ $this, 'redirect_buy_page' ],
					],

					'hpve_document_view_page'       => [
						'path'   => '/verification-document/(?P<attachment_id>\d+)',
						'action' => [ $this, 'render_document' ],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/*
	--------------------------------------------------------------------------
	Shared guards.
	--------------------------------------------------------------------------
	*/

	/**
	 * Loads a request for a REST action and checks the caller owns it (or may review it).
	 *
	 * @param mixed $request_id Request ID from the route.
	 * @param bool  $owner_only Refuse reviewers.
	 * @return object|\WP_REST_Response The request, or an error response.
	 */
	protected function load_own_request( $request_id, $owner_only = false ) {
		$request = hivepress()->hpve_request->get_request( absint( $request_id ) );

		if ( ! $request || 'trash' === $request->get_status() ) {
			return hp\rest_error( 404 );
		}

		$is_owner = hivepress()->hpve_request->is_owner( $request, get_current_user_id() );

		if ( ! $is_owner && ( $owner_only || ! current_user_can( HPVE_REVIEW_CAP ) ) ) {
			return hp\rest_error( 403 );
		}

		return $request;
	}

	/**
	 * Whether the request feature is on; every REST action refuses when it is off.
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		return hivepress()->hpve_request->is_enabled();
	}

	/**
	 * Whether an unpaid request is blocked by the payment setting.
	 *
	 * @param object $request Request.
	 * @return bool
	 */
	protected function needs_payment( $request ) {
		return hivepress()->hpve_request->is_payment_required() && ! $request->is_paid();
	}

	/*
	--------------------------------------------------------------------------
	Documents.
	--------------------------------------------------------------------------
	*/

	/**
	 * Uploads one document into the private folder.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function upload_document( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		if ( ! $this->is_enabled() ) {
			return hp\rest_error( 403 );
		}

		$parent = hivepress()->hpve_request->get_request( absint( $request->get_param( 'parent' ) ) );

		if ( ! $parent || 'trash' === $parent->get_status() ) {
			return hp\rest_error( 400 );
		}

		$is_owner    = hivepress()->hpve_request->is_owner( $parent, get_current_user_id() );
		$is_reviewer = current_user_can( HPVE_REVIEW_CAP );

		if ( ! $is_owner && ! $is_reviewer ) {
			return hp\rest_error( 403 );
		}

		// Inside the renewal window the first upload turns the verified request into a renewal
		// draft; outside it this does nothing and the request stays closed to changes.
		if ( $is_owner && 'publish' === (string) $parent->get_status() ) {
			hivepress()->hpve_request->maybe_start_renewal( $parent );
		}

		$status = (string) $parent->get_status();

		if ( ( $is_owner && ! $is_reviewer && ! State::owner_can_edit( $status ) ) || ( $is_reviewer && ! in_array( $status, [ 'draft', 'pending' ], true ) ) ) {
			return hp\rest_error( 403, esc_html__( 'This request has been submitted and cannot be changed.', 'verification-expiry-for-hivepress' ) );
		}

		if ( $is_owner && ! $is_reviewer && $this->needs_payment( $parent ) ) {
			return hp\rest_error( 403, esc_html__( 'Payment is needed before documents can be sent.', 'verification-expiry-for-hivepress' ) );
		}

		$field_name = sanitize_key( (string) $request->get_param( 'parent_field' ) );
		$key        = Doc_Types::key_from_field( $field_name );
		$row        = '' !== $key ? Doc_Types::find( hivepress()->hpve_request->get_document_types(), $key ) : null;

		if ( ! $row ) {
			return hp\rest_error( 400 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the REST cookie nonce was checked by WordPress before this callback ran.
		if ( ! isset( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'], $_FILES['file']['name'], $_FILES['file']['size'] ) ) {
			return hp\rest_error( 400 );
		}

		if ( ! empty( $_FILES['file']['error'] ) ) {
			return hp\rest_error( 400, esc_html__( 'The file could not be uploaded. Please try again.', 'verification-expiry-for-hivepress' ) );
		}

		$max_size = min( Doc_Types::max_bytes( $row ), wp_max_upload_size() );

		if ( absint( $_FILES['file']['size'] ) > $max_size ) {
			/* translators: %s: a file size such as 10 MB. */
			return hp\rest_error( 400, sprintf( esc_html__( 'The file size must not exceed %s.', 'verification-expiry-for-hivepress' ), size_format( $max_size ) ) );
		}

		$formats = array_values( array_intersect( Doc_Types::FORMATS, $row['formats'] ) );

		// tmp_name is the path PHP wrote for the upload, not client input; wp_unslash() would strip
		// the backslashes of a Windows path, so it is passed as is.
		$tmp_name = is_string( $_FILES['file']['tmp_name'] ) ? $_FILES['file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a server-side temporary path.
		$name     = sanitize_file_name( wp_unslash( (string) $_FILES['file']['name'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! hivepress()->attachment->is_valid_file( $tmp_name, $name, $formats ) ) {
			/* translators: %s: file extensions. */
			return hp\rest_error( 400, sprintf( esc_html__( 'Only %s files are allowed.', 'verification-expiry-for-hivepress' ), strtoupper( implode( ', ', $formats ) ) ) );
		}

		$existing = hivepress()->hpve_request->get_documents( $parent->get_id(), $key );

		if ( count( $existing ) >= (int) $row['max_files'] ) {
			/* translators: %s: number of files. */
			return hp\rest_error( 403, sprintf( esc_html__( 'Only up to %s files can be uploaded for this document.', 'verification-expiry-for-hivepress' ), number_format_i18n( $row['max_files'] ) ) );
		}

		$attachment_id = hivepress()->hpve_storage->upload( $parent, $row, 'file' );

		if ( is_wp_error( $attachment_id ) ) {
			return hp\rest_error( 400, $attachment_id->get_error_messages() );
		}

		$attachment = Models\Attachment::query()->get_by_id( $attachment_id );

		if ( ! $attachment ) {
			return hp\rest_error( 400 );
		}

		$attachment->fill(
			[
				'sort_order'   => count( $existing ),
				'parent_model' => 'hpve_request',
				'parent_field' => $field_name,
				'parent'       => $parent->get_id(),
			]
		);

		if ( ! $attachment->save() ) {
			return hp\rest_error( 400, $attachment->_get_errors() );
		}

		hivepress()->hpve_request->apply_event(
			$parent,
			'document_uploaded',
			[
				'message' => sprintf(
					/* translators: 1: the document type, 2: a file size such as 1.2 MB. */
					esc_html__( 'Uploaded a %1$s (%2$s).', 'verification-expiry-for-hivepress' ),
					$row['label'],
					size_format( absint( get_post_meta( $attachment_id, 'hp_hpve_doc_size', true ) ) )
				),
			]
		);

		$data = [
			'id' => $attachment_id,
		];

		if ( $request->get_param( 'render' ) ) {
			$field = hp\get_array_value( $parent->_get_fields(), $field_name );

			if ( $field ) {
				$data['html'] = $field->render_attachment( $attachment );
			}
		}

		return hp\rest_response( 201, $data );
	}

	/**
	 * Deletes one document.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function delete_document( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		$attachment_id = absint( $request->get_param( 'attachment_id' ) );

		if ( ! $attachment_id || ! hivepress()->hpve_storage->is_private( $attachment_id ) ) {
			return hp\rest_error( 404 );
		}

		$parent = hivepress()->hpve_request->get_request( (int) get_post_field( 'post_parent', $attachment_id ) );

		if ( ! $parent ) {
			return hp\rest_error( 404 );
		}

		$is_owner    = hivepress()->hpve_request->is_owner( $parent, get_current_user_id() );
		$is_reviewer = current_user_can( HPVE_REVIEW_CAP );

		if ( ! $is_reviewer && ( ! $is_owner || ! State::owner_can_edit( (string) $parent->get_status() ) ) ) {
			return hp\rest_error( 403 );
		}

		$field_name = (string) get_post_meta( $attachment_id, 'hp_parent_field', true );
		$key        = Doc_Types::key_from_field( $field_name );
		$row        = '' !== $key ? Doc_Types::find( hivepress()->hpve_request->get_document_types( true ), $key ) : null;
		$label      = $row ? $row['label'] : $key;

		if ( ! wp_delete_attachment( $attachment_id, true ) ) {
			return hp\rest_error( 400 );
		}

		hivepress()->hpve_request->apply_event(
			$parent,
			'document_deleted',
			[
				/* translators: %s: the document type. */
				'message' => sprintf( esc_html__( 'Removed a %s.', 'verification-expiry-for-hivepress' ), $label ),
			]
		);

		return hp\rest_response( 204 );
	}

	/**
	 * Streams a document through the gate. The storage component checks everything and exits.
	 *
	 * @return string
	 */
	public function render_document() {
		hivepress()->hpve_storage->serve( absint( hivepress()->request->get_param( 'attachment_id' ) ) );

		return '';
	}

	/*
	--------------------------------------------------------------------------
	Request actions.
	--------------------------------------------------------------------------
	*/

	/**
	 * Sends a draft request for review.
	 *
	 * The upload fields are disabled by inheritance, so the form save never touches them; the
	 * required types and the consent box are checked here, server-side, whatever the browser sent.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function submit_request( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		if ( ! $this->is_enabled() ) {
			return hp\rest_error( 403 );
		}

		$object = $this->load_own_request( $request->get_param( 'request_id' ), true );

		if ( $object instanceof \WP_REST_Response ) {
			return $object;
		}

		// Inside the renewal window a verified request becomes a renewal draft first (documents
		// kept from the last check can be sent again as they are); elsewhere this does nothing.
		hivepress()->hpve_request->maybe_start_renewal( $object );

		if ( ! State::owner_can_edit( (string) $object->get_status() ) ) {
			return hp\rest_error( 403, esc_html__( 'This request has already been sent for review.', 'verification-expiry-for-hivepress' ) );
		}

		if ( $this->needs_payment( $object ) ) {
			return hp\rest_error( 403, esc_html__( 'Payment is needed before documents can be sent.', 'verification-expiry-for-hivepress' ) );
		}

		$provider = hivepress()->hpve_provider->get_provider();

		// A register provider collects a typed reference through this same form, so the form is a
		// legitimate submission even with documents switched off.
		$reference_provider = hivepress()->hpve_provider->get_reference_provider();

		if ( ! $provider->supports_documents() && ! $reference_provider ) {
			return hp\rest_error( 403, esc_html__( 'Documents are not collected on this site. Please use the automated check.', 'verification-expiry-for-hivepress' ) );
		}

		$form = new \HivePress\Forms\Hpve_Request_Submit( [ 'model' => $object ] );

		$form->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		if ( ! $form->get_value( 'consent' ) ) {
			return hp\rest_error( 400, esc_html__( 'Please confirm the documents are yours and agree to them being checked.', 'verification-expiry-for-hivepress' ) );
		}

		$missing = hivepress()->hpve_request->get_missing_required( $object->get_id() );

		if ( $missing ) {
			/* translators: %s: a list of document types. */
			return hp\rest_error( 400, sprintf( esc_html__( 'Please upload the following before sending: %s.', 'verification-expiry-for-hivepress' ), implode( ', ', $missing ) ) );
		}

		$note = $form->get_value( 'applicant_note' );

		if ( null !== $note ) {
			$object->set_applicant_note( $note )->save( [ 'applicant_note' ] );
		}

		/*
		 * The register reference. Refused here as well as in the provider, because a number that
		 * cannot be one is worth saying so about while the applicant is still looking at the form,
		 * rather than in an email a minute later. Stored in the shape the register uses, so the
		 * value the applicant sees on the way back is the one that was actually looked up.
		 */
		if ( $reference_provider ) {
			$reference = $reference_provider->normalise_reference( (string) $form->get_value( 'business_ref' ) );

			if ( '' === $reference ) {
				return hp\rest_error(
					400,
					sprintf(
						/* translators: %s: the reference field's label, e.g. "Company number". */
						esc_html__( 'Please check the %s: it is not in a form we can look up.', 'verification-expiry-for-hivepress' ),
						strtolower( $reference_provider->get_reference_label() )
					)
				);
			}

			$object->set_business_ref( $reference )->save( [ 'business_ref' ] );
		}

		if ( ! $object->get_vendor__id() ) {
			$vendor = hivepress()->hpve_request->get_vendor_for_user( get_current_user_id() );

			if ( $vendor ) {
				$object->set_vendor( $vendor->get_id() )->save( [ 'vendor' ] );

				update_post_meta( $vendor->get_id(), \HivePress\Components\Hpve_Request::META_REQUEST_ID, $object->get_id() );
			}
		}

		$result = hivepress()->hpve_request->apply_event( $object, 'submit', [ 'consent' => true ] );

		if ( true !== $result ) {
			return hp\rest_error( 400, esc_html__( 'The request could not be sent. Please reload the page and try again.', 'verification-expiry-for-hivepress' ) );
		}

		return hp\rest_response(
			200,
			[
				'id' => $object->get_id(),
			]
		);
	}

	/**
	 * Starts an automated check.
	 *
	 * Only queues: the third-party call runs in a background job, never on this request
	 * (resources/security-standards.md, rule 5). The page polls the redirect route for the URL.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function start_request( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		if ( ! $this->is_enabled() ) {
			return hp\rest_error( 403 );
		}

		$object = $this->load_own_request( $request->get_param( 'request_id' ), true );

		if ( $object instanceof \WP_REST_Response ) {
			return $object;
		}

		// Inside the renewal window a verified request becomes a renewal draft first (documents
		// kept from the last check can be sent again as they are); elsewhere this does nothing.
		hivepress()->hpve_request->maybe_start_renewal( $object );

		if ( ! State::owner_can_edit( (string) $object->get_status() ) ) {
			return hp\rest_error( 403, esc_html__( 'This request has already been sent for review.', 'verification-expiry-for-hivepress' ) );
		}

		if ( $this->needs_payment( $object ) ) {
			return hp\rest_error( 403, esc_html__( 'Payment is needed before the check can start.', 'verification-expiry-for-hivepress' ) );
		}

		$provider = hivepress()->hpve_provider->get_provider();

		if ( 'manual' === $provider->get_name() || ! $provider->is_configured() ) {
			return hp\rest_error( 403, esc_html__( 'Automated checks are not available on this site.', 'verification-expiry-for-hivepress' ) );
		}

		$limit = hivepress()->hpve_request->get_number_option( 'stripe_attempt_limit', 3 );

		if ( (int) $object->get_provider_attempts() >= max( 1, $limit ) ) {
			return hp\rest_error( 403, esc_html__( 'You have used all your attempts at the automated check. Please send your documents to us instead.', 'verification-expiry-for-hivepress' ) );
		}

		$rate_key = 'hpve_rate_' . get_current_user_id();

		if ( get_transient( $rate_key ) ) {
			return hp\rest_error( 429, esc_html__( 'Please wait a minute before trying again.', 'verification-expiry-for-hivepress' ) );
		}

		set_transient( $rate_key, 1, self::START_RATE_SECONDS );

		if ( ! $object->get_vendor__id() ) {
			$vendor = hivepress()->hpve_request->get_vendor_for_user( get_current_user_id() );

			if ( $vendor ) {
				$object->set_vendor( $vendor->get_id() )->save( [ 'vendor' ] );
			}
		}

		$result = hivepress()->hpve_request->apply_event( $object, 'submit', [ 'consent' => true ] );

		if ( true !== $result ) {
			return hp\rest_error( 400, esc_html__( 'The check could not be started. Please reload the page and try again.', 'verification-expiry-for-hivepress' ) );
		}

		return hp\rest_response(
			202,
			[
				'id' => $object->get_id(),
			]
		);
	}

	/**
	 * Hands the applicant the provider's URL once a job has stored it. Single use.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function redirect_request( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		$object = $this->load_own_request( $request->get_param( 'request_id' ), true );

		if ( $object instanceof \WP_REST_Response ) {
			return $object;
		}

		if ( 'pending' !== (string) $object->get_status() ) {
			return hp\rest_error( 403 );
		}

		$url = hivepress()->hpve_provider->get_provider()->get_redirect_url( $object->get_id() );

		if ( '' === $url ) {
			return hp\rest_response( 200 );
		}

		return hp\rest_response(
			200,
			[
				'url' => $url,
			]
		);
	}

	/**
	 * Receives a provider webhook. The signature is the gate; nothing changes on this request.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		return $this->answer_webhook( $request, hivepress()->hpve_provider->handle_webhook( (string) $request->get_body(), $this->read_headers( $request ) ) );
	}

	/**
	 * Receives a webhook for a named provider. The signature is the gate; nothing changes here.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function handle_provider_webhook( $request ) {
		$name = sanitize_key( (string) $request->get_param( 'provider' ) );

		return $this->answer_webhook( $request, hivepress()->hpve_provider->handle_named_webhook( $name, (string) $request->get_body(), $this->read_headers( $request ) ) );
	}

	/**
	 * Flattens REST headers to lower-cased names.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array
	 */
	protected function read_headers( $request ) {
		$headers = [];

		foreach ( (array) $request->get_headers() as $name => $values ) {
			$headers[ strtolower( str_replace( '_', '-', (string) $name ) ) ] = is_array( $values ) ? (string) reset( $values ) : (string) $values;
		}

		return $headers;
	}

	/**
	 * Turns a provider's status code into the REST answer.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param int              $code HTTP status.
	 * @return \WP_REST_Response
	 */
	protected function answer_webhook( $request, $code ) {
		$code = (int) $code;

		if ( $code >= 200 && $code < 300 ) {
			return new \WP_REST_Response( [ 'received' => true ], $code );
		}

		return hp\rest_error( $code );
	}

	/*
	--------------------------------------------------------------------------
	Pages.
	--------------------------------------------------------------------------
	*/

	/**
	 * Login bounce for the Verification page.
	 *
	 * @return mixed
	 */
	public function redirect_verification_page() {
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		if ( ! $this->is_enabled() ) {
			return true;
		}

		return false;
	}

	/**
	 * Renders the Verification page.
	 *
	 * @return string
	 */
	public function render_verification_page() {
		return ( new Blocks\Template(
			[
				'template' => 'hpve_verification_page',
			]
		) )->render();
	}

	/**
	 * Guards the return page: a child route inherits no callbacks, so login is checked again here.
	 *
	 * Nothing in the query string is trusted beyond request_id; the provider re-fetches the result
	 * in a background job.
	 *
	 * @return mixed
	 */
	public function redirect_return_page() {
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		$request_id = absint( hp\get_array_value( $_GET, 'request_id' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; Stripe redirects here and nothing changes on this request.
		$object     = $request_id ? hivepress()->hpve_request->get_request( $request_id ) : null;

		if ( ! $object || ! hivepress()->hpve_request->is_owner( $object, get_current_user_id() ) || ! $object->get_provider_ref() ) {
			return hivepress()->router->get_url( 'hpve_verification_page' );
		}

		hivepress()->hpve_provider->get_provider()->handle_return( $object->get_id(), [] );

		return false;
	}

	/**
	 * Renders the return page.
	 *
	 * @return string
	 */
	public function render_return_page() {
		return ( new Blocks\Template(
			[
				'template' => 'hpve_verification_return_page',
			]
		) )->render();
	}

	/**
	 * Empties the cart, adds the verification product and sends the buyer to the checkout.
	 *
	 * The empty-first step mirrors Marketplace's own buy button so a mixed basket can never be
	 * attributed to the first line's Vendor (resources/hivepress-data.md, "Who an order pays").
	 *
	 * @return mixed
	 */
	public function redirect_buy_page() {
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		$payment = hivepress()->hpve_payment;

		if ( ! $payment || ! $payment->has_product() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return hivepress()->router->get_url( 'hpve_verification_page' );
		}

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $payment->get_product_id() );

		return wc_get_checkout_url();
	}
}
