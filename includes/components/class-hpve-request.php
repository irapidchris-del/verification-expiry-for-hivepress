<?php
/**
 * Request component.
 *
 * Owns the verification request lifecycle: the model fields that follow the settings, the one write
 * path for a request's status (apply_event()), the audit trail, the applicant emails, the hand-off
 * into the 1.x tick that starts the expiry clock, the account menu item, the "Get verified" call to
 * action, document retention and the privacy tools.
 *
 * THE RULE THIS FILE ENFORCES: never write a request's status outside apply_event(). The
 * update_status listener at priority 20 logs a "status_changed" line naming the current user for any
 * write that bypasses it, which is how a stray write is found in review.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;
use HivePress\Emails;
use Verification_Expiry\Logic\Hpve_Request_State as State;
use Verification_Expiry\Logic\Hpve_Document_Types as Doc_Types;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Request lifecycle component.
 *
 * @class Hpve_Request
 */
final class Hpve_Request extends Component {

	/**
	 * The post type, as registered.
	 */
	const POST_TYPE = 'hp_hpve_request';

	/**
	 * The audit trail comment type.
	 */
	const LOG_TYPE = 'hp_hpve_log';

	/**
	 * Vendor meta pointing at the Vendor's request.
	 */
	const META_REQUEST_ID = 'hp_hpve_request_id';

	/**
	 * Vendor meta pointing at the subscription that pays for the verification.
	 */
	const META_SUBSCRIPTION_ID = 'hp_hpve_subscription_id';

	/**
	 * Audit lines the applicant may see on their card.
	 */
	const VISIBLE_ACTIONS = [ 'submitted', 'resubmitted', 'needs_info', 'rejected', 'approved', 'expired', 'documents_deleted' ];

	/**
	 * How many requests one retention batch handles.
	 */
	const RETENTION_BATCH = 25;

	/**
	 * Request IDs whose status apply_event() is writing right now.
	 *
	 * @var array<int, bool>
	 */
	protected $applying = [];

	/**
	 * Cached document type rows.
	 *
	 * @var array|null
	 */
	protected $doc_types = null;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// One upload field per enabled document type, from the settings.
		add_filter( 'hivepress/v1/models/hpve_request', [ $this, 'add_document_fields' ] );

		// The two Vendor fields that point back at a request and a subscription.
		add_filter( 'hivepress/v1/models/vendor', [ $this, 'add_vendor_fields' ] );

		// A status write that did not go through apply_event() is logged, not hidden.
		add_action( 'hivepress/v1/models/hpve_request/update_status', [ $this, 'track_status_change' ], 20, 4 );

		// A hand tick or the hourly expiry moving the Verified box; 1.x listens at 10.
		add_action( 'hivepress/v1/models/vendor/update_verified', [ $this, 'sync_vendor_verified' ], 20, 2 );

