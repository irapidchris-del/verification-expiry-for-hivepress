<?php
/**
 * Stripe Identity provider.
 *
 * Creates a VerificationSession from the WooCommerce Stripe gateway's key, sends the applicant to
 * Stripe's hosted page, takes the result back through a signed webhook (and the return page as a
 * belt and braces), and maps it to a request event. Every call to Stripe runs in a scheduler job:
 * nothing here calls out during a visitor's request (resources/security-standards.md, rule 5).
 *
 * What is stored: the session id (vs_...), its livemode flag, its last status and last error code.
 * Never the session URL or client secret, which Stripe says must not be stored, logged or emailed
 * (docs.stripe.com/api/identity/verification_sessions/object, read 2026-09-06).
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

use Verification_Expiry\Logic\Hpve_Stripe_Signature as Signature;
use Verification_Expiry\Logic\Hpve_Stripe_Mapper as Mapper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Stripe Identity.
 */
final class Hpve_Provider_Stripe implements Hpve_Provider_Interface {

	/**
	 * How long a fetched session URL waits for the applicant's browser to collect it.
	 */
	const URL_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Webhook event ids are remembered for this long.
	 */
	const EVENT_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * The HTTP client. Injected by the tests; built from the gateway's settings otherwise.
	 *
	 * @var Hpve_Stripe_Http|null
	 */
	protected $http = null;

	/**
	 * Class constructor.
	 *
	 * @param Hpve_Stripe_Http|null $http HTTP client.
	 */
	public function __construct( $http = null ) {
		if ( $http instanceof Hpve_Stripe_Http ) {
			$this->http = $http;
		}
	}

	/**
	 * Gets the HTTP client.
	 *
	 * @return Hpve_Stripe_Http
	 */
	public function get_http() {
		if ( ! $this->http ) {
			$this->http = new Hpve_Stripe_Http( null, null, null, HPVE_VERSION );
		}

		return $this->http;
	}

	/**
	 * Gets the stored name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'stripe_identity';
	}

	/**
	 * Gets the label. A product name, so it is not translated.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Stripe Identity';
	}

	/**
	 * Configured when the gateway is active with a key for its mode and a webhook secret for the same mode.
	 *
	 * Gated on the live class rather than a stored option alone: the mode toggle the owner sees is
	 * the gateway's, so it must be present for the choice to mean anything.
	 *
	 * @return bool
	 */
	public function is_configured() {
		if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
			return false;
		}

		$key = Hpve_Stripe_Http::describe_gateway_key();

