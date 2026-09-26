<?php
/**
 * HTTP client for the free public business registers: Companies House, HMRC and VIES.
 *
 * Separate from Hpve_Stripe_Http because none of these three speak Stripe's dialect: two of them
 * take no credential at all, they answer JSON rather than form-encoded parameters, and only
 * Companies House uses HTTP Basic. Sharing one client would have meant a flag for every
 * difference.
 *
 * The transport and the secret provider are injected so the logic tests drive this class from
 * fixtures with no network, and so a test can assert that a returned error never carries the API
 * key. The default transport is a wp_remote_request() call, reached only from scheduler jobs and
 * never from a visitor's request (resources/security-standards.md, rule 5).
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Talks to the free public registers.
 */
final class Hpve_Registry_Http {

	/**
	 * Companies House, the public read API. Basic auth, the API key as the username.
	 */
	const COMPANIES_HOUSE = 'https://api.company-information.service.gov.uk/';

	/**
	 * HMRC's "check a UK VAT number" service. Open access: no key, no token.
	 */
	const HMRC_VAT = 'https://api.service.hmrc.gov.uk/organisations/vat/check-vat-number/lookup/';

	/**
	 * The European Commission's VIES service, for the non-GB member-state numbers.
	 */
	const VIES_VAT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/';

	/**
	 * Request timeout in seconds. Every call runs inside a background job, so short is right.
	 */
	const TIMEOUT = 15;

	/**
	 * Transport callable: ( string $url, array $args ) => [ 'code' => int, 'body' => string ] | WP_Error.
	 *
	 * @var callable
	 */
	protected $transport;

	/**
	 * Companies House key provider: () => string. Called at request time, never earlier.
	 *
	 * @var callable
	 */
	protected $key_provider;

	/**
	 * Plugin version, for the user agent.
	 *
	 * @var string
	 */
	protected $plugin_version;

	/**
	 * Class constructor.
	 *
	 * @param callable|null $transport Transport callable, defaults to wp_remote_request.
	 * @param callable|null $key_provider Companies House key provider, defaults to the setting.
	 * @param string        $plugin_version Version string for the user agent.
	 */
	public function __construct( $transport = null, $key_provider = null, $plugin_version = '' ) {
		$this->transport      = is_callable( $transport ) ? $transport : [ $this, 'default_transport' ];
		$this->key_provider   = is_callable( $key_provider ) ? $key_provider : [ $this, 'default_key' ];
		$this->plugin_version = (string) $plugin_version;
	}

	/*
	--------------------------------------------------------------------------
	Companies House.
	--------------------------------------------------------------------------
	*/

	/**
	 * Looks up one company by its registered number.
	 *
	 * @param string $number Company number, already normalised.
	 * @return array|\WP_Error Decoded profile, or an error.
	 */
	public function get_company( $number ) {
		$key = (string) call_user_func( $this->key_provider );

		if ( '' === $key ) {
			return new \WP_Error( 'hpve_no_key', 'No Companies House API key is set.' );
		}

		return $this->request(
			self::COMPANIES_HOUSE . 'company/' . rawurlencode( $number ),
			[
				// Companies House takes the key as the Basic username with an empty password.
				// base64_encode() here is the HTTP Basic scheme (RFC 7617) building a credential,
				// not obfuscated code, which is what the ignored sniff is looking for.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
			],
			$key
		);
	}

	/*
	--------------------------------------------------------------------------
	VAT.
	--------------------------------------------------------------------------
	*/

	/**
	 * Looks up a UK VAT registration number at HMRC.
	 *
	 * @param string $vrn Nine or twelve digits, already normalised.
	 * @return array|\WP_Error
	 */
	public function get_uk_vat( $vrn ) {
		return $this->request(
			self::HMRC_VAT . rawurlencode( $vrn ),
			[
				// HMRC versions its APIs through Accept rather than the path.
				'Accept' => 'application/vnd.hmrc.2.0+json',
			],
			''
		);
	}

	/**
	 * Looks up a member-state VAT number at VIES.
	 *
	 * @param string $country Two-letter member-state code.
	 * @param string $number The number without the country prefix.
	 * @return array|\WP_Error
	 */
	public function get_eu_vat( $country, $number ) {
		return $this->request(
			self::VIES_VAT . rawurlencode( $country ) . '/vat/' . rawurlencode( $number ),
			[ 'Accept' => 'application/json' ],
			''
		);
	}

	/*
	--------------------------------------------------------------------------
	Transport.
	--------------------------------------------------------------------------
	*/

	/**
	 * Performs one GET and decodes the JSON.
	 *
	 * A 404 is returned as data rather than as an error, because "no such company" and "that VAT
	 * number is not registered" are both real answers the mapper has to act on, not failures to
	 * retry. Anything else non-2xx is an error.
	 *
	 * @param string $url Full URL.
	 * @param array  $headers Request headers.
	 * @param string $secret A credential to keep out of any error message, or an empty string.
	 * @return array|\WP_Error
	 */
	protected function request( $url, array $headers, $secret ) {
		$headers['User-Agent'] = 'VerificationExpiryForHivePress/' . $this->plugin_version . '; ' . home_url();

		$response = call_user_func(
			$this->transport,
			$url,
			[
				'method'      => 'GET',
				'timeout'     => self::TIMEOUT,
				'headers'     => $headers,
				'redirection' => 0,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'hpve_http', $this->mask( $response->get_error_message(), $secret ) );
		}

		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;
		$body = isset( $response['body'] ) ? (string) $response['body'] : '';
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$data = [];
		}

		// A "not found" is an answer, and the mapper turns it into a message for the applicant.
		if ( 404 === $code ) {
			return [ 'hpve_not_found' => true ];
		}

		if ( $code < 200 || $code > 299 ) {
			$message = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;

			return new \WP_Error( 'hpve_http_' . $code, $this->mask( $message, $secret ) );
		}

		return $data;
	}

	/**
	 * Removes a credential from text that is about to be stored or shown.
	 *
	 * @param string $text Text.
	 * @param string $secret Credential, or an empty string.
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
	 * The default Companies House key, read from the setting at call time.
	 *
	 * @return string
	 */
	public function default_key() {
		return trim( (string) hivepress()->hpve_request->get_option( 'ch_api_key', '' ) );
	}
}
