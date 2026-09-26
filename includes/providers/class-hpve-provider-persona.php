<?php
/**
 * Persona provider: hosted identity verification with a free tier.
 *
 * Verified against Persona's own API reference on 2026-09-07:
 * - POST https://api.withpersona.com/api/v1/inquiries, Bearer key, Persona-Version header,
 *   body { data: { attributes: { inquiry-template-id, reference-id, fields } } }.
 * - The hosted address comes back as meta.one-time-link, which Persona only includes when the
 *   template is set to auto-create one. That is why get_setup_notice() says so: without it the
 *   call succeeds and there is simply no URL, which is the confusing failure to prevent.
 * - Webhooks carry Persona-Signature as "t=<ts>,v1=<hex>" over "<ts>.<raw body>", HMAC-SHA256,
 *   the same scheme Stripe uses, so Hpve_Stripe_Signature verifies both.
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

use Verification_Expiry\Logic\Hpve_Stripe_Signature as Signature;
use Verification_Expiry\Logic\Hpve_Hosted_Mapper as Mapper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Persona.
 */
final class Hpve_Provider_Persona extends Hpve_Provider_Hosted {

	/**
	 * The API root.
	 */
	const ENDPOINT = 'https://api.withpersona.com/api/v1/';

	/**
	 * The API version this plugin was written against.
	 */
	const API_VERSION = '2025-12-08';

	/**
	 * Gets the stored name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'persona';
	}

	/**
	 * Gets the label. A product name, so it is not translated.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Persona';
	}

	/**
	 * The settings key prefix.
	 *
	 * @return string
	 */
	public function get_option_prefix() {
		return 'persona';
	}

	/**
	 * The inquiry template the checks run against.
	 *
	 * @return string
	 */
	public function get_template_id() {
		return trim( (string) $this->get_setting( 'template_id', '' ) );
	}

	/**
	 * Configured once the key, the webhook secret and a template are all present.
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
			return esc_html__( 'Add a Persona API key below. Create one in the Persona Dashboard under API Keys.', 'verification-expiry-for-hivepress' );
		}

		if ( '' === $this->get_template_id() ) {
			return esc_html__( 'Add the Persona inquiry template id below (it starts with itmpl_), and switch on "Create a one-time link" for that template so applicants have somewhere to go.', 'verification-expiry-for-hivepress' );
		}

		if ( '' === $this->get_webhook_secret() ) {
			return esc_html__( 'Add a Persona webhook pointing at the address above and paste its secret below.', 'verification-expiry-for-hivepress' );
		}

		return '';
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
			[ 'Persona-Version' => self::API_VERSION ],
			null,
			HPVE_VERSION
		);
	}

	/**
	 * Creates an inquiry and returns its id and hosted link.
	 *
	 * @param object $request Request.
	 * @return array|\WP_Error
	 */
	protected function create_session( $request ) {
		$user = get_userdata( (int) $request->get_user__id() );

		$fields = [];

		if ( $user ) {
			$fields['name-first'] = $user->first_name ? $user->first_name : $user->display_name;

			if ( $user->last_name ) {
				$fields['name-last'] = $user->last_name;
			}

			if ( $user->user_email ) {
				$fields['email-address'] = $user->user_email;
			}
		}

		$response = $this->get_http()->post(
			self::ENDPOINT . 'inquiries',
			[
				'data' => [
					'attributes' => [
						'inquiry-template-id' => $this->get_template_id(),
						'reference-id'        => 'hpve-' . (int) $request->get_id(),
						'fields'              => $fields,
					],
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$id   = isset( $response['data']['id'] ) ? (string) $response['data']['id'] : '';
		$link = '';

		foreach ( [ 'one-time-link', 'one-time-link-short' ] as $key ) {
			if ( ! empty( $response['meta'][ $key ] ) && is_string( $response['meta'][ $key ] ) ) {
				$link = $response['meta'][ $key ];
				break;
			}
		}

		if ( '' === $id ) {
			return new \WP_Error( 'hpve_no_inquiry', 'Persona did not return an inquiry id.' );
		}

		if ( '' === $link ) {
			return new \WP_Error( 'hpve_no_link', 'Persona returned no one-time link. Switch that on for the inquiry template.' );
		}

		return [
			'ref' => $id,
			'url' => $link,
		];
	}

	/**
	 * Reads the inquiry's current status.
	 *
	 * @param string $ref Inquiry id.
	 * @return string|\WP_Error
	 */
	protected function fetch_status( $ref ) {
		$response = $this->get_http()->get( self::ENDPOINT . 'inquiries/' . rawurlencode( $ref ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['data']['attributes']['status'] ) ) {
			return new \WP_Error( 'hpve_no_status', 'Persona returned no status.' );
		}

		return (string) $response['data']['attributes']['status'];
	}

	/**
	 * Verifies a Persona webhook.
	 *
	 * @param string $body Raw body.
	 * @param array  $headers Lower-cased headers.
	 * @return array|int
	 */
	protected function verify_webhook( $body, array $headers ) {
		$signature = isset( $headers['persona-signature'] ) ? (string) $headers['persona-signature'] : '';

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

		$event_id = isset( $data['data']['id'] ) ? (string) $data['data']['id'] : '';

		// The inquiry the event is about sits in the payload the event carries.
		$ref = '';

		foreach ( [ [ 'data', 'attributes', 'payload', 'data', 'id' ], [ 'data', 'attributes', 'payload', 'data', 'relationships', 'inquiry', 'data', 'id' ] ] as $path ) {
			$value = $data;

			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					$value = null;
					break;
				}

				$value = $value[ $key ];
			}

			if ( is_string( $value ) && 0 === strpos( $value, 'inq_' ) ) {
				$ref = $value;
				break;
			}
		}

		if ( '' === $event_id || '' === $ref ) {
			// Acknowledged and ignored: an event shape this plugin does not handle is not an error.
			return 200;
		}

		return [
			'event_id' => $event_id,
			'ref'      => $ref,
		];
	}

	/**
	 * Maps an inquiry status.
	 *
	 * @param string $status Status.
	 * @param int    $attempts Attempts used.
	 * @param int    $limit Attempts allowed.
	 * @param bool   $signoff Sign-off setting.
	 * @return array
	 */
	protected function map_status( $status, $attempts, $limit, $signoff ) {
		return Mapper::map_persona( $status, $attempts, $limit, $signoff );
	}
}