		return $key['present'] && '' !== $this->get_webhook_secret();
	}

	/**
	 * One sentence for the settings tab when not configured.
	 *
	 * @return string
	 */
	public function get_setup_notice() {
		if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
			return esc_html__( 'The WooCommerce Stripe gateway is not active, so there is no Stripe key to use.', 'verification-expiry-for-hivepress' );
		}

		$key = Hpve_Stripe_Http::describe_gateway_key();

		if ( ! $key['present'] ) {
			return $key['test']
				? esc_html__( 'The Stripe gateway is in test mode but has no test secret key.', 'verification-expiry-for-hivepress' )
				: esc_html__( 'The Stripe gateway is in live mode but has no live secret key.', 'verification-expiry-for-hivepress' );
		}

		if ( '' === $this->get_webhook_secret() ) {
			return $key['test']
				? esc_html__( 'Paste the webhook signing secret for test mode below, then choose Stripe Identity.', 'verification-expiry-for-hivepress' )
				: esc_html__( 'Paste the webhook signing secret for live mode below, then choose Stripe Identity.', 'verification-expiry-for-hivepress' );
		}

		return '';
	}

	/**
	 * A status line for the settings tab: mode and whether a key is present, with the key masked.
	 *
	 * @return string
	 */
	public function describe_gateway() {
		if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
			return esc_html__( 'Stripe gateway: not active.', 'verification-expiry-for-hivepress' );
		}

		$key  = Hpve_Stripe_Http::describe_gateway_key();
		$mode = $key['test'] ? esc_html__( 'test mode', 'verification-expiry-for-hivepress' ) : esc_html__( 'live mode', 'verification-expiry-for-hivepress' );

		if ( $key['present'] ) {
			/* translators: 1: "test mode" or "live mode", 2: the last four characters of the key. */
			return sprintf( esc_html__( 'Stripe gateway: %1$s, key present (ends %2$s).', 'verification-expiry-for-hivepress' ), $mode, $key['last4'] );
		}

		/* translators: %s: "test mode" or "live mode". */
		return sprintf( esc_html__( 'Stripe gateway: %s, no key found.', 'verification-expiry-for-hivepress' ), $mode );
	}

	/**
	 * Whether documents are still collected alongside the Stripe check.
	 *
	 * @return bool
	 */
	public function supports_documents() {
		return (bool) hivepress()->hpve_request->get_option( 'doc_collect_with_provider', false );
	}

	/**
	 * The webhook signing secret for the gateway's current mode.
	 *
	 * @return string
	 */
	public function get_webhook_secret() {
		$key  = Hpve_Stripe_Http::describe_gateway_key();
		$name = $key['test'] ? 'stripe_webhook_secret_test' : 'stripe_webhook_secret_live';

		return trim( (string) hivepress()->hpve_request->get_option( $name, '' ) );
	}

	/*
	--------------------------------------------------------------------------
	Applicant flow.
	--------------------------------------------------------------------------
	*/

	/**
	 * Queues session creation. The request is already pending.
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

		hivepress()->hpve_request->apply_event(
			$request,
			'provider_started',
			[
				'actor'   => $this->get_actor(),
				/* translators: %d: attempt number. */
				'message' => sprintf( esc_html__( 'Stripe Identity check started (attempt %d).', 'verification-expiry-for-hivepress' ), $attempt ),
			]
		);

		hivepress()->scheduler->add_action( 'hpve_stripe_create_session', [ (int) $request_id, $attempt ] );

		return true;
	}

	/**
	 * Hands the stored URL over once, then forgets it.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public function get_redirect_url( $request_id ) {
		$key = 'hpve_session_url_' . absint( $request_id );
		$url = get_transient( $key );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		delete_transient( $key );

		return $url;
	}

	/**
	 * The applicant is back: queue a sync. The return page trusts nothing in the query string.
	 *
	 * @param int   $request_id Request ID.
	 * @param array $params Parameters, unused.
	 * @return true
	 */
	public function handle_return( $request_id, array $params ) {
		hivepress()->scheduler->add_action( 'hpve_stripe_sync', [ absint( $request_id ), 'return' ] );

		return true;
	}

	/**
	 * Background job: creates or retrieves the session and stores its URL for the poll.
	 *
	 * The session URL is single use and expires after 48 hours, so a retry retrieves the same session
	 * for a fresh one rather than creating another. The Idempotency-Key makes a repeated create for
	 * the same attempt return the same session.
	 *
	 * @param int $request_id Request ID.
	 * @param int $attempt Attempt number.
	 * @return void
	 */
	public function create_session_job( $request_id, $attempt ) {
		$request = hivepress()->hpve_request->get_request( $request_id );

		// Re-read everything inside the job: the owner may have changed settings in the gap.
		if ( ! $request || 'pending' !== (string) $request->get_status() || $this->get_name() !== (string) $request->get_provider() || ! $this->is_configured() ) {
			return;
		}

		$session = null;
		$ref     = (string) $request->get_provider_ref();

		if ( Mapper::is_session_id( $ref ) ) {
			$existing = $this->get_http()->retrieve_session( $ref );

			if ( ! is_wp_error( $existing ) && isset( $existing['status'] ) && 'requires_input' === $existing['status'] && ! empty( $existing['url'] ) ) {
				$session = $existing;
			}
		}

		if ( null === $session ) {
			$user = get_userdata( (int) $request->get_user__id() );

			$params = [
				'type'                      => 'document',
				'options[document][require_matching_selfie]' => hivepress()->hpve_request->get_option( 'stripe_require_selfie', true ) ? 'true' : 'false',
				'return_url'                => add_query_arg( 'request_id', (int) $request_id, hivepress()->router->get_url( 'hpve_verification_return_page' ) ),
				'client_reference_id'       => 'hpve-' . (int) $request_id,
				'metadata[hpve_request_id]' => (string) (int) $request_id,
				'metadata[site]'            => home_url(),
			];

			if ( $user && $user->user_email ) {
				$params['provided_details[email]'] = $user->user_email;
			}

			$session = $this->get_http()->create_session( $params, 'hpve-' . substr( md5( home_url() ), 0, 8 ) . '-' . (int) $request_id . '-' . (int) $attempt );
		}

		if ( is_wp_error( $session ) ) {
			hivepress()->hpve_request->apply_event(
				$request,
				'provider_result',
				[
					'actor'   => $this->get_actor(),
					/* translators: %s: the error message. */
					'message' => sprintf( esc_html__( 'Stripe could not start the check: %s', 'verification-expiry-for-hivepress' ), $session->get_error_message() ),
				]
			);

			return;
		}

		if ( ! isset( $session['id'] ) || ! Mapper::is_session_id( $session['id'] ) || empty( $session['url'] ) ) {
			return;
		}

		$request->fill(
			[
				'provider_ref'      => $session['id'],
				'provider_livemode' => ! empty( $session['livemode'] ),
				'provider_status'   => isset( $session['status'] ) ? sanitize_key( $session['status'] ) : 'requires_input',
			]
		)->save( [ 'provider_ref', 'provider_livemode', 'provider_status' ] );

		set_transient( 'hpve_session_url_' . (int) $request_id, esc_url_raw( $session['url'] ), self::URL_TTL );

		hivepress()->hpve_request->log(
			$request_id,
			'provider_started',
			esc_html__( 'Stripe Identity session ready for the applicant.', 'verification-expiry-for-hivepress' ),
			[ 'actor' => $this->get_actor() ]
		);
	}

	/*
	--------------------------------------------------------------------------
	Results.
	--------------------------------------------------------------------------
	*/

	/**
	 * Verifies, dedupes and queues a webhook. Nothing changes and nothing is called on this request.
	 *
	 * @param string $body Raw body.
	 * @param array  $headers Lower-cased headers.
	 * @return int HTTP status.
	 */
	public function handle_webhook( $body, array $headers ) {
		$signature = isset( $headers['stripe-signature'] ) ? (string) $headers['stripe-signature'] : '';

		if ( '' === $body || '' === $signature ) {
			return 400;
		}

		$secret = $this->get_webhook_secret();

		if ( '' === $secret ) {
			return 503;
		}

		if ( ! Signature::verify( $body, $signature, $secret ) ) {
			return 403;
		}

		$event = Mapper::parse_event( json_decode( $body, true ) );

		if ( is_string( $event ) ) {
			// Acknowledged and ignored: an event this plugin does not handle is not Stripe's problem.
			return 200;
		}

		$dedupe = 'hpve_evt_' . $event['event_id'];

		if ( get_transient( $dedupe ) ) {
			return 200;
		}

		set_transient( $dedupe, 1, self::EVENT_TTL );

		$request = hivepress()->hpve_request->get_request( $event['request_id'] );

		if ( ! $request || (string) $request->get_provider_ref() !== $event['session_id'] ) {
			return 200;
		}

		hivepress()->scheduler->add_action( 'hpve_stripe_sync', [ (int) $event['request_id'], $event['event_id'] ] );

		return 200;
	}

	/**
	 * Background job: retrieves the session and applies the mapped event.
	 *
	 * The session is always re-fetched rather than read from the webhook body, so a replayed old
	 * event cannot roll a decision back, and a request that is no longer pending is left alone.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $event_id Event id, 'return' or 'manual'.
	 * @return void
	 */
	public function sync( $request_id, $event_id ) {
		$request = hivepress()->hpve_request->get_request( $request_id );

		if ( ! $request || 'pending' !== (string) $request->get_status() ) {
			return;
		}

		$ref = (string) $request->get_provider_ref();

		if ( ! Mapper::is_session_id( $ref ) ) {
			return;
		}

		$session = $this->get_http()->retrieve_session( $ref );

		if ( is_wp_error( $session ) || ! isset( $session['status'] ) ) {
			return;
		}

		if ( (bool) $request->is_provider_livemode() !== ! empty( $session['livemode'] ) ) {
			hivepress()->hpve_request->log(
				$request_id,
				'provider_result',
				esc_html__( 'A Stripe result from the other mode (test or live) was ignored.', 'verification-expiry-for-hivepress' ),
				[ 'actor' => $this->get_actor() ]
			);

			return;
		}

		$status     = sanitize_key( (string) $session['status'] );
		$error_code = isset( $session['last_error']['code'] ) ? sanitize_key( (string) $session['last_error']['code'] ) : '';
		$reason     = isset( $session['last_error']['reason'] ) ? sanitize_text_field( (string) $session['last_error']['reason'] ) : '';

		$request->fill(
			[
				'provider_status' => $status,
				'provider_error'  => $error_code,
			]
		)->save( [ 'provider_status', 'provider_error' ] );

		$limit   = hivepress()->hpve_request->get_number_option( 'stripe_attempt_limit', 3 );
		$signoff = (bool) hivepress()->hpve_request->get_option( 'stripe_admin_signoff', false );

		$mapped = Mapper::map( $status, $error_code, (int) $request->get_provider_attempts(), $limit, $signoff );

		$this->apply_mapped( $request, $mapped, $reason );
	}

	/**
	 * Turns a mapped result into a request event.
	 *
	 * @param object $request Request.
	 * @param array  $mapped [ action, note ].
	 * @param string $reason Stripe's user-safe sentence, or empty.
	 * @return void
	 */
	public function apply_mapped( $request, array $mapped, $reason ) {
		$component = hivepress()->hpve_request;
		$actor     = $this->get_actor();

		$text = '';

		switch ( $mapped['note'] ) {
			case 'unfinished':
				$text = esc_html__( 'You have not finished the Stripe check. Start it again to continue.', 'verification-expiry-for-hivepress' );
				break;
			case 'consent_declined':
				$text = esc_html__( 'You chose not to go ahead with the Stripe check. You can send your documents to us instead.', 'verification-expiry-for-hivepress' );
				break;
			case 'stripe_reason':
				$text = '' !== $reason ? $reason : esc_html__( 'Stripe could not verify the document you provided.', 'verification-expiry-for-hivepress' );
				break;
			case 'unknown':
				$text = esc_html__( 'Stripe could not complete the check. Please try again or send your documents to us.', 'verification-expiry-for-hivepress' );
				break;
		}

		switch ( $mapped['action'] ) {
			case 'approve':
				$component->apply_event(
					$request,
					'approve',
					[
						'actor'   => $actor,
						'message' => esc_html__( 'Approved automatically: Stripe Identity verified the document.', 'verification-expiry-for-hivepress' ),
					]
				);
				break;

			case 'hold_for_signoff':
				$component->apply_event(
					$request,
					'provider_result',
					[
						'actor'   => $actor,
						'message' => esc_html__( 'Stripe Identity verified the document; waiting for an admin to approve.', 'verification-expiry-for-hivepress' ),
					]
				);

				$component->send_email( 'signoff', $request );
				break;

			case 'needs_info':
				$component->apply_event(
					$request,
					'needs_info',
					[
						'actor' => $actor,
						'note'  => $text,
					]
				);
				break;

			case 'reject':
				$component->apply_event(
					$request,
					'reject',
					[
						'actor'  => $actor,
						'reason' => $text,
					]
				);
				break;

			case 'cancelled':
				$component->apply_event( $request, 'provider_cancel', [ 'actor' => $actor ] );
				break;

			case 'pending':
			default:
				$component->apply_event(
					$request,
					'provider_result',
					[
						'actor'   => $actor,
						'message' => esc_html__( 'Stripe Identity is still processing the document.', 'verification-expiry-for-hivepress' ),
					]
				);
				break;
		}
	}

	/**
	 * Background job: asks Stripe to redact a session once the documents have been deleted here.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	public function redact_job( $request_id ) {
		$request = hivepress()->hpve_request->get_request( $request_id );

		if ( ! $request || ! hivepress()->hpve_request->get_option( 'stripe_redact', false ) ) {
			return;
		}

		$ref = (string) $request->get_provider_ref();

		if ( ! Mapper::is_session_id( $ref ) ) {
			return;
		}

		$result = $this->get_http()->redact_session( $ref );

		hivepress()->hpve_request->log(
			$request_id,
			'provider_result',
			is_wp_error( $result )
				/* translators: %s: the error message. */
				? sprintf( esc_html__( 'Stripe could not redact the session: %s', 'verification-expiry-for-hivepress' ), $result->get_error_message() )
				: esc_html__( 'Stripe session redaction requested.', 'verification-expiry-for-hivepress' ),
			[ 'actor' => $this->get_actor() ]
		);
	}

	/**
	 * The actor recorded on every line Stripe writes.
	 *
	 * @return array
	 */
	protected function get_actor() {
		return [
			'name'    => 'Stripe Identity',
			'user_id' => 0,
			'email'   => '',
		];
	}
}
