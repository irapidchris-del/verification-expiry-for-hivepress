<?php
/**
 * Shared behaviour for the free public-register providers.
 *
 * These check a business rather than a person: the applicant types one reference (a company
 * number, a VAT number) and the plugin asks a public register whether it exists, whether it is
 * still trading and whose name is on it. There is no hosted page to send anybody to and no
 * webhook to receive, so the whole flow is start() queueing one job and sync() making one call.
 *
 * They are free in the sense that matters to a site owner: neither register charges per lookup.
 * Companies House still needs a free API key; HMRC and VIES need nothing at all.
 *
 * **This is not identity verification and the settings copy must never imply that it is.** It
 * proves a business exists and that its registered name matches the Vendor's, which is a useful
 * signal for a marketplace of tradespeople and a worthless one for proving that the person
 * holding the account is who they say. A site that needs the latter wants Stripe Identity,
 * Persona or ComplyCube.
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

use Verification_Expiry\Logic\Hpve_Registry_Mapper as Mapper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A free public register.
 */
abstract class Hpve_Provider_Registry implements Hpve_Provider_Interface {

	/**
	 * The HTTP client. Injected by the tests; built on demand otherwise.
	 *
	 * @var Hpve_Registry_Http|null
	 */
	protected $http = null;

	/**
	 * Class constructor.
	 *
	 * @param Hpve_Registry_Http|null $http HTTP client.
	 */
	public function __construct( $http = null ) {
		if ( $http instanceof Hpve_Registry_Http ) {
			$this->http = $http;
		}
	}

	/**
	 * Gets the HTTP client.
	 *
	 * @return Hpve_Registry_Http
	 */
	public function get_http() {
		if ( ! $this->http ) {
			$this->http = new Hpve_Registry_Http( null, null, HPVE_VERSION );
		}

		return $this->http;
	}

	/*
	--------------------------------------------------------------------------
	What each register fills in.
	--------------------------------------------------------------------------
	*/

	/**
	 * The label of the reference field on the request form, e.g. "Company number".
	 *
	 * @return string
	 */
	abstract public function get_reference_label();

	/**
	 * A short instruction under that field.
	 *
	 * @return string
	 */
	abstract public function get_reference_help();

	/**
	 * Normalises what the applicant typed, or returns an empty string when it cannot be valid.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	abstract public function normalise_reference( $value );

	/**
	 * Looks the reference up and maps the answer.
	 *
	 * @param string $reference Normalised reference.
	 * @param string $claimed_name The applicant's business name.
	 * @return array|\WP_Error Mapped result, or an error to log and leave pending.
	 */
	abstract protected function look_up( $reference, $claimed_name );

	/*
	--------------------------------------------------------------------------
	The interface.
	--------------------------------------------------------------------------
	*/

	/**
	 * These providers ask for a typed reference rather than a hosted flow.
	 *
	 * @return bool
	 */
	public function needs_reference() {
		return true;
	}

	/**
	 * Whether documents are still collected alongside the register check.
	 *
	 * @return bool
	 */
	public function supports_documents() {
		return (bool) hivepress()->hpve_request->get_option( 'doc_collect_with_provider', false );
	}

	/**
	 * Queues the lookup. The request is already pending.
	 *
	 * @param int $request_id Request ID.
	 * @return true|\WP_Error
	 */
	public function start( $request_id ) {
		$request = hivepress()->hpve_request->get_request( $request_id );

		if ( ! $request ) {
			return new \WP_Error( 'hpve_not_found', 'Request not found.' );
		}

		$attempt = (int) $request->get_provider_attempts() + 1;

		$request->fill(
			[
				'provider'          => $this->get_name(),
				'provider_attempts' => $attempt,
			]
		)->save( [ 'provider', 'provider_attempts' ] );

		$reference = $this->normalise_reference( (string) $request->get_business_ref() );

		// Refused here rather than in the job, so the applicant is told at once instead of waiting
		// for a background run to tell them the number they typed could never have been looked up.
		if ( '' === $reference ) {
			hivepress()->hpve_request->apply_event(
				$request,
				'needs_info',
				[
					'actor' => $this->get_actor(),
					'note'  => $this->get_invalid_message(),
				]
			);

			return true;
		}

		hivepress()->hpve_request->apply_event(
			$request,
			'provider_started',
			[
				'actor'   => $this->get_actor(),
				'message' => sprintf(
					/* translators: 1: the register's name, 2: attempt number. */
					esc_html__( '%1$s check started (attempt %2$d).', 'verification-expiry-for-hivepress' ),
					$this->get_label(),
					$attempt
				),
			]
		);

		hivepress()->scheduler->add_action( 'hpve_provider_sync', [ (int) $request_id, $this->get_name(), 'check' ] );

		return true;
	}

