<?php
/**
 * JSON HTTP client for the hosted identity providers.
 *
 * Persona and ComplyCube both speak JSON in and JSON out, which is why they share a client where
 * Stripe (form-encoded, its own idempotency header, the gateway's key) has its own. The two
 * differ only in how they carry the credential, so the authorization header is passed in whole
 * rather than assembled here: Persona wants "Bearer <key>" and ComplyCube wants the bare key
 * (verified against each service's API reference, 2026-09-07). Assuming Bearer for both would
 * have made every ComplyCube call a 401.
 *
 * The transport and the secret provider are injected so the tests drive this from fixtures with
 * no network. The default transport is a wp_remote_request() call reached only from scheduler
 * jobs, never from a visitor's request (resources/security-standards.md, rule 5).
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Talks JSON to a hosted identity provider.
 */
final class Hpve_Hosted_Http {

	/**
	 * Request timeout in seconds. Runs inside a background job, so short is right.
	 */
	const TIMEOUT = 20;

	/**
	 * Transport callable: ( string $url, array $args ) => [ 'code' => int, 'body' => string ] | WP_Error.
	 *
	 * @var callable
	 */
	protected $transport;

	/**
	 * Secret provider callable: () => string. Called at request time, never earlier.
	 *
	 * @var callable
	 */
	protected $secret_provider;

	/**
	 * Extra headers every request carries, e.g. an API version.
	 *
	 * @var array
	 */
	protected $headers;

	/**
	 * Builds the Authorization header value from the secret.
	 *
	 * @var callable
	 */
	protected $auth_builder;

	/**
	 * Plugin version, for the user agent.
	 *
	 * @var string
	 */
	protected $plugin_version;

	/**
	 * Class constructor.
	 *
	 * @param callable|null $transport Transport callable.
	 * @param callable|null $secret_provider Secret provider.
	 * @param array         $headers Extra headers.
	 * @param callable|null $auth_builder Builds the Authorization value; defaults to "Bearer <key>".
	 * @param string        $plugin_version Version for the user agent.
	 */
	public function __construct( $transport = null, $secret_provider = null, array $headers = [], $auth_builder = null, $plugin_version = '' ) {
		$this->transport       = is_callable( $transport ) ? $transport : [ $this, 'default_transport' ];
		$this->secret_provider = is_callable( $secret_provider ) ? $secret_provider : [ $this, 'no_secret' ];
		$this->headers         = $headers;
		$this->plugin_version  = (string) $plugin_version;

		$this->auth_builder = is_callable( $auth_builder ) ? $auth_builder : function ( $secret ) {
			return 'Bearer ' . $secret;
		};
	}

	/**
	 * Performs a GET.
	 *
	 * @param string $url Full URL.
	 * @return array|\WP_Error
	 */
	public function get( $url ) {
		return $this->request( 'GET', $url, null );
	}

	/**
	 * Performs a POST with a JSON body.
	 *
	 * @param string $url Full URL.
	 * @param array  $body Body to encode.
	 * @return array|\WP_Error
	 */
	public function post( $url, array $body ) {
		return $this->request( 'POST', $url, $body );
	}

	/**
	 * Performs one request and decodes the JSON.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url Full URL.
	 * @param array|null $body Body, or null.
	 * @return array|\WP_Error
	 */
	protected function request( $method, $url, $body ) {
		$secret = trim( (string) call_user_func( $this->secret_provider ) );

		if ( '' === $secret ) {
			return new \WP_Error( 'hpve_no_key', 'No API key is set.' );
		}

		$args = [
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array_merge(
				$this->headers,
				[
					'Authorization' => (string) call_user_func( $this->auth_builder, $secret ),
					'Accept'        => 'application/json',
					'User-Agent'    => 'VerificationExpiryForHivePress/' . $this->plugin_version . '; ' . home_url(),
				]
			),
		];

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = call_user_func( $this->transport, $url, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'hpve_http', $this->mask( $response->get_error_message(), $secret ) );
		}

		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;
		$raw  = isset( $response['body'] ) ? (string) $response['body'] : '';
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			$data = [];
		}

		if ( $code < 200 || $code > 299 ) {
			return new \WP_Error( 'hpve_http_' . $code, $this->mask( $this->describe_error( $data, $code ), $secret ) );
		}

		return $data;
	}

	/**
	 * Pulls a usable sentence out of an error body.
	 *
	 * Both services nest their message differently, and a raw body dump would put whatever the
	 * service echoed back (which can include what the applicant typed) into the log.
	 *
	 * @param array $data Decoded body.
	 * @param int   $code HTTP status.
	 * @return string
	 */
	protected function describe_error( array $data, $code ) {
		foreach ( [ [ 'message' ], [ 'error', 'message' ], [ 'errors', 0, 'title' ], [ 'errors', 0, 'detail' ] ] as $path ) {
			$value = $data;

			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					$value = null;
					break;
				}

				$value = $value[ $key ];
			}

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return 'HTTP ' . (int) $code;
	}

	/**
	 * Removes a credential from text that is about to be stored or shown.
	 *
	 * @param string $text Text.
	 * @param string $secret Credential.
	 * @return string
	 */
	protected function mask( $text, $secret ) {
		$text = (string) $text;

		if ( '' === (string) $secret ) {
			return $text;
		}

		return str_replace( $secret, '***', $text );
	}

	/**
	 * The default transport.
	 *
	 * @param string $url URL.
	 * @param array  $args Request arguments.
	 * @return array|\WP_Error
	 */
	public function default_transport( $url, array $args ) {
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return [
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		];
	}

	/**
	 * The fallback secret provider, for an instance built without one.
	 *
	 * @return string
	 */
	public function no_secret() {
		return '';
	}
}
