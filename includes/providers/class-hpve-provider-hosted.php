<?php
/**
 * Shared behaviour for the hosted identity providers: Persona and ComplyCube.
 *
 * The flow matches Stripe Identity's, because the services work the same way: start() queues a
 * job, the job creates a session and stashes its URL for the applicant's browser to collect once,
 * a signed webhook says the result is ready, and a second job re-fetches the result and applies
 * it. Every call runs in a scheduler job; nothing here calls out during a visitor's request
 * (resources/security-standards.md, rule 5).
 *
 * **Why this is a base class and Stripe is not folded into it.** Stripe's provider was written
 * first, ships in 2.0.0 and is covered by 214 runtime assertions that pass against the real
 * request component. Refactoring it into this base to remove the duplication would put that
 * coverage at risk for a purely internal tidy, so the duplication stays and is managed by the
 * standing rule that a fix is never finished until its siblings have been swept: a change to this
 * flow is a change to Stripe's copy as well, and to any provider added after it.
 *
 * What is stored: the session or inquiry id, its last status, and the last error code. Never the
 * hosted URL, which is single use and is handed to the browser through a transient instead.
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

use Verification_Expiry\Logic\Hpve_Hosted_Mapper as Mapper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A hosted identity service.
 */
abstract class Hpve_Provider_Hosted implements Hpve_Provider_Interface {

	/**
	 * How long a fetched session URL waits for the applicant's browser to collect it.
	 */
	const URL_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Webhook event ids are remembered for this long.
	 *
	 * Longer than Stripe's window would need to be, because ComplyCube's signature carries no
	 * timestamp: without one there is no replay tolerance to lean on, and this dedupe is the only
	 * thing standing between a captured webhook and a repeated decision.
	 */
	const EVENT_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * The HTTP client. Injected by the tests; built on demand otherwise.
	 *
	 * @var Hpve_Hosted_Http|null
	 */
	protected $http = null;

	/**
	 * Class constructor.
	 *
	 * @param Hpve_Hosted_Http|null $http HTTP client.
	 */
	public function __construct( $http = null ) {
		if ( $http instanceof Hpve_Hosted_Http ) {
			$this->http = $http;
		}
	}

	/**
	 * Gets the HTTP client.
	 *
	 * @return Hpve_Hosted_Http
	 */
	public function get_http() {
		if ( ! $this->http ) {
			$this->http = $this->build_http();
		}

		return $this->http;
	}

	/*
	--------------------------------------------------------------------------
	What each service fills in.
	--------------------------------------------------------------------------
	*/

	/**
	 * Builds this service's HTTP client.
	 *
	 * @return Hpve_Hosted_Http
	 */
	abstract protected function build_http();

	/**
	 * The settings key prefix for this provider's own options, e.g. "persona".
	 *
	 * @return string
	 */
	abstract public function get_option_prefix();

	/**
	 * Creates a session at the service.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error [ 'ref' => string, 'url' => string ] or an error.
	 */
	abstract protected function create_session( $request );

	/**
	 * Reads the current result for a stored reference.
	 *
	 * @param string $ref Provider reference.
	 * @return string|\WP_Error The service's own status or outcome value.
	 */
	abstract protected function fetch_status( $ref );

	/**
	 * Verifies a webhook and returns what it is about.
	 *
	 * @param string $body Raw body.
	 * @param array  $headers Lower-cased headers.
	 * @return array|int [ 'event_id' => string, 'ref' => string ] or an HTTP status to answer with.
	 */
	abstract protected function verify_webhook( $body, array $headers );

	/**
	 * Maps the service's status to an action.
	 *
	 * @param string $status Service status.
	 * @param int    $attempts Attempts used.
	 * @param int    $limit Attempts allowed.
	 * @param bool   $signoff Whether a pass waits for an admin.
	 * @return array
	 */
	abstract protected function map_status( $status, $attempts, $limit, $signoff );

	/*
	--------------------------------------------------------------------------
	Settings helpers.
	--------------------------------------------------------------------------
	*/

	/**
	 * Reads one of this provider's own settings.
	 *
	 * @param string $name Name after the provider prefix.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function get_setting( $name, $fallback = null ) {
		return hivepress()->hpve_request->get_option( $this->get_option_prefix() . '_' . $name, $fallback );
	}

	/**
	 * The API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		return trim( (string) $this->get_setting( 'api_key', '' ) );
	}

	/**
	 * The webhook signing secret.
	 *
	 * @return string
	 */
	public function get_webhook_secret() {
		return trim( (string) $this->get_setting( 'webhook_secret', '' ) );
	}