	/**
	 * No hosted flow.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public function get_redirect_url( $request_id ) {
		return '';
	}

	/**
	 * No return leg.
	 *
	 * @param int   $request_id Request ID.
	 * @param array $params Parameters.
	 * @return true
	 */
	public function handle_return( $request_id, array $params ) {
		return true;
	}

	/**
	 * No webhook: a register is asked, it never calls back.
	 *
	 * @param string $body Body.
	 * @param array  $headers Headers.
	 * @return int
	 */
	public function handle_webhook( $body, array $headers ) {
		return 404;
	}

	/**
	 * Background job: looks the reference up and applies the result.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $event_id What triggered this.
	 * @return void
	 */
	public function sync( $request_id, $event_id ) {
		$request = hivepress()->hpve_request->get_request( $request_id );

		// Re-read everything inside the job: the owner may have changed settings in the gap, and a
		// request an admin has already decided by hand must never be overwritten by a late job.
		if ( ! $request || 'pending' !== (string) $request->get_status() || $this->get_name() !== (string) $request->get_provider() || ! $this->is_configured() ) {
			return;
		}

		$reference = $this->normalise_reference( (string) $request->get_business_ref() );

		if ( '' === $reference ) {
			return;
		}

		$mapped = $this->look_up( $reference, $this->get_claimed_name( $request ) );

		if ( is_wp_error( $mapped ) ) {

			// Left pending on purpose: a register being down is not the applicant's fault and must
			// not read as a refusal. The line tells an admin why nothing moved.
			hivepress()->hpve_request->log(
				$request_id,
				'provider_result',
				sprintf(
					/* translators: 1: the register's name, 2: the error message. */
					esc_html__( '%1$s could not be reached: %2$s', 'verification-expiry-for-hivepress' ),
					$this->get_label(),
					$mapped->get_error_message()
				),
				[ 'actor' => $this->get_actor() ]
			);

			return;
		}

		$request->fill(
			[
				'provider_ref'    => $reference,
				'provider_status' => sanitize_key( $mapped['status'] ),
				'provider_error'  => 'ok' === $mapped['note'] ? '' : sanitize_key( $mapped['note'] ),
			]
		)->save( [ 'provider_ref', 'provider_status', 'provider_error' ] );

		$this->apply_mapped( $request, $mapped );
	}

	/**
	 * Turns a mapped result into a request event.
	 *
	 * @param object $request Request.
	 * @param array  $mapped Mapped result.
	 * @return void
	 */
	public function apply_mapped( $request, array $mapped ) {
		$component = hivepress()->hpve_request;
		$actor     = $this->get_actor();

		switch ( $mapped['action'] ) {
			case 'approve':
				$component->apply_event(
					$request,
					'approve',
					[
						'actor'   => $actor,
						'message' => sprintf(
							/* translators: 1: the register's name, 2: the registered business name. */
							esc_html__( 'Approved automatically: %1$s confirmed %2$s.', 'verification-expiry-for-hivepress' ),
							$this->get_label(),
							'' !== $mapped['name'] ? $mapped['name'] : $this->get_reference_label()
						),
					]
				);
				break;

			case 'hold_for_signoff':
				$component->apply_event(
					$request,
					'provider_result',
					[
						'actor'   => $actor,
						'message' => $this->get_signoff_message( $mapped ),
					]
				);

				$component->send_email( 'signoff', $request );
				break;

			case 'reject':
				$component->apply_event(
					$request,
					'reject',
					[
						'actor'  => $actor,
						'reason' => $this->get_applicant_message( $mapped ),
					]
				);
				break;

			case 'needs_info':
			default:
				$component->apply_event(
					$request,
					'needs_info',
					[
						'actor' => $actor,
						'note'  => $this->get_applicant_message( $mapped ),
					]
				);
				break;
		}
	}

	/*
	--------------------------------------------------------------------------
	Copy.
	--------------------------------------------------------------------------
	*/

