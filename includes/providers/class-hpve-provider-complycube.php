<?php
/**
 * ComplyCube provider: UK-based hosted identity verification.
 *
 * Verified against ComplyCube's own API reference on 2026-09-07:
 * - Base https://api.complycube.com/v1.
 * - The Authorization header is the BARE API key, not "Bearer <key>". The key itself carries the
 *   environment as a prefix, "test_" or "live_", so there is no separate sandbox toggle to set and
 *   no way for the two to be out of step.
 * - A hosted run is two calls: POST /clients for the person, then POST /flow/sessions with
 *   clientId, workflowTemplateId, successUrl and cancelUrl. The address to send them to comes back
 *   as redirectUrl.
 * - Webhooks carry ComplyCube-Signature: a bare HMAC-SHA256 hex digest of the raw body, with no
 *   timestamp, so Hpve_Plain_Signature verifies it and the event-id dedupe is what stops replays.
 * - A workflow outcome is "clear", "attention" or "rejected".
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

use Verification_Expiry\Logic\Hpve_Plain_Signature as Signature;
use Verification_Expiry\Logic\Hpve_Hosted_Mapper as Mapper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * ComplyCube.
 */
final class Hpve_Provider_Complycube extends Hpve_Provider_Hosted {

	/**
	 * The API root.
	 */
	const ENDPOINT = 'https://api.complycube.com/v1/';

	/**
	 * Gets the stored name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'complycube';
	}

	/**
	 * Gets the label. A product name, so it is not translated.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'ComplyCube';
	}

	/**
	 * The settings key prefix.
	 *
	 * @return string
	 */
	public function get_option_prefix() {
		return 'complycube';
	}

	/**
	 * The workflow template a session runs.
	 *
	 * @return string
	 */
	public function get_template_id() {
		return trim( (string) $this->get_setting( 'template_id', '' ) );
	}

	/**
	 * Configured once the key, the webhook secret and a workflow are all present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return parent::is_configured() && '' !== $this->get_template_id();
	}

	/**
	 * One sentence for the settings tab when not configured.
	 *
	 * @return string
	 */
	public function get_setup_notice() {
		if ( '' === $this->get_api_key() ) {
			return esc_html__( 'Add a ComplyCube API key below. A key starting with test_ runs in their sandbox and one starting with live_ runs for real, so the key alone decides the mode.', 'verification-expiry-for-hivepress' );
		}

		if ( '' === $this->get_template_id() ) {
			return esc_html__( 'Add the ComplyCube workflow template id below, from Workflows in their dashboard.', 'verification-expiry-for-hivepress' );
		}

		if ( '' === $this->get_webhook_secret() ) {
			return esc_html__( 'Add a ComplyCube webhook pointing at the address above and paste its secret below.', 'verification-expiry-for-hivepress' );
		}

		return '';
	}

	/**
	 * Whether the key puts this site in ComplyCube's sandbox.
	 *
	 * @return bool
	 */
	public function is_sandbox() {
		return 0 === strpos( $this->get_api_key(), 'test_' );
	}

	/**
	 * A status line for the settings tab, with the key masked.
	 *
	 * @return string
	 */
	public function describe_key() {
		$key = $this->get_api_key();

		if ( '' === $key ) {
			return esc_html__( 'ComplyCube: no key set.', 'verification-expiry-for-hivepress' );
		}

		return $this->is_sandbox()
			? esc_html__( 'ComplyCube: sandbox key (test_), so no check costs anything and no result is real.', 'verification-expiry-for-hivepress' )
			: esc_html__( 'ComplyCube: live key, so every completed check is charged.', 'verification-expiry-for-hivepress' );
	}

	/**
	 * Builds the HTTP client.
	 *
	 * @return Hpve_Hosted_Http
	 */
	protected function build_http() {
		return new Hpve_Hosted_Http(
			null,
			function () {
				return $this->get_api_key();
			},
			[],
			// The bare key, with no scheme in front of it. See the class docblock.
			function ( $secret ) {
				return $secret;
			},
			HPVE_VERSION
		);
	}

