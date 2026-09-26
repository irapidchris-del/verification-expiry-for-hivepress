<?php
/**
 * Stripe HTTP client for Identity verification sessions.
 *
 * Deliberately not WC_Stripe_API: that class logs every request body to the gateway's own
 * logger, its idempotency helper knows only charges and payment intents, its timeout is 70
 * seconds and it is not a public API (verified in woocommerce-gateway-stripe 10.9.0,
 * includes/class-wc-stripe-api.php, 2026-09-06). Only the gateway's key and API version are
 * borrowed, read at call time and never stored.
 *
 * The transport and the secret provider are injected so the logic tests can drive this class
 * with fixtures and assert that nothing it returns ever contains the key. The default transport
 * is the one wp_remote_request() call in this plugin outside the updater, and it is reached only
 * from scheduler jobs, never from a visitor's request (resources/security-standards.md, rule 5).
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Talks to api.stripe.com.
 */
final class Hpve_Stripe_Http {

	/**
	 * Stripe's base URL.
	 */
	const ENDPOINT = 'https://api.stripe.com/v1/';

	/**
	 * The API version used when the gateway's own constant is not available.
	 *
	 * The gateway pins '2026-03-25.dahlia' (class-wc-stripe-api.php:17); when the gateway is
	 * active its constant wins so both plugins speak the same version.
	 */
	const FALLBACK_API_VERSION = '2026-03-25.dahlia';

	/**
	 * Request timeout in seconds. Runs inside a background job, so short is right.
	 */
	const TIMEOUT = 15;

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
	 * Stripe-Version header value.
	 *
	 * @var string
	 */
	protected $api_version;

	/**
	 * Plugin version for the user agent.
	 *
	 * @var string
	 */
	protected $plugin_version;

	/**
	 * Class constructor.
	 *
	 * @param callable|null $transport Transport callable, defaults to wp_remote_request.
	 * @param callable|null $secret_provider Secret provider, defaults to the gateway's settings option.
	 * @param string|null   $api_version Stripe-Version header, defaults to the gateway's constant.
	 * @param string        $plugin_version Version string for the user agent.
	 */
	public function __construct( $transport = null, $secret_provider = null, $api_version = null, $plugin_version = '' ) {
		$this->transport       = is_callable( $transport ) ? $transport : [ $this, 'default_transport' ];
		$this->secret_provider = is_callable( $secret_provider ) ? $secret_provider : [ $this, 'default_secret' ];
		$this->plugin_version  = (string) $plugin_version;

		if ( null === $api_version ) {
			$api_version = class_exists( 'WC_Stripe_API' ) && defined( 'WC_Stripe_API::STRIPE_API_VERSION' ) ? constant( 'WC_Stripe_API::STRIPE_API_VERSION' ) : self::FALLBACK_API_VERSION;
		}

		$this->api_version = (string) $api_version;
	}

	/**
	 * Creates a VerificationSession.
	 *
	 * @param array  $params Form-encoded parameters (type, options, return_url, metadata...).
	 * @param string $idempotency_key Idempotency key.
	 * @return array|\WP_Error
	 */
	public function create_session( array $params, $idempotency_key ) {
		return $this->request( 'POST', 'identity/verification_sessions', $params, $idempotency_key );
	}

	/**
	 * Retrieves a VerificationSession.
	 *
	 * @param string $session_id Session id.
	 * @return array|\WP_Error
	 */
	public function retrieve_session( $session_id ) {
		return $this->request( 'GET', 'identity/verification_sessions/' . rawurlencode( (string) $session_id ), [], '' );
	}

	/**
	 * Redacts a VerificationSession.
	 *
	 * @param string $session_id Session id.
	 * @return array|\WP_Error
	 */
	public function redact_session( $session_id ) {
		return $this->request( 'POST', 'identity/verification_sessions/' . rawurlencode( (string) $session_id ) . '/redact', [], 'hpve-redact-' . (string) $session_id );
	}