	/**
	 * Configured once both the key and the webhook secret are present.
	 *
	 * The secret is required rather than optional on purpose: without it the result can only ever
	 * arrive on the return leg, which the applicant can simply close, leaving every request stuck
	 * pending with no way for the site to learn what happened.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->get_api_key() && '' !== $this->get_webhook_secret();
	}

	/**
	 * Whether documents are still collected alongside the automated check.
	 *
	 * @return bool
	 */
	public function supports_documents() {
		return (bool) hivepress()->hpve_request->get_option( 'doc_collect_with_provider', false );
	}

	/**
	 * The attempt limit, shared with Stripe's so an owner sets it once.
	 *
	 * @return int
	 */
	protected function get_attempt_limit() {
		return hivepress()->hpve_request->get_number_option( 'stripe_attempt_limit', 3 );
	}

	/**
	 * Whether a pass waits for an admin.
	 *
	 * @return bool
	 */
	protected function requires_signoff() {
		return (bool) $this->get_setting( 'admin_signoff', false );
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
				'message' => sprintf(
					/* translators: 1: the service's name, 2: attempt number. */
					esc_html__( '%1$s check started (attempt %2$d).', 'verification-expiry-for-hivepress' ),
					$this->get_label(),
					$attempt
				),
			]
		);

		hivepress()->scheduler->add_action( 'hpve_provider_start', [ (int) $request_id, $this->get_name(), $attempt ] );

		return true;
	}

	/**
	 * Background job: creates the session and stores its URL for the poll.
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

		$session = $this->create_session( $request );

		if ( is_wp_error( $session ) ) {
			hivepress()->hpve_request->apply_event(
				$request,
				'provider_result',
				[
					'actor'   => $this->get_actor(),
					'message' => sprintf(
						/* translators: 1: the service's name, 2: the error message. */
						esc_html__( '%1$s could not start the check: %2$s', 'verification-expiry-for-hivepress' ),
						$this->get_label(),
						$session->get_error_message()
					),
				]
			);

			return;
		}

		if ( empty( $session['ref'] ) || empty( $session['url'] ) ) {
			return;
		}

		$request->fill(
			[
				'provider_ref'    => sanitize_text_field( (string) $session['ref'] ),
				'provider_status' => 'created',
			]
		)->save( [ 'provider_ref', 'provider_status' ] );

		set_transient( 'hpve_session_url_' . (int) $request_id, esc_url_raw( $session['url'] ), self::URL_TTL );

		hivepress()->hpve_request->log(
			$request_id,
			'provider_started',
			sprintf(
				/* translators: %s: the service's name. */
				esc_html__( '%s session ready for the applicant.', 'verification-expiry-for-hivepress' ),
				$this->get_label()
			),
			[ 'actor' => $this->get_actor() ]
		);
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
		hivepress()->scheduler->add_action( 'hpve_provider_sync', [ absint( $request_id ), $this->get_name(), 'return' ] );

		return true;
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
		$event = $this->verify_webhook( (string) $body, $headers );

		if ( is_int( $event ) ) {
			return $event;
		}

		$dedupe = 'hpve_evt_' . md5( $this->get_name() . '|' . $event['event_id'] );

		if ( get_transient( $dedupe ) ) {
			return 200;
		}

		set_transient( $dedupe, 1, self::EVENT_TTL );

		$request = $this->find_request_by_ref( $event['ref'] );

		if ( ! $request ) {
			// Acknowledged: an event for something this site does not hold is not the service's problem.
			return 200;
		}

		hivepress()->scheduler->add_action( 'hpve_provider_sync', [ (int) $request->get_id(), $this->get_name(), (string) $event['event_id'] ] );

		return 200;
	}

	/**
	 * Finds the request holding a provider reference.
	 *
	 * @param string $ref Provider reference.
	 * @return object|null
	 */
	protected function find_request_by_ref( $ref ) {
		$ref = trim( (string) $ref );

		if ( '' === $ref ) {
			return null;
		}

		/*
		 * The model query rather than a raw meta_query: provider_ref is a declared field, so
		 * HivePress builds the meta comparison itself and the lookup stays inside the framework's
		 * own caching (resources/hivepress-data.md). A hand-written meta_query here would also
		 * have tripped the slow-query sniff for no benefit.
		 *
		 * Only a pending request is a candidate. A webhook that arrives after an admin has already
		 * decided by hand must find nothing, so a late result cannot reopen a settled request.
		 */
		$request = \HivePress\Models\Hpve_Request::query()->filter(
			[
				'provider'     => $this->get_name(),
				'provider_ref' => $ref,
				'status'       => 'pending',
			]
		)->get_first();

		return $request ? $request : null;
	}

	/**
	 * Background job: re-reads the result and applies it.
	 *
	 * The result is always re-fetched rather than read from the webhook body, so a replayed old
	 * event cannot roll a decision back, and a request that is no longer pending is left alone.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $event_id Event id, 'return' or 'manual'.
	 * @return void
	 */
	public function sync( $request_id, $event_id ) {
		$request = hivepress()->hpve_request->get_request( $request_id );

		if ( ! $request || 'pending' !== (string) $request->get_status() || $this->get_name() !== (string) $request->get_provider() ) {
			return;
		}

		$ref = (string) $request->get_provider_ref();

		if ( '' === $ref ) {
			return;
		}

		$status = $this->fetch_status( $ref );

		if ( is_wp_error( $status ) ) {
			hivepress()->hpve_request->log(
				$request_id,
				'provider_result',
				sprintf(
					/* translators: 1: the service's name, 2: the error message. */
					esc_html__( '%1$s could not be reached: %2$s', 'verification-expiry-for-hivepress' ),
					$this->get_label(),
					$status->get_error_message()
				),
				[ 'actor' => $this->get_actor() ]
			);

			return;
		}

		$request->fill( [ 'provider_status' => sanitize_key( (string) $status ) ] )->save( [ 'provider_status' ] );

		$mapped = $this->map_status( (string) $status, (int) $request->get_provider_attempts(), $this->get_attempt_limit(), $this->requires_signoff() );

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
		$text      = $this->get_applicant_message( $mapped['note'] );

		switch ( $mapped['action'] ) {
			case 'approve':
				$component->apply_event(
					$request,
					'approve',
					[
						'actor'   => $actor,
						'message' => sprintf(
							/* translators: %s: the service's name. */
							esc_html__( 'Approved automatically: %s verified the applicant.', 'verification-expiry-for-hivepress' ),
							$this->get_label()
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
						'message' => 'review' === $mapped['note']
							? sprintf(
								/* translators: %s: the service's name. */
								esc_html__( '%s finished the check and asked for a person to look at it. Approve or reject this one by hand.', 'verification-expiry-for-hivepress' ),
								$this->get_label()
							)
							: sprintf(
								/* translators: %s: the service's name. */
								esc_html__( '%s verified the applicant; waiting for an admin to approve.', 'verification-expiry-for-hivepress' ),
								$this->get_label()
							),
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

			case 'pending':
			default:
				$component->apply_event(
					$request,
					'provider_result',
					[
						'actor'   => $actor,
						'message' => sprintf(
							/* translators: %s: the service's name. */
							esc_html__( '%s has not finished the check yet.', 'verification-expiry-for-hivepress' ),
							$this->get_label()
						),
					]
				);
				break;
		}
	}

	/**
	 * The sentence the applicant reads for a given outcome.
	 *
	 * @param string $note Note key.
	 * @return string
	 */
	protected function get_applicant_message( $note ) {
		switch ( $note ) {
			case 'expired':
				return esc_html__( 'The check expired before it was finished. Start it again to continue.', 'verification-expiry-for-hivepress' );

			case 'declined':
				return esc_html__( 'The identity check did not pass. You can try again, or send your documents to us instead.', 'verification-expiry-for-hivepress' );

			case 'unfinished':
				return esc_html__( 'You have not finished the check. Start it again to continue.', 'verification-expiry-for-hivepress' );

			default:
				return esc_html__( 'The check could not be completed. Please try again, or send your documents to us.', 'verification-expiry-for-hivepress' );
		}
	}

	/**
	 * The return address the service sends the applicant back to.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	protected function get_return_url( $request_id ) {
		return add_query_arg( 'request_id', (int) $request_id, hivepress()->router->get_url( 'hpve_verification_return_page' ) );
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
