<?php
/**
 * Stripe Identity result mapper and event parser.
 *
 * Pure PHP. Turns a VerificationSession's status and last_error.code into the request event
 * the sync job applies, and checks a webhook body before anything trusts it. The codes are
 * Stripe's published list (docs.stripe.com/identity/handle-verification-outcomes, read
 * 2026-09-06); anything unknown is treated as "needs more information" rather than as a pass
 * or a fail.
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Maps Stripe outcomes to request events.
 */
final class Hpve_Stripe_Mapper {

	/**
	 * Document and selfie problems the applicant can fix by trying again.
	 */
	const RETRYABLE = [
		'document_expired',
		'document_unverified_other',
		'document_type_not_supported',
		'selfie_document_missing_photo',
		'selfie_face_mismatch',
		'selfie_unverified_other',
		'selfie_manipulated',
	];

	/**
	 * Problems no retry can fix.
	 */
	const TERMINAL = [
		'under_supported_age',
		'country_not_supported',
	];

	/**
	 * Maps a session to an action.
	 *
	 * @param string $status Session status: verified, processing, requires_input, canceled.
	 * @param string $error_code last_error.code, or an empty string.
	 * @param int    $attempts Attempts made so far, including the one being judged.
	 * @param int    $limit The attempt limit from settings.
	 * @param bool   $admin_signoff Whether an admin must confirm a verified result.
	 * @return array [ 'action' => approve|hold_for_signoff|needs_info|reject|pending|cancelled, 'note' => key ]
	 */
	public static function map( $status, $error_code, $attempts, $limit, $admin_signoff ) {
		$status     = (string) $status;
		$error_code = (string) $error_code;
		$attempts   = max( 0, (int) $attempts );
		$limit      = max( 1, (int) $limit );

		switch ( $status ) {
			case 'verified':
				return [
					'action' => $admin_signoff ? 'hold_for_signoff' : 'approve',
					'note'   => '',
				];

			case 'processing':
				return [
					'action' => 'pending',
					'note'   => '',
				];

			case 'canceled':
				return [
					'action' => 'cancelled',
					'note'   => 'cancelled',
				];

			case 'requires_input':
				if ( '' === $error_code ) {
					return [
						'action' => 'needs_info',
						'note'   => 'unfinished',
					];
				}

				if ( 'consent_declined' === $error_code ) {
					return [
						'action' => 'needs_info',
						'note'   => 'consent_declined',
					];
				}

				if ( in_array( $error_code, self::TERMINAL, true ) ) {
					return [
						'action' => 'reject',
						'note'   => 'stripe_reason',
					];
				}

				if ( in_array( $error_code, self::RETRYABLE, true ) && $attempts >= $limit ) {
					return [
						'action' => 'reject',
						'note'   => 'stripe_reason',
					];
				}

				return [
					'action' => 'needs_info',
					'note'   => 'stripe_reason',
				];
		}

		return [
			'action' => 'needs_info',
			'note'   => 'unknown',
		];
	}

	/**
	 * Checks a decoded webhook body and extracts what the plugin needs from it.
	 *
	 * @param mixed $event The decoded JSON.
	 * @return array|string [ event_id, session_id, request_id, livemode, type ] or an error code:
	 *                      'not_object', 'not_identity', 'bad_event_id', 'bad_session_id', 'bad_request_id'.
	 */
	public static function parse_event( $event ) {
		if ( ! is_array( $event ) ) {
			return 'not_object';
		}

		$type = isset( $event['type'] ) ? (string) $event['type'] : '';

		if ( 0 !== strpos( $type, 'identity.verification_session.' ) ) {
			return 'not_identity';
		}

		$event_id = isset( $event['id'] ) ? (string) $event['id'] : '';

		if ( ! preg_match( '/^evt_[A-Za-z0-9]+$/', $event_id ) ) {
			return 'bad_event_id';
		}

		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : [];

		$session_id = isset( $object['id'] ) ? (string) $object['id'] : '';

		if ( ! preg_match( '/^vs_[A-Za-z0-9]+$/', $session_id ) ) {
			return 'bad_session_id';
		}

		$request_id = isset( $object['metadata']['hpve_request_id'] ) ? $object['metadata']['hpve_request_id'] : 0;

		if ( ! is_numeric( $request_id ) || (int) $request_id <= 0 ) {
			return 'bad_request_id';
		}

		return [
			'event_id'   => $event_id,
			'session_id' => $session_id,
			'request_id' => (int) $request_id,
			'livemode'   => ! empty( $event['livemode'] ),
			'type'       => $type,
		];
	}

	/**
	 * Whether a session id looks like one Stripe issued.
	 *
	 * @param mixed $session_id Session id.
	 * @return bool
	 */
	public static function is_session_id( $session_id ) {
		return is_string( $session_id ) && 1 === preg_match( '/^vs_[A-Za-z0-9]+$/', $session_id );
	}
}