	/**
	 * Builds the request without sending it. Public so the tests can inspect the shape.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Path under the endpoint.
	 * @param array  $params Parameters.
	 * @param string $idempotency_key Idempotency key, or an empty string.
	 * @param string $secret The API key.
	 * @return array [ url, args ]
	 */
	public function build_request( $method, $path, array $params, $idempotency_key, $secret ) {
		$headers = [
			'Authorization'  => 'Bearer ' . $secret,
			'Stripe-Version' => $this->api_version,
			'Content-Type'   => 'application/x-www-form-urlencoded',
		];

		if ( '' !== (string) $idempotency_key ) {
			$headers['Idempotency-Key'] = (string) $idempotency_key;
		}

		$url  = self::ENDPOINT . ltrim( (string) $path, '/' );
		$args = [
			'method'     => strtoupper( (string) $method ),
			'timeout'    => self::TIMEOUT,
			'headers'    => $headers,
			'user-agent' => 'verification-expiry-for-hivepress/' . $this->plugin_version,
		];

		if ( 'GET' === $args['method'] ) {
			if ( $params ) {
				$url .= '?' . http_build_query( $params, '', '&' );
			}
		} else {
			$args['body'] = http_build_query( $params, '', '&' );
		}

		return [ $url, $args ];
	}

	/**
	 * Sends a request and decodes the answer.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Path.
	 * @param array  $params Parameters.
	 * @param string $idempotency_key Idempotency key.
	 * @return array|\WP_Error
	 */
	protected function request( $method, $path, array $params, $idempotency_key ) {
		$secret = trim( (string) call_user_func( $this->secret_provider ) );

		if ( '' === $secret ) {
			return new \WP_Error( 'hpve_stripe_no_key', 'No Stripe secret key is available.' );
		}

		list( $url, $args ) = $this->build_request( $method, $path, $params, $idempotency_key, $secret );

		$response = call_user_func( $this->transport, $url, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'hpve_stripe_transport', $this->mask( (string) $response->get_error_message(), $secret ) );
		}

		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;
		$body = isset( $response['body'] ) ? (string) $response['body'] : '';

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'hpve_stripe_invalid_json', 'Stripe answered with something that is not JSON (HTTP ' . $code . ').' );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : 'Stripe returned HTTP ' . $code . '.';
			$type    = isset( $decoded['error']['type'] ) ? (string) $decoded['error']['type'] : '';
			$ecode   = isset( $decoded['error']['code'] ) ? (string) $decoded['error']['code'] : '';

			return new \WP_Error(
				'hpve_stripe_api',
				$this->mask( $message, $secret ),
				[
					'status' => $code,
					'type'   => $this->mask( $type, $secret ),
					'code'   => $this->mask( $ecode, $secret ),
				]
			);
		}

		return $decoded;
	}

	/**
	 * Strips the key from any text that might be logged or shown.
	 *
	 * @param string $text Text.
	 * @param string $secret The key.
	 * @return string
	 */
	protected function mask( $text, $secret ) {
		if ( '' === $secret ) {
			return $text;
		}

		return str_replace( $secret, '[key]', $text );
	}

	/**
	 * The default transport: WordPress's HTTP API, normalised to code and body.
	 *
	 * @param string $url URL.
	 * @param array  $args Arguments.
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
	 * The default secret provider: the WooCommerce Stripe gateway's key for its current mode.
	 *
	 * Read from the option the gateway itself uses (class-wc-stripe-helper.php:16), choosing the
	 * test or live key the way WC_Stripe_Mode::is_test() does (class-wc-stripe-mode.php:25-28).
	 *
	 * @return string
	 */
	public function default_secret() {
		$settings = get_option( 'woocommerce_stripe_settings', [] );

		if ( ! is_array( $settings ) ) {
			return '';
		}

		$test = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
		$key  = $test ? 'test_secret_key' : 'secret_key';

		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * Whether the gateway holds a key for its current mode, and which mode that is.
	 *
	 * Reports presence and the last four characters only; the key itself never leaves this class.
	 *
	 * @return array [ 'present' => bool, 'test' => bool, 'last4' => string ]
	 */
	public static function describe_gateway_key() {
		$settings = get_option( 'woocommerce_stripe_settings', [] );

		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$test = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
		$key  = $test ? 'test_secret_key' : 'secret_key';
		$val  = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';

		return [
			'present' => '' !== $val,
			'test'    => $test,
			'last4'   => '' !== $val ? substr( $val, -4 ) : '',
		];
	}
}