	/**
	 * Creates a client and a flow session, and returns the session id and its URL.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	protected function create_session( $request ) {
		$client_id = $this->get_client_id( $request );

		if ( is_wp_error( $client_id ) ) {
			return $client_id;
		}

		$return_url = $this->get_return_url( (int) $request->get_id() );

		$response = $this->get_http()->post(
			self::ENDPOINT . 'flow/sessions',
			[
				'clientId'           => $client_id,
				'workflowTemplateId' => $this->get_template_id(),
				'successUrl'         => $return_url,
				'cancelUrl'          => $return_url,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$url = isset( $response['redirectUrl'] ) ? (string) $response['redirectUrl'] : '';

		// The session's own id is what the webhook will name; fall back to the client id so a
		// result can still be matched if the shape ever changes.
		$ref = isset( $response['id'] ) ? (string) $response['id'] : $client_id;

		if ( '' === $url ) {
			return new \WP_Error( 'hpve_no_link', 'ComplyCube returned no redirect URL.' );
		}

		// Kept so sync() can read the workflow result, which hangs off the client rather than the
		// session once the applicant has finished.
		update_post_meta( (int) $request->get_id(), 'hp_hpve_cc_client', sanitize_text_field( $client_id ) );

		return [
			'ref' => $ref,
			'url' => $url,
		];
	}

	/**
	 * Creates the ComplyCube client for an applicant, reusing one if this request already has it.
	 *
	 * @param object $request Request.
	 * @return string|\WP_Error
	 */
	protected function get_client_id( $request ) {
		$existing = (string) get_post_meta( (int) $request->get_id(), 'hp_hpve_cc_client', true );

		if ( '' !== $existing ) {
			return $existing;
		}

		$user = get_userdata( (int) $request->get_user__id() );

		if ( ! $user ) {
			return new \WP_Error( 'hpve_no_user', 'The applicant no longer exists.' );
		}

		$first = $user->first_name ? $user->first_name : $user->display_name;
		$last  = $user->last_name ? $user->last_name : $user->display_name;

		$response = $this->get_http()->post(
			self::ENDPOINT . 'clients',
			[
				'type'          => 'person',
				'email'         => $user->user_email,
				'personDetails' => [
					'firstName' => $first,
					'lastName'  => $last,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['id'] ) ) {
			return new \WP_Error( 'hpve_no_client', 'ComplyCube did not return a client id.' );
		}

		return (string) $response['id'];
	}

	/**
	 * Reads the latest workflow outcome for this applicant.
	 *
	 * @param string $ref Session id.
	 * @return string|\WP_Error
	 */
	protected function fetch_status( $ref ) {
		$response = $this->get_http()->get( self::ENDPOINT . 'flow/sessions/' . rawurlencode( $ref ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		foreach ( [ 'outcome', 'status' ] as $key ) {
			if ( ! empty( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return '';
	}

	/**
	 * Verifies a ComplyCube webhook.
	 *
	 * @param string $body Raw body.
	 * @param array  $headers Lower-cased headers.
	 * @return array|int
	 */
	protected function verify_webhook( $body, array $headers ) {
		$signature = isset( $headers['complycube-signature'] ) ? (string) $headers['complycube-signature'] : '';

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

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return 400;
		}

		$event_id = isset( $data['id'] ) ? (string) $data['id'] : '';
		$ref      = '';

		foreach ( [ [ 'payload', 'sessionId' ], [ 'payload', 'id' ], [ 'payload', 'clientId' ] ] as $path ) {
			$value = $data;

			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					$value = null;
					break;
				}

				$value = $value[ $key ];
			}

			if ( is_string( $value ) && '' !== $value ) {
				$ref = $value;
				break;
			}
		}

		if ( '' === $event_id || '' === $ref ) {
			return 200;
		}

		return [
			'event_id' => $event_id,
			'ref'      => $ref,
		];
	}

	/**
	 * Maps a workflow outcome.
	 *
	 * @param string $status Outcome.
	 * @param int    $attempts Attempts used.
	 * @param int    $limit Attempts allowed.
	 * @param bool   $signoff Sign-off setting.
	 * @return array
	 */
	protected function map_status( $status, $attempts, $limit, $signoff ) {
		return Mapper::map_complycube( $status, $attempts, $limit, $signoff );
	}
}