	/**
	 * The sentence the applicant reads for a given outcome.
	 *
	 * @param array $mapped Mapped result.
	 * @return string
	 */
	protected function get_applicant_message( array $mapped ) {
		switch ( $mapped['note'] ) {
			case 'not_found':
				return $this->get_not_found_message();

			case 'dead':
				/* translators: %s: the registered business name. */
				return sprintf( esc_html__( 'The register shows that %s is no longer trading, so we cannot verify it.', 'verification-expiry-for-hivepress' ), '' !== $mapped['name'] ? $mapped['name'] : esc_html__( 'this business', 'verification-expiry-for-hivepress' ) );

			case 'name_mismatch':
				return sprintf(
					/* translators: 1: the name on the register, 2: the label of the reference field. */
					esc_html__( 'That reference is registered to %1$s, which does not match your name here. Please check the %2$s, or change your name to the registered one and send it again.', 'verification-expiry-for-hivepress' ),
					$mapped['name'],
					strtolower( $this->get_reference_label() )
				);

			case 'unreadable':
			default:
				return esc_html__( 'We could not read the register\'s answer. Please try again, or send us your documents instead.', 'verification-expiry-for-hivepress' );
		}
	}

	/**
	 * The line an admin reads when a result is held for them.
	 *
	 * @param array $mapped Mapped result.
	 * @return string
	 */
	protected function get_signoff_message( array $mapped ) {
		if ( 'distressed' === $mapped['note'] ) {
			return sprintf(
				/* translators: 1: the registered business name, 2: the register status, e.g. "liquidation". */
				esc_html__( '%1$s exists but the register lists it as "%2$s". Approve or reject this one by hand.', 'verification-expiry-for-hivepress' ),
				'' !== $mapped['name'] ? $mapped['name'] : esc_html__( 'The business', 'verification-expiry-for-hivepress' ),
				$mapped['status']
			);
		}

		if ( 'unknown_status' === $mapped['note'] ) {
			return sprintf(
				/* translators: 1: the registered business name, 2: the register status. */
				esc_html__( '%1$s was found, but this plugin does not recognise the register status "%2$s", so it has not decided. Approve or reject this one by hand.', 'verification-expiry-for-hivepress' ),
				'' !== $mapped['name'] ? $mapped['name'] : esc_html__( 'The business', 'verification-expiry-for-hivepress' ),
				$mapped['status']
			);
		}

		if ( 'no_name' === $mapped['note'] ) {
			return sprintf(
				/* translators: %s: the register's name. */
				esc_html__( '%s confirmed the number, but the register does not publish the trader\'s name, so it could not be matched. Waiting for you to approve.', 'verification-expiry-for-hivepress' ),
				$this->get_label()
			);
		}

		return sprintf(
			/* translators: 1: the register's name, 2: the registered business name. */
			esc_html__( '%1$s confirmed %2$s; waiting for an admin to approve.', 'verification-expiry-for-hivepress' ),
			$this->get_label(),
			'' !== $mapped['name'] ? $mapped['name'] : esc_html__( 'the business', 'verification-expiry-for-hivepress' )
		);
	}

	/**
	 * What the applicant is told when the reference could never be looked up.
	 *
	 * @return string
	 */
	abstract protected function get_invalid_message();

	/**
	 * What the applicant is told when the register has no such entry.
	 *
	 * @return string
	 */
	abstract protected function get_not_found_message();

	/*
	--------------------------------------------------------------------------
	Helpers.
	--------------------------------------------------------------------------
	*/

	/**
	 * Whether a name mismatch should stop an automatic approval.
	 *
	 * @return bool
	 */
	protected function requires_name_match() {
		return (bool) hivepress()->hpve_request->get_option( 'registry_require_name', true );
	}

	/**
	 * Whether a pass waits for an admin.
	 *
	 * @return bool
	 */
	protected function requires_signoff() {
		return (bool) hivepress()->hpve_request->get_option( 'registry_admin_signoff', false );
	}

	/**
	 * The business name the applicant is claiming.
	 *
	 * The Vendor's name is the one that matters: it is what visitors see beside the verified badge,
	 * so it is the name the register has to agree with. The account's display name is the fallback
	 * for a request made before a Vendor exists.
	 *
	 * @param object $request Request.
	 * @return string
	 */
	protected function get_claimed_name( $request ) {
		$vendor_id = (int) $request->get_vendor__id();

		if ( $vendor_id ) {
			$vendor = \HivePress\Models\Vendor::query()->get_by_id( $vendor_id );

			if ( $vendor && '' !== (string) $vendor->get_name() ) {
				return (string) $vendor->get_name();
			}
		}

		$user = get_userdata( (int) $request->get_user__id() );

		return $user ? (string) $user->display_name : '';
	}

	/**
	 * The actor recorded on every line this provider writes.
	 *
	 * @return array
	 */
	protected function get_actor() {
		return [
			'name'    => $this->get_label(),
			'user_id' => 0,
			'email'   => '',
		];
	}
}