		// Version-gated upgrade and rewrite flush (an update is not an activation).
		add_action( 'init', [ $this, 'maybe_upgrade' ], 15 );
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );

		// Account menu item, on both stages (resources/hivepress-framework.md, "The account menu is
		// filtered at TWO stages"), with no is_admin() guard so sibling plugins can see it.
		add_filter( 'hivepress/v1/menus/user_account', [ $this, 'add_menu_item' ] );
		add_filter( 'hivepress/v1/menus/user_account/items', [ $this, 'add_menu_items' ], 10, 2 );

		if ( ! is_admin() ) {

			// The "Get verified" call to action on the Vendor dashboard and the account settings page.
			add_filter( 'hivepress/v1/templates/listings_edit_page/blocks', [ $this, 'add_cta_block' ], 100, 2 );
			add_filter( 'hivepress/v1/templates/user_edit_settings_page/blocks', [ $this, 'add_cta_block' ], 100, 2 );
		}

		// Retention: the daily event queues the first batch; each batch queues the next with a
		// different argument (resources/hivepress-framework.md, "A chained background job").
		add_action( 'hivepress/v1/events/daily', [ $this, 'queue_retention' ] );
		add_action( 'hpve_retention', [ $this, 'run_retention' ] );

		// Privacy tools.
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );

		parent::__construct( $args );
	}

	/*
	--------------------------------------------------------------------------
	Settings helpers.
	--------------------------------------------------------------------------
	*/

	/**
	 * Reads one of the plugin's settings.
	 *
	 * @param string $name Name without the prefixes.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function get_option( $name, $fallback = null ) {
		return hpve_get_option( HPVE_OPTION_PREFIX . $name, $fallback );
	}

	/**
	 * Reads a number setting.
	 *
	 * @param string $name Name without the prefixes.
	 * @param int    $fallback Fallback.
	 * @return int
	 */
	public function get_number_option( $name, $fallback ) {
		return hpve_get_number_option( HPVE_OPTION_PREFIX . $name, $fallback );
	}

	/**
	 * Whether the request feature is switched on at all.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->get_option( 'request_enable', true );
	}

	/**
	 * The enabled document types, normalised.
	 *
	 * @param bool $include_disabled Include unticked rows.
	 * @return array
	 */
	public function get_document_types( $include_disabled = false ) {
		if ( $include_disabled ) {
			return Doc_Types::normalise( $this->get_option( 'doc_types', null ), true );
		}

		if ( null === $this->doc_types ) {
			$this->doc_types = Doc_Types::normalise( $this->get_option( 'doc_types', null ) );
		}

		return $this->doc_types;
	}

	/**
	 * Translated labels for the default document types, for seeding.
	 *
	 * @return array
	 */
	public static function get_default_type_labels() {
		return [
			'photo_id'         => [ esc_html__( 'Photo ID', 'verification-expiry-for-hivepress' ), esc_html__( 'A passport, driving licence or national identity card. The name must match your profile.', 'verification-expiry-for-hivepress' ) ],
			'qualification'    => [ esc_html__( 'Qualification certificate', 'verification-expiry-for-hivepress' ), esc_html__( 'A certificate or diploma for the work you offer.', 'verification-expiry-for-hivepress' ) ],
			'insurance'        => [ esc_html__( 'Insurance certificate', 'verification-expiry-for-hivepress' ), esc_html__( 'Your current public liability or professional insurance certificate.', 'verification-expiry-for-hivepress' ) ],
			'proof_of_address' => [ esc_html__( 'Proof of address', 'verification-expiry-for-hivepress' ), esc_html__( 'A utility bill or bank statement from the last three months.', 'verification-expiry-for-hivepress' ) ],
		];
	}

	/**
	 * Whether payment must happen before documents can be sent.
	 *
	 * @return bool
	 */
	public function is_payment_required() {
		$payment = hivepress()->hpve_payment;

		return $payment && $payment->has_product() && (bool) $this->get_option( 'payment_required', false );
	}

	/*
	--------------------------------------------------------------------------
	Model fields.
	--------------------------------------------------------------------------
	*/

	/**
	 * Adds one upload field per enabled document type to the request model.
	 *
	 * The field type is the plugin's own subclass (fields/class-hpve-document-upload.php), so core's
	 * upload endpoint refuses it (it accepts only the exact name "attachment_upload",
	 * controllers/class-attachment.php:155) and the plugin's own route takes the file instead. The
	 * relation shape is copied from the Listing images field (models/class-listing.php:161-170), so
	 * Post::save() and Post::get() skip it.
	 *
	 * @param array $args Model arguments.
	 * @return array
	 */
	public function add_document_fields( $args ) {
		foreach ( $this->get_document_types() as $row ) {
			$args['fields'][ Doc_Types::field_name( $row['key'] ) ] = [
				'label'     => $row['label'],
				'caption'   => esc_html__( 'Select files', 'verification-expiry-for-hivepress' ),
				'type'      => 'hpve_document_upload',
				'multiple'  => true,
				'max_files' => $row['max_files'],
				'formats'   => $row['formats'],
				'max_bytes' => Doc_Types::max_bytes( $row ),
				'doc_key'   => $row['key'],

				// Never "required" on the model: a whole-object save validates every field, and a
				// required upload field with no value would block every save of the request. The
				// submit route checks the required types itself by querying attachments.
				'required'  => false,
				'_model'    => 'attachment',
				'_relation' => 'one_to_many',
			];
		}

		return $args;
	}

	/**
	 * Adds the request and subscription pointers to the Vendor model, so they are queryable.
	 *
	 * @param array $args Model arguments.
	 * @return array
	 */
	public function add_vendor_fields( $args ) {
		$args['fields']['hpve_request_id'] = [
			'type'      => 'number',
			'min_value' => 0,
			'_external' => true,
		];

		$args['fields']['hpve_subscription_id'] = [
			'type'      => 'number',
			'min_value' => 0,
			'_external' => true,
		];

		return $args;
	}

	/*
	--------------------------------------------------------------------------
	Lookups.
	--------------------------------------------------------------------------
	*/

	/**
	 * Loads a request by ID.
	 *
	 * @param int $id Request ID.
	 * @return object|null
	 */
	public function get_request( $id ) {
		$id = absint( $id );

		if ( ! $id ) {
			return null;
		}

		$request = Models\Hpve_Request::query()->get_by_id( $id );

		return $request ? $request : null;
	}

	/**
	 * The Vendor record of a user, if they have one.
	 *
	 * The canonical test from resources/hivepress-data.md: query the model with the statuses core
	 * itself uses, never the request context (which needs edit_posts and a published Vendor) and never
	 * the role (Subscriptions demotes a Vendor on hold).
	 *
	 * @param int $user_id User ID.
	 * @return object|null
	 */
	public function get_vendor_for_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$vendor = Models\Vendor::query()->filter(
			[
				'status' => [ 'auto-draft', 'draft', 'publish' ],
				'user'   => $user_id,
			]
		)->get_first();

		return $vendor ? $vendor : null;
	}

	/**
	 * The user's request, excluding the trash.
	 *
	 * One request per applicant, reused across attempts, so the queue never shows a person twice.
	 *
	 * @param int $user_id User ID.
	 * @return object|null
	 */
	public function get_for_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$request = Models\Hpve_Request::query()->filter(
			[
				'user'       => $user_id,
				'status__in' => [ 'draft', 'pending', 'publish' ],
			]
		)->order( [ 'created_date' => 'desc' ] )
		->get_first();

		return $request ? $request : null;
	}

	/**
	 * The user's request, creating a draft when there is none.
	 *
	 * A draft is created on the first visit to the Verification page because an upload needs a parent
	 * (controllers/class-attachment.php:134). A trashed request is left in the trash and a fresh one
	 * is created, with a log line saying so.
	 *
	 * @param int      $user_id User ID.
	 * @param int|null $vendor_id Vendor ID, or null to look it up.
	 * @return object|null
	 */
	public function get_or_create( $user_id, $vendor_id = null ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$request = $this->get_for_user( $user_id );

		if ( $request ) {
			if ( null !== $vendor_id && $vendor_id && ! $request->get_vendor__id() ) {
				$request->set_vendor( $vendor_id )->save( [ 'vendor' ] );

				update_post_meta( absint( $vendor_id ), self::META_REQUEST_ID, $request->get_id() );
			}

			return $request;
		}

		if ( null === $vendor_id ) {
			$vendor    = $this->get_vendor_for_user( $user_id );
			$vendor_id = $vendor ? $vendor->get_id() : 0;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		$request = new Models\Hpve_Request();

		$request->fill(
			[
				'user'     => $user_id,
				'vendor'   => absint( $vendor_id ),
				'status'   => 'draft',
				/* translators: %s: the applicant's display name. */
				'title'    => sprintf( esc_html__( 'Verification request: %s', 'verification-expiry-for-hivepress' ), $user->display_name ),
				'outcome'  => '',
				'provider' => 'manual',
				'subject'  => 'vendor',
			]
		);

		if ( ! $request->save() ) {
			return null;
		}

		if ( $vendor_id ) {
			update_post_meta( absint( $vendor_id ), self::META_REQUEST_ID, $request->get_id() );
		}

		$trashed = Models\Hpve_Request::query()->filter(
			[
				'user'   => $user_id,
				'status' => 'trash',
			]
		)->get_count();

		$this->log( $request->get_id(), 'created', $trashed ? esc_html__( 'Request created. An earlier request for this applicant is in the trash.', 'verification-expiry-for-hivepress' ) : esc_html__( 'Request created.', 'verification-expiry-for-hivepress' ) );

		return $request;
	}

	/**
	 * The request a Vendor points at, or the Vendor's user's request.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return object|null
	 */
	public function get_for_vendor( $vendor_id ) {
		$vendor_id = absint( $vendor_id );

		$request_id = absint( get_post_meta( $vendor_id, self::META_REQUEST_ID, true ) );

		if ( $request_id ) {
			$request = $this->get_request( $request_id );

			if ( $request && 'trash' !== $request->get_status() ) {
				return $request;
			}
		}

		$user_id = absint( get_post_field( 'post_author', $vendor_id ) );

		return $user_id ? $this->get_for_user( $user_id ) : null;
	}

	/**
	 * The documents attached to a request.
	 *
	 * @param int         $request_id Request ID.
	 * @param string|null $key Document type key, or null for all.
	 * @return array Attachment model objects.
	 */
	public function get_documents( $request_id, $key = null ) {
		$filter = [
			'parent_model' => 'hpve_request',
			'parent'       => absint( $request_id ),
		];

		if ( null !== $key ) {
			$filter['parent_field'] = Doc_Types::field_name( $key );
		}

		return Models\Attachment::query()->filter( $filter )->order( [ 'id' => 'asc' ] )->get()->serialize();
	}

	/**
	 * How many documents each type has.
	 *
	 * @param int $request_id Request ID.
	 * @return array Map of type key to count.
	 */
	public function count_documents( $request_id ) {
		$counts = [];

		foreach ( $this->get_documents( $request_id ) as $attachment ) {
			$key = Doc_Types::key_from_field( (string) $attachment->get_parent_field() );

			if ( '' !== $key ) {
				$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
			}
		}

		return $counts;
	}

	/**
	 * Which required document types are missing from a request.
	 *
	 * @param int $request_id Request ID.
	 * @return array Labels of the missing types.
	 */
	public function get_missing_required( $request_id ) {
		$counts  = $this->count_documents( $request_id );
		$missing = [];

		foreach ( $this->get_document_types() as $row ) {
			if ( $row['required'] && empty( $counts[ $row['key'] ] ) ) {
				$missing[] = $row['label'];
			}
		}

		return $missing;
	}

	/**
	 * Whether a request has been paid for.
	 *
	 * @param object $request Request.
	 * @return bool
	 */
	public function is_paid( $request ) {
		return (bool) $request->is_paid();
	}

	/**
	 * Whether the current user may review requests.
	 *
	 * @return bool
	 */
	public function current_user_can_review() {
		return current_user_can( HPVE_REVIEW_CAP );
	}

	/**
	 * Whether a user owns a request.
	 *
	 * @param object $request Request.
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	public function is_owner( $request, $user_id ) {
		return $user_id && (int) $request->get_user__id() === (int) $user_id;
	}

	/**
	 * The card state for a request and its Vendor.
	 *
	 * @param object|null $request Request or null.
	 * @param object|null $vendor Vendor or null.
	 * @return string
	 */
	public function get_card_state( $request, $vendor ) {
		$facts = [
			'has_vendor'  => (bool) $vendor,
			'has_request' => $request && 'trash' !== $request->get_status(),
		];

		if ( $request ) {
			$facts['status']           = (string) $request->get_status();
			$facts['outcome']          = (string) $request->get_outcome();
			$facts['paid']             = (bool) $request->is_paid();
			$facts['payment_required'] = $this->is_payment_required();
			$facts['provider']         = (string) $request->get_provider();
			$facts['provider_status']  = (string) $request->get_provider_status();
			$facts['has_documents']    = (bool) $this->count_documents( $request->get_id() );
		}

		if ( $vendor ) {
			$facts['vendor_verified'] = (bool) get_post_meta( $vendor->get_id(), 'hp_verified', true );

			if ( $request && 'publish' === $facts['status'] && $facts['vendor_verified'] ) {
				$facts['renewal_due'] = $this->is_renewal_due( $vendor->get_id() );
			}
		}

		return State::card_state( $facts );
	}

	/**
	 * Whether a verified Vendor is inside the renewal window, which is the reminder window.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return bool
	 */
	public function is_renewal_due( $vendor_id ) {
		$until = (string) get_post_meta( $vendor_id, Hpve_Verification::META_UNTIL, true );

		return State::renewal_due( $until, current_time( 'Y-m-d' ), hpve_get_number_option( HPVE_OPTION_PREFIX . 'reminder_days', 7 ) );
	}

	/**
	 * Starts an early renewal when the Vendor's first action on a verified request needs a draft.
	 *
	 * Documents can only change while a request is a draft (State::owner_can_edit()), and a
	 * verified request is published, so the upload and submit endpoints call this first. It does
	 * nothing outside the renewal window, which keeps the "submitted and cannot be changed" answer
	 * for every other published request.
	 *
	 * @param object $request Request.
	 * @return bool Whether the request is now a renewal draft.
	 */
	public function maybe_start_renewal( $request ) {
		if ( 'publish' !== (string) $request->get_status() || ! $request->get_vendor__id() ) {
			return false;
		}

		$vendor_id = (int) $request->get_vendor__id();

		if ( ! get_post_meta( $vendor_id, 'hp_verified', true ) || ! $this->is_renewal_due( $vendor_id ) ) {
			return false;
		}

		return true === $this->apply_event( $request, 'renew' );
	}

	/**
	 * Translated words for the account menu.
	 *
	 * @param string $card_state Card state.
	 * @return string
	 */
	public function get_menu_word( $card_state ) {
		switch ( State::menu_word( $card_state ) ) {
			case 'pending':
				return esc_html__( 'Pending', 'verification-expiry-for-hivepress' );
			case 'action_needed':
				return esc_html__( 'Action needed', 'verification-expiry-for-hivepress' );
			case 'expired':
				return esc_html__( 'Expired', 'verification-expiry-for-hivepress' );
			case 'renewal_due':
				return esc_html__( 'Renewal due', 'verification-expiry-for-hivepress' );
		}

		return '';
	}

	/*
	--------------------------------------------------------------------------
	The one write path.
	--------------------------------------------------------------------------
	*/

	/**
	 * Applies an event to a request: the status, the side effects, the audit line and the email.
	 *
	 * Arguments by event: submit takes "consent" (bool) and "start_provider" (bool, default true);
	 * needs_info takes "note"; reject takes "reason"; paid takes "order_id"; the subscription events
	 * take "subscription_id"; every event takes "actor" ([ name, user_id ]) and "message" (the log
	 * line, when the default is not right).
	 *
	 * @param object|int $request Request object or ID.
	 * @param string     $event Event name.
	 * @param array      $args Event arguments.
	 * @return true|string True, or an error code.
	 */
	public function apply_event( $request, $event, array $args = [] ) {
		if ( ! is_object( $request ) ) {
			$request = $this->get_request( $request );
		}

		if ( ! $request ) {
			return 'not_found';
		}

		$id      = (int) $request->get_id();
		$status  = (string) $request->get_status();
		$outcome = (string) $request->get_outcome();

		$result = State::transition( $status, $outcome, $event );

		if ( is_string( $result ) ) {
			return $result;
		}

		list( $new_status, $new_outcome ) = $result;

		if ( 'approve' === $event && ! $request->get_vendor__id() ) {
			return 'no_vendor';
		}

		$actor   = $this->resolve_actor( $args );
		$now     = time();
		$fields  = [];
		$message = isset( $args['message'] ) ? (string) $args['message'] : '';
		$visible = false;
		$after   = [];

		switch ( $event ) {
			case 'submit':
				$provider = hivepress()->hpve_provider ? hivepress()->hpve_provider->get_active_name() : 'manual';

				// docs_deleted_time goes: retention picks requests WITHOUT it, so a marker left
				// from an earlier decision would keep these new documents forever, breaking the
				// "deleted N days after a decision" promise for every resubmission after an expiry.
				$fields = [
					'submitted_time'    => $now,
					'attempts'          => (int) $request->get_attempts() + 1,
					'note'              => null,
					'reason'            => null,
					'provider'          => $provider,
					'docs_deleted_time' => null,
				];

				if ( ! empty( $args['consent'] ) ) {
					$fields['consent_time'] = $now;
				}

				$event_name = $request->get_attempts() ? 'resubmitted' : 'submitted';
				$visible    = true;

				if ( '' === $message ) {
					$message = 'resubmitted' === $event_name ? esc_html__( 'Documents sent again for review.', 'verification-expiry-for-hivepress' ) : esc_html__( 'Documents sent for review.', 'verification-expiry-for-hivepress' );
				}

				$after[] = 'email_submitted';
				$after[] = 'email_received';

				if ( ! isset( $args['start_provider'] ) || $args['start_provider'] ) {
					$after[] = 'provider_start';
				}

				$event = $event_name;
				break;

			case 'needs_info':
				$fields = [
					'note'          => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
					'reviewed_time' => $now,
					'reviewer'      => $actor['user_id'],
				];

				$visible = true;

				if ( '' === $message ) {
					/* translators: %s: the reviewer's name. */
					$message = sprintf( esc_html__( 'More information requested by %s.', 'verification-expiry-for-hivepress' ), $actor['name'] );
				}

				$after[] = 'email_needs_info';
				break;

			case 'reject':
				$fields = [
					'reason'        => isset( $args['reason'] ) ? sanitize_textarea_field( (string) $args['reason'] ) : '',
					'reviewed_time' => $now,
					'reviewer'      => $actor['user_id'],
				];

				$visible = true;
				$event   = 'rejected';

				if ( '' === $message ) {
					/* translators: %s: the reviewer's name. */
					$message = sprintf( esc_html__( 'Not approved by %s.', 'verification-expiry-for-hivepress' ), $actor['name'] );
				}

				$after[] = 'email_rejected';
				break;

			case 'approve':
				$fields = [
					'reviewed_time' => $now,
					'reviewer'      => $actor['user_id'],
				];

				$visible = true;
				$event   = 'approved';

				if ( '' === $message ) {
					/* translators: %s: the reviewer's name. */
					$message = sprintf( esc_html__( 'Approved by %s.', 'verification-expiry-for-hivepress' ), $actor['name'] );
				}

				$after[] = 'tick_vendor';
				break;

			case 'reopen':
				$event = 'reopened';

				if ( '' === $message ) {
					/* translators: %s: the reviewer's name. */
					$message = sprintf( esc_html__( 'Re-opened for review by %s.', 'verification-expiry-for-hivepress' ), $actor['name'] );
				}

				if ( 'publish' === $status ) {
					$after[] = 'untick_vendor';
				}
				break;

			case 'renew':
				// The old decision date goes, because retention deletes documents of drafts whose
				// reviewed_time is older than the retention period: left in place, the documents
				// uploaded for the renewal would be deleted within the hour. The badge stays: no
				// untick here, and the expiry job still removes it if the old date passes first.
				$fields = [
					'reviewed_time'     => null,
					'docs_deleted_time' => null,
				];

				$event   = 'renewal_started';
				$visible = true;

				if ( '' === $message ) {
					$message = esc_html__( 'Renewal started. The badge stays on while the new documents are reviewed.', 'verification-expiry-for-hivepress' );
				}
				break;

			case 'expire':
				$event   = 'expired';
				$visible = true;

				if ( '' === $message ) {
					$message = esc_html__( 'Verification expired and the badge was removed.', 'verification-expiry-for-hivepress' );
				}
				break;

			case 'revoke':
				$event = 'revoked';

				if ( '' === $message ) {
					/* translators: %s: the person's name. */
					$message = sprintf( esc_html__( 'Verified box unticked by %s.', 'verification-expiry-for-hivepress' ), $actor['name'] );
				}
				break;

			case 'provider_cancel':
				$event = 'cancelled';

				if ( '' === $message ) {
					$message = esc_html__( 'The automated check was cancelled.', 'verification-expiry-for-hivepress' );
				}
				break;

			case 'trash':
				$event = 'trashed';

				if ( '' === $message ) {
					/* translators: %s: the person's name. */
					$message = sprintf( esc_html__( 'Moved to the trash by %s.', 'verification-expiry-for-hivepress' ), $actor['name'] );
				}
				break;

			case 'paid':
				$fields = [
					'paid'          => true,
					'order_id'      => isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0,
					'payment_state' => 'paid',
				];

				if ( $this->get_option( 'paid_priority', true ) ) {
					$fields['priority'] = 1;
				}

				if ( '' === $message ) {
					/* translators: %s: the order number. */
					$message = sprintf( esc_html__( 'Paid, order #%s.', 'verification-expiry-for-hivepress' ), isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0 );
				}
				break;

			case 'refund':
			case 'cancel':
				$fields = [
					'payment_state' => 'refund' === $event ? 'refunded' : 'cancelled',
				];

				$event = 'refund' === $event ? 'refunded' : 'cancelled_payment';

				if ( '' === $message ) {
					$message = 'refunded' === $event ? esc_html__( 'Payment refunded. Verification is not affected.', 'verification-expiry-for-hivepress' ) : esc_html__( 'Order cancelled or failed. Verification is not affected.', 'verification-expiry-for-hivepress' );
				}
				break;

			case 'subscription_active':
				$fields = [
					'paid'            => true,
					'subscription_id' => isset( $args['subscription_id'] ) ? absint( $args['subscription_id'] ) : 0,
					'payment_state'   => 'paid',
				];

				if ( isset( $args['order_id'] ) ) {
					$fields['order_id'] = absint( $args['order_id'] );
				}

				if ( $this->get_option( 'paid_priority', true ) ) {
					$fields['priority'] = 1;
				}

				if ( '' === $message ) {
					/* translators: %s: the subscription number. */
					$message = sprintf( esc_html__( 'Subscription #%s active.', 'verification-expiry-for-hivepress' ), isset( $args['subscription_id'] ) ? absint( $args['subscription_id'] ) : 0 );
				}
				break;

			case 'subscription_on_hold':
			case 'subscription_cancelled':
			case 'subscription_expired':
			case 'subscription_pending_cancel':
				$fields = [
					'payment_state' => \Verification_Expiry\Logic\Hpve_Payment_Rules::payment_state_for_event( $event ),
				];

				if ( '' === $message ) {
					$message = esc_html__( 'Subscription status changed. Verification is not affected until it expires.', 'verification-expiry-for-hivepress' );
				}
				break;

			case 'renewal':
			case 'document_uploaded':
			case 'document_deleted':
			case 'documents_deleted':
			case 'provider_started':
			case 'provider_result':
				if ( 'documents_deleted' === $event ) {
					$visible = true;
				}
				break;
		}

		// Write.
		$this->applying[ $id ] = true;

		$names = array_keys( $fields );

		if ( $new_status !== $status ) {
			$fields['status'] = $new_status;
			$names[]          = 'status';
		}

		if ( $new_outcome !== $outcome ) {
			$fields['outcome'] = '' === $new_outcome ? null : $new_outcome;
			$names[]           = 'outcome';
		}

		if ( $fields ) {
			$request->fill( $fields );

			if ( ! $request->save( $names ) ) {
				unset( $this->applying[ $id ] );

				return 'save_failed';
			}
		}

		unset( $this->applying[ $id ] );

		// Log.
		if ( '' !== $message ) {
			$this->log(
				$id,
				$event,
				$message,
				[
					'actor'   => $actor,
					'visible' => $visible,
				]
			);
		}

		// Side effects, after the row is in its new state so every listener reads the truth.
		foreach ( $after as $step ) {
			$this->run_after( $step, $request, $args );
		}

		return true;
	}

	/**
	 * Whether apply_event() is writing this request right now.
	 *
	 * @param int $request_id Request ID.
	 * @return bool
	 */
	public function is_applying( $request_id ) {
		return ! empty( $this->applying[ (int) $request_id ] );
	}

	/**
	 * Runs one side effect of apply_event().
	 *
	 * @param string $step Step name.
	 * @param object $request Request.
	 * @param array  $args Event arguments.
	 * @return void
	 */
	protected function run_after( $step, $request, array $args ) {
		switch ( $step ) {
			case 'email_submitted':
				if ( $this->get_option( 'request_emails', true ) ) {
					$this->send_email( 'submitted', $request );
				}
				break;

			case 'email_received':
				if ( $this->get_option( 'request_admin_email', true ) ) {
					$this->send_email( 'received', $request );
				}
				break;

			case 'email_needs_info':
				if ( $this->get_option( 'request_emails', true ) ) {
					$this->send_email( 'needs_info', $request );
				}
				break;

			case 'email_rejected':
				if ( $this->get_option( 'request_emails', true ) ) {
					$this->send_email( 'rejected', $request );
				}
				break;

			case 'provider_start':
				$provider = hivepress()->hpve_provider ? hivepress()->hpve_provider->get_provider() : null;

				if ( $provider ) {
					$provider->start( $request->get_id() );
				}
				break;

			case 'tick_vendor':
				$vendor = Models\Vendor::query()->get_by_id( $request->get_vendor__id() );

				if ( $vendor && ! empty( $args['from_tick'] ) ) {
					update_post_meta( $vendor->get_id(), self::META_REQUEST_ID, $request->get_id() );
				} elseif ( $vendor ) {
					update_post_meta( $vendor->get_id(), self::META_REQUEST_ID, $request->get_id() );

					if ( $vendor->is_verified() ) {

						// An approved renewal: the box is already ticked, so saving it again would
						// change nothing and start no clock. Extend from the old date instead.
						hivepress()->hpve_verification->extend_clock( $vendor->get_id() );
					} else {

						// The 1.x tick path: starts the clock, sends Vendor Verified, syncs listing badges.
						$vendor->set_verified( true )->save_verified();
					}
				}
				break;

			case 'untick_vendor':
				$vendor = Models\Vendor::query()->get_by_id( $request->get_vendor__id() );

				if ( $vendor && $vendor->is_verified() ) {
					$vendor->set_verified( false )->save_verified();
				}
				break;
		}
	}

	/**
	 * Who is doing this, for the audit line.
	 *
	 * @param array $args Event arguments.
	 * @return array [ name, user_id, email ]
	 */
	protected function resolve_actor( array $args ) {
		if ( isset( $args['actor'] ) && is_array( $args['actor'] ) ) {
			return [
				'name'    => isset( $args['actor']['name'] ) ? (string) $args['actor']['name'] : esc_html__( 'System', 'verification-expiry-for-hivepress' ),
				'user_id' => isset( $args['actor']['user_id'] ) ? absint( $args['actor']['user_id'] ) : 0,
				'email'   => isset( $args['actor']['email'] ) ? (string) $args['actor']['email'] : '',
			];
		}

		$user = wp_get_current_user();

		if ( $user && $user->ID ) {
			return [
				'name'    => $user->display_name,
				'user_id' => (int) $user->ID,
				'email'   => $user->user_email,
			];
		}

		return [
			'name'    => esc_html__( 'Scheduled job', 'verification-expiry-for-hivepress' ),
			'user_id' => 0,
			'email'   => '',
		];
	}

	/*
	--------------------------------------------------------------------------
	Audit trail.
	--------------------------------------------------------------------------
	*/

	/**
	 * Writes one line of history. The only place an Hpve_Log row is created.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $action Action key.
	 * @param string $text The translated sentence.
	 * @param array  $args "actor" ([ name, user_id, email ]) and "visible" (bool).
	 * @return int Log ID, or 0.
	 */
	public function log( $request_id, $action, $text, array $args = [] ) {
		$actor = isset( $args['actor'] ) && is_array( $args['actor'] ) ? $args['actor'] : $this->resolve_actor( [] );

		$log = new Models\Hpve_Log();

		$log->fill(
			[
				'request'      => absint( $request_id ),
				'user'         => isset( $actor['user_id'] ) ? absint( $actor['user_id'] ) : 0,
				'author'       => isset( $actor['name'] ) ? (string) $actor['name'] : '',
				'author_email' => isset( $actor['email'] ) ? (string) $actor['email'] : '',
				'text'         => (string) $text,
				'approved'     => 1,
				'action'       => sanitize_key( $action ),
				'visible'      => ! empty( $args['visible'] ) || in_array( $action, self::VISIBLE_ACTIONS, true ),
			]
		);

		if ( ! $log->save() ) {
			return 0;
		}

		return (int) $log->get_id();
	}

	/**
	 * Reads a request's history, newest first.
	 *
	 * The default comment status is used on purpose: 'any' would return the trash
	 * (resources/wordpress-php-notes.md, "get_comments( 'status' => 'any' ) returns the TRASH").
	 *
	 * @param int  $request_id Request ID.
	 * @param bool $visible_only Only lines the applicant may see.
	 * @return array Hpve_Log objects.
	 */
	public function get_log( $request_id, $visible_only = false ) {
		$args = [
			'type'    => self::LOG_TYPE,
			'post_id' => absint( $request_id ),
			'orderby' => 'comment_ID',
			'order'   => 'DESC',
		];

		if ( $visible_only ) {
			$args['meta_key']   = 'hp_hpve_visible'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a request has a few dozen lines at most.
			$args['meta_value'] = '1'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		$rows = [];

		foreach ( get_comments( $args ) as $comment ) {
			$log = Models\Hpve_Log::query()->get_by_id( $comment );

			if ( $log ) {
				$rows[] = $log;
			}
		}

		return $rows;
	}

	/*
	--------------------------------------------------------------------------
	Listeners.
	--------------------------------------------------------------------------
	*/

	/**
	 * Logs a status change that did not come through apply_event().
	 *
	 * Quick Edit, bulk trash and a direct wp_update_post() all fire this. A move into the trash by
	 * WordPress's own row action is expected and logged as such; anything else is logged so a stray
	 * write is visible in the History box.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param object $object Request object.
	 * @return void
	 */
	public function track_status_change( $request_id, $new_status, $old_status, $object ) {
		if ( $this->is_applying( $request_id ) ) {
			return;
		}

		$actor = $this->resolve_actor( [] );

		if ( 'trash' === $new_status ) {
			/* translators: %s: the person's name. */
			$this->log( $request_id, 'trashed', sprintf( esc_html__( 'Moved to the trash by %s.', 'verification-expiry-for-hivepress' ), $actor['name'] ), [ 'actor' => $actor ] );

			return;
		}

		if ( 'trash' === $old_status ) {
			/* translators: %s: the person's name. */
			$this->log( $request_id, 'status_changed', sprintf( esc_html__( 'Restored from the trash by %s.', 'verification-expiry-for-hivepress' ), $actor['name'] ), [ 'actor' => $actor ] );

			return;
		}

		if ( 'new' === $old_status || 'auto-draft' === $old_status ) {
			return;
		}

		$this->log(
			$request_id,
			'status_changed',
			sprintf(
				/* translators: 1: old status, 2: new status, 3: the person's name. */
				esc_html__( 'Status changed from %1$s to %2$s by %3$s outside the review screen.', 'verification-expiry-for-hivepress' ),
				$old_status,
				$new_status,
				$actor['name']
			),
			[ 'actor' => $actor ]
		);
	}

	/**
	 * Keeps the request in step when the Verified box moves without the queue.
	 *
	 * Truthy with a pending request: an admin ticked the box instead of using Verifications, so the
	 * request is approved and the log says how. Falsy with an approved request: the hourly expiry (which
	 * writes META_EXPIRED first, class-hpve-verification.php:758) or a hand untick.
	 *
	 * @param int   $vendor_id Vendor ID.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function sync_vendor_verified( $vendor_id, $value ) {
		$request = $this->get_for_vendor( $vendor_id );

		if ( ! $request ) {
			return;
		}

		$status = (string) $request->get_status();

		if ( $value ) {
			if ( 'pending' === $status ) {
				$actor = $this->resolve_actor( [] );

				$this->apply_event(
					$request,
					'approve',
					[
						'actor'     => $actor,
						/* translators: %s: the person's name. */
						'message'   => sprintf( esc_html__( 'Approved by %s ticking the Verified box on the Vendor.', 'verification-expiry-for-hivepress' ), $actor['name'] ),

						// The tick itself already started the clock (update_verified); without this
						// flag tick_vendor would see a verified Vendor and extend a second period.
						'from_tick' => true,
					]
				);
			}

			return;
		}

		if ( 'publish' !== $status ) {
			return;
		}

		$expired = absint( get_post_meta( $vendor_id, Hpve_Verification::META_EXPIRED, true ) );

		if ( $expired && $expired >= time() - MINUTE_IN_SECONDS ) {
			$this->apply_event( $request, 'expire' );
		} else {
			$this->apply_event( $request, 'revoke' );
		}
	}

	/*
	--------------------------------------------------------------------------
	Emails.
	--------------------------------------------------------------------------
	*/

	/**
	 * Builds the tokens every request email shares.
	 *
	 * @param object $request Request.
	 * @return array
	 */
	public function get_email_tokens( $request ) {
		$user = get_userdata( (int) $request->get_user__id() );

		$review_days = $this->get_number_option( 'review_days', 3 );
		$review_note = '';

		if ( $review_days > 0 ) {
			/* translators: %d: number of working days. */
			$review_note = ', ' . sprintf( _n( 'usually within %d working day', 'usually within %d working days', $review_days, 'verification-expiry-for-hivepress' ), $review_days );
		}

		$vendor_name = '';
		$vendor_id   = (int) $request->get_vendor__id();

		if ( $vendor_id ) {
			$vendor_name = (string) get_the_title( $vendor_id );
		}

		if ( '' === $vendor_name && $user ) {
			$vendor_name = $user->display_name;
		}

		$paid_note = '';

		if ( $request->is_paid() ) {
			$paid_note = esc_html__( 'This is a paid request, so it should be reviewed first.', 'verification-expiry-for-hivepress' );
		}

		$priority_note = $this->get_option( 'paid_priority', true ) ? esc_html__( 'Paid requests are reviewed first.', 'verification-expiry-for-hivepress' ) : '';

		return [
			'user_name'        => $user ? $user->display_name : '',
			'user_email'       => $user ? $user->user_email : '',
			'vendor_name'      => $vendor_name,
			'review_note'      => $review_note,
			'verification_url' => hivepress()->router->get_url( 'hpve_verification_page' ),
			'review_url'       => admin_url( 'post.php?post=' . (int) $request->get_id() . '&action=edit' ),
			'note'             => (string) $request->get_note(),
			'reason'           => (string) $request->get_reason(),
			'order_number'     => (string) $request->get_order_id(),
			'paid_note'        => $paid_note,
			'priority_note'    => $priority_note,
		];
	}

	/**
	 * Sends one of the request emails.
	 *
	 * @param string $type submitted | received | needs_info | rejected | paid | signoff.
	 * @param object $request Request.
	 * @return void
	 */
	public function send_email( $type, $request ) {
		$tokens = $this->get_email_tokens( $request );

		$recipient = in_array( $type, [ 'received', 'signoff' ], true ) ? get_option( 'admin_email' ) : $tokens['user_email'];

		if ( ! $recipient ) {
			return;
		}

		$args = [
			'recipient' => $recipient,
			'tokens'    => $tokens,
		];

		switch ( $type ) {
			case 'submitted':
				( new Emails\Hpve_Request_Submitted( $args ) )->send();
				break;
			case 'received':
				( new Emails\Hpve_Request_Received( $args ) )->send();
				break;
			case 'needs_info':
				( new Emails\Hpve_Request_Needs_Info( $args ) )->send();
				break;
			case 'rejected':
				( new Emails\Hpve_Request_Rejected( $args ) )->send();
				break;
			case 'paid':
				( new Emails\Hpve_Request_Paid( $args ) )->send();
				break;
			case 'signoff':
				( new Emails\Hpve_Request_Signoff( $args ) )->send();
				break;
		}
	}

	/*
	--------------------------------------------------------------------------
	Upgrade and rewrite rules.
	--------------------------------------------------------------------------
	*/

	/**
	 * Seeds the document types once and records the version.
	 *
	 * The types are seeded so that an emptied repeater ('' in the option) can mean "no document
	 * types" deliberately rather than being confused with "never saved".
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$stored = (string) get_option( 'hp_' . HPVE_OPTION_PREFIX . 'version', '' );

		if ( HPVE_VERSION === $stored ) {
			return;
		}

		if ( null === get_option( 'hp_' . HPVE_OPTION_PREFIX . 'doc_types', null ) ) {
			add_option( 'hp_' . HPVE_OPTION_PREFIX . 'doc_types', Doc_Types::defaults( self::get_default_type_labels() ), '', false );
		}

		update_option( 'hp_' . HPVE_OPTION_PREFIX . 'version', HPVE_VERSION, false );

		// A new version may have added a page route; the cached rules would 404 it.
		hivepress()->router->flush_rewrite_rules();
	}

	/**
	 * Flushes when the stored version differs, for an upgrade done by copying files.
	 *
	 * The activation hook sets a flag and maybe_upgrade() already flushed at priority 15; this runs
	 * at 20, after the router registered routes at 10, so the very next request builds fresh rules.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'hp_' . HPVE_OPTION_PREFIX . 'flush', '' ) ) {
			delete_option( 'hp_' . HPVE_OPTION_PREFIX . 'flush' );

			hivepress()->router->flush_rewrite_rules();
		}
	}

	/*
	--------------------------------------------------------------------------
	Account menu and call to action.
	--------------------------------------------------------------------------
	*/

	/**
	 * Adds the Verification item at the constructor stage.
	 *
	 * @param array $menu Menu arguments.
	 * @return array
	 */
	public function add_menu_item( $menu ) {
		if ( ! $this->is_enabled() || ! is_user_logged_in() ) {
			return $menu;
		}

		$menu['items']['hpve_verification'] = $this->get_menu_item_args();

		return $menu;
	}

	/**
	 * Adds the Verification item at the items stage too.
	 *
	 * @param array  $items Menu items.
	 * @param object $menu Menu object.
	 * @return array
	 */
	public function add_menu_items( $items, $menu ) {
		if ( ! $this->is_enabled() || ! is_user_logged_in() ) {
			return $items;
		}

		if ( ! isset( $items['hpve_verification'] ) ) {
			$items['hpve_verification'] = $this->get_menu_item_args();
		}

		return $items;
	}

	/**
	 * The menu item, with a state word beneath it when something needs attention.
	 *
	 * @return array
	 */
	protected function get_menu_item_args() {
		$args = [
			'route'  => 'hpve_verification_page',
			'_order' => 45,
		];

		if ( ! is_admin() ) {
			$user_id = get_current_user_id();
			$request = $this->get_for_user( $user_id );

			if ( $request ) {

				// The Vendor is not needed here: every state that carries a word has a request.
				$word = $this->get_menu_word( $this->get_card_state( $request, null ) );

				if ( '' !== $word ) {
					$args['meta'] = $word;
				}
			}
		}

		return $args;
	}

	/**
	 * Injects the "Get verified" block above the page content.
	 *
	 * The block decides for itself whether to render anything, so the injection is unconditional and
	 * cheap.
	 *
	 * @param array  $blocks Template blocks.
	 * @param object $template Template object.
	 * @return array
	 */
	public function add_cta_block( $blocks, $template ) {
		if ( ! $this->is_enabled() || ! is_user_logged_in() ) {
			return $blocks;
		}

		return hivepress()->template->merge_blocks(
			$blocks,
			[
				'page_content' => [
					'blocks' => [
						'hpve_verification_cta' => [
							'type'   => 'hpve_verification_cta',
							'_order' => 5,
						],
					],
				],
			]
		);
	}

	/*
	--------------------------------------------------------------------------
	Retention.
	--------------------------------------------------------------------------
	*/

	/**
	 * Queues the first retention batch.
	 *
	 * @return void
	 */
	public function queue_retention() {
		$scheduler = hivepress()->scheduler;

		if ( $scheduler ) {
			$scheduler->add_action( 'hpve_retention', [ 0 ] );
		}
	}

	/**
	 * Deletes the documents of decided requests older than the retention period.
	 *
	 * @param int $after_id Continue after this request ID.
	 * @return void
	 */
	public function run_retention( $after_id = 0 ) {
		$days = $this->get_number_option( 'doc_retention_days', 30 );

		if ( $days < 1 ) {
			return;
		}

		$cutoff = time() - $days * DAY_IN_SECONDS;

		// Handled requests gain docs_deleted_time and drop out of the NOT EXISTS clause, so the
		// batches walk forward without a cursor; $after_id only varies the job's arguments.
		$requests = Models\Hpve_Request::query()->filter(
			[
				'status__in'         => [ 'draft', 'publish' ],
				'reviewed_time__lte' => $cutoff,
				'docs_deleted_time'  => null,
			]
		)->order( [ 'id' => 'asc' ] )
		->limit( self::RETENTION_BATCH )
		->get();

		$last_id = 0;

		foreach ( $requests as $request ) {
			$last_id = (int) $request->get_id();

			$this->delete_documents( $request, esc_html__( 'Scheduled job', 'verification-expiry-for-hivepress' ) );
		}

		$storage = hivepress()->hpve_storage;

		if ( $storage ) {
			$storage->sweep_orphans( $cutoff );
		}

		if ( $requests->count() >= self::RETENTION_BATCH && $last_id ) {
			hivepress()->scheduler->add_action( 'hpve_retention', [ $last_id ] );
		}
	}

	/**
	 * Deletes every document of a request and records it.
	 *
	 * @param object $request Request.
	 * @param string $actor_name Who did it, for the log line.
	 * @return int Files deleted.
	 */
	public function delete_documents( $request, $actor_name ) {
		$deleted = 0;

		foreach ( $this->get_documents( $request->get_id() ) as $attachment ) {
			if ( wp_delete_attachment( $attachment->get_id(), true ) ) {
				++$deleted;
			}
		}

		$request->set_docs_deleted_time( time() )->save( [ 'docs_deleted_time' ] );

		$provider = hivepress()->hpve_provider;

		if ( $provider && $this->get_option( 'stripe_redact', false ) && 'stripe_identity' === (string) $request->get_provider() && $request->get_provider_ref() ) {
			hivepress()->scheduler->add_action( 'hpve_stripe_redact', [ (int) $request->get_id() ] );
		}

		$this->apply_event(
			$request,
			'documents_deleted',
			[
				'actor'   => [
					'name'    => $actor_name,
					'user_id' => get_current_user_id(),
				],
				/* translators: %s: today's date. */
				'message' => sprintf( esc_html__( 'Your documents were deleted on %s as part of routine housekeeping.', 'verification-expiry-for-hivepress' ), wp_date( get_option( 'date_format' ) ) ),
			]
		);

		return $deleted;
	}

	/*
	--------------------------------------------------------------------------
	Privacy tools.
	--------------------------------------------------------------------------
	*/

	/**
	 * Registers the personal data exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['verification-expiry-for-hivepress'] = [
			'exporter_friendly_name' => esc_html__( 'Verification requests', 'verification-expiry-for-hivepress' ),
			'callback'               => [ $this, 'export_personal_data' ],
		];

		return $exporters;
	}

	/**
	 * Registers the personal data eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['verification-expiry-for-hivepress'] = [
			'eraser_friendly_name' => esc_html__( 'Verification requests', 'verification-expiry-for-hivepress' ),
			'callback'             => [ $this, 'erase_personal_data' ],
		];

		return $erasers;
	}

	/**
	 * Exports a user's requests: status, dates, notes and document type names, never the files.
	 *
	 * @param string $email Email address.
	 * @param int    $page Page.
	 * @return array
	 */
	public function export_personal_data( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );
		$data = [];

		if ( $user ) {
			$requests = Models\Hpve_Request::query()->filter(
				[
					'user'       => $user->ID,
					'status__in' => [ 'draft', 'pending', 'publish', 'trash' ],
				]
			)->get();

			foreach ( $requests as $request ) {
				$counts = $this->count_documents( $request->get_id() );
				$types  = [];

				foreach ( $this->get_document_types( true ) as $row ) {
					if ( ! empty( $counts[ $row['key'] ] ) ) {
						$types[] = $row['label'] . ' (' . (int) $counts[ $row['key'] ] . ')';
					}
				}

				$data[] = [
					'group_id'    => 'hpve_requests',
					'group_label' => esc_html__( 'Verification requests', 'verification-expiry-for-hivepress' ),
					'item_id'     => 'hpve-request-' . (int) $request->get_id(),
					'data'        => [
						[
							'name'  => esc_html__( 'Status', 'verification-expiry-for-hivepress' ),
							'value' => (string) $request->get_status() . ( $request->get_outcome() ? ' / ' . $request->get_outcome() : '' ),
						],
						[
							'name'  => esc_html__( 'Created', 'verification-expiry-for-hivepress' ),
							'value' => (string) $request->get_created_date(),
						],
						[
							'name'  => esc_html__( 'Submitted', 'verification-expiry-for-hivepress' ),
							'value' => $request->get_submitted_time() ? wp_date( 'Y-m-d H:i', (int) $request->get_submitted_time() ) : '',
						],
						[
							'name'  => esc_html__( 'Reviewed', 'verification-expiry-for-hivepress' ),
							'value' => $request->get_reviewed_time() ? wp_date( 'Y-m-d H:i', (int) $request->get_reviewed_time() ) : '',
						],
						[
							'name'  => esc_html__( 'Note from the reviewer', 'verification-expiry-for-hivepress' ),
							'value' => (string) $request->get_note(),
						],
						[
							'name'  => esc_html__( 'Reason given', 'verification-expiry-for-hivepress' ),
							'value' => (string) $request->get_reason(),
						],
						[
							'name'  => esc_html__( 'Your note', 'verification-expiry-for-hivepress' ),
							'value' => (string) $request->get_applicant_note(),
						],
						[
							'name'  => esc_html__( 'Documents held', 'verification-expiry-for-hivepress' ),
							'value' => implode( ', ', $types ),
						],
					],
				];
			}
		}

		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Erases a user's requests, documents and history.
	 *
	 * Deleting the post fires HivePress's cascade, which deletes the attachments by parent, and the
	 * storage component unlinks each file; WordPress deletes the comments with the post.
	 *
	 * @param string $email Email address.
	 * @param int    $page Page.
	 * @return array
	 */
	public function erase_personal_data( $email, $page = 1 ) {
		$user    = get_user_by( 'email', $email );
		$removed = false;

		if ( $user ) {
			$requests = Models\Hpve_Request::query()->filter(
				[
					'user'       => $user->ID,
					'status__in' => [ 'draft', 'pending', 'publish', 'trash' ],
				]
			)->get();

			foreach ( $requests as $request ) {
				foreach ( $this->get_documents( $request->get_id() ) as $attachment ) {
					wp_delete_attachment( $attachment->get_id(), true );
				}

				$vendor_id = (int) $request->get_vendor__id();

				if ( $vendor_id ) {
					delete_post_meta( $vendor_id, self::META_REQUEST_ID );
					delete_post_meta( $vendor_id, self::META_SUBSCRIPTION_ID );
				}

				wp_delete_post( $request->get_id(), true );

				$removed = true;
			}
		}

		return [
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
