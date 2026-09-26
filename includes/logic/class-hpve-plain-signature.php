<?php
/**
 * Plain HMAC webhook signature verifier, for services that sign the body and nothing else.
 *
 * ComplyCube's ComplyCube-Signature is a bare HMAC-SHA256 hex digest of the raw request body
 * keyed on the webhook secret, with no timestamp in the header
 * (docs.complycube.com/documentation/integration-resources/webhooks, read 2026-09-07). That is
 * why this is a separate class from Hpve_Stripe_Signature rather than a flag on it: without a
 * timestamp there is no tolerance to apply and no replay window to enforce, so the two verifiers
 * genuinely check different things and merging them would mean a parameter that silently turns
 * the replay protection off.
 *
 * **The absence of a timestamp is the service's design, not an oversight here.** A captured
 * ComplyCube webhook stays valid for ever, so the caller must dedupe on the event id, which is
 * what Hpve_Provider_Hosted does before it queues anything.
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Verifies a body-only HMAC signature.
 */
final class Hpve_Plain_Signature {

	/**
	 * Signs a payload. Used by the tests to build fixtures.
	 *
	 * @param string $payload Raw body.
	 * @param string $secret Signing secret.
	 * @return string The hex signature.
	 */
	public static function sign( $payload, $secret ) {
		return hash_hmac( 'sha256', (string) $payload, (string) $secret );
	}

	/**
	 * Verifies a header against the raw body.
	 *
	 * @param string $payload Raw request body, exactly as received.
	 * @param string $header The signature header.
	 * @param string $secret The endpoint's signing secret.
	 * @return bool
	 */
	public static function verify( $payload, $header, $secret ) {
		$secret = trim( (string) $secret );
		$header = trim( (string) $header );

		if ( '' === $secret || '' === $header ) {
			return false;
		}

		// Some senders prefix the scheme; accept "sha256=<hex>" as well as a bare digest.
		if ( 0 === stripos( $header, 'sha256=' ) ) {
			$header = substr( $header, 7 );
		}

		return hash_equals( self::sign( $payload, $secret ), $header );
	}
}
