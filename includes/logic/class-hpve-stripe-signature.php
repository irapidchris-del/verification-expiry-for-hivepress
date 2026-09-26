<?php
/**
 * Stripe webhook signature verifier.
 *
 * Pure PHP, copied in shape from direct-charges-for-hivepress/includes/helpers.php:2374-2416
 * (the verifier that has taken real webhooks on a live site) with the clock injected so the
 * tests can sign a fixture at a chosen moment. Not the gateway's own validate_request(), which
 * compares with preg_match rather than in constant time.
 *
 * Stripe's rules (docs.stripe.com/webhooks, "Verify manually", read 2026-09-06): the header is
 * "t=<ts>,v1=<hex>[,v1=<hex>][,v0=...]"; the signed payload is "t.raw_body"; only the v1 scheme
 * counts; a rolled secret means two valid v1 values for up to 24 hours; and the timestamp must
 * be within a tolerance, never zero.
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Verifies the Stripe-Signature header.
 */
final class Hpve_Stripe_Signature {

	/**
	 * Default tolerance in seconds, matching Stripe's own libraries.
	 */
	const TOLERANCE = 300;

	/**
	 * Parses the header into its schemes.
	 *
	 * @param string $header Header value.
	 * @return array Map of scheme to list of values.
	 */
	public static function parse( $header ) {
		$parts = [];

		foreach ( explode( ',', (string) $header ) as $item ) {
			$item = trim( $item );

			if ( false === strpos( $item, '=' ) ) {
				continue;
			}

			list( $key, $value ) = array_map( 'trim', explode( '=', $item, 2 ) );

			if ( '' !== $key && '' !== $value ) {
				$parts[ $key ][] = $value;
			}
		}

		return $parts;
	}

	/**
	 * Signs a payload the way Stripe does. Used by the tests to build fixtures.
	 *
	 * @param string $payload Raw body.
	 * @param string $secret Signing secret.
	 * @param int    $timestamp Unix time.
	 * @return string The hex signature.
	 */
	public static function sign( $payload, $secret, $timestamp ) {
		return hash_hmac( 'sha256', (int) $timestamp . '.' . (string) $payload, (string) $secret );
	}

	/**
	 * Verifies a header against the raw body.
	 *
	 * @param string   $payload Raw request body, exactly as received.
	 * @param string   $header The Stripe-Signature header.
	 * @param string   $secret The endpoint's signing secret.
	 * @param int|null $now Current Unix time; null means time().
	 * @param int      $tolerance Allowed clock skew in seconds.
	 * @return bool
	 */
	public static function verify( $payload, $header, $secret, $now = null, $tolerance = self::TOLERANCE ) {
		$secret = trim( (string) $secret );

		if ( '' === $secret ) {
			return false;
		}

		$parts = self::parse( $header );

		if ( empty( $parts['t'][0] ) || empty( $parts['v1'] ) ) {
			return false;
		}

		if ( ! ctype_digit( (string) $parts['t'][0] ) ) {
			return false;
		}

		$timestamp = (int) $parts['t'][0];

		if ( null === $now ) {
			$now = time();
		}

		if ( $timestamp <= 0 || abs( (int) $now - $timestamp ) > (int) $tolerance ) {
			return false;
		}

		$expected = self::sign( $payload, $secret, $timestamp );

		foreach ( (array) $parts['v1'] as $signature ) {
			if ( hash_equals( $expected, (string) $signature ) ) {
				return true;
			}
		}

		return false;
	}
}
