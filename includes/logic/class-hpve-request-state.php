<?php
/**
 * Request state machine.
 *
 * Pure PHP with no WordPress or HivePress calls, so tests/logic-tests.php can drive every
 * transition under the plain php binary. The component (includes/components/class-hpve-request.php)
 * is the ONLY caller that turns an answer from here into a database write; see apply_event() there.
 *
 * The class lives outside the HivePress namespace on purpose: core globs includes/{type}/*.php
 * across every extension and instantiates \HivePress\{Type}\{Filename}, and a class it cannot
 * instantiate is harmless but a class it can would run on every request. It is loaded with
 * require_once from the main plugin file.
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Decides what a request may become, and how the account page describes it.
 */
final class Hpve_Request_State {

	/**
	 * Post statuses a request can hold.
	 */
	const STATUSES = [ 'draft', 'pending', 'publish', 'trash' ];

	/**
	 * Sub-states stored in the outcome meta while the post status is draft.
	 */
	const OUTCOMES = [ '', 'needs_info', 'rejected', 'expired', 'revoked', 'cancelled', 'renewal' ];

	/**
	 * Events that never move the status and exist only so the audit trail records them.
	 */
	const RECORD_ONLY = [ 'paid', 'refund', 'cancel', 'subscription_active', 'subscription_on_hold', 'subscription_cancelled', 'subscription_expired', 'subscription_pending_cancel', 'renewal', 'document_uploaded', 'document_deleted', 'documents_deleted', 'provider_started', 'provider_result' ];

	/**
	 * Card states, in the order the account page tests them.
	 */
	const CARD_STATES = [ 'no_vendor', 'not_started', 'payment_needed', 'pending', 'processing', 'needs_info', 'verified', 'renewal_due', 'renewing', 'rejected', 'expired', 'cancelled' ];

	/**
	 * Works out the status and outcome an event leads to.
	 *
	 * @param string $status Current post status, or an empty string for "no request yet".
	 * @param string $outcome Current outcome meta.
	 * @param string $event Event name.
	 * @return array|string [ status, outcome ] on success, or an error code string.
	 */
	public static function transition( $status, $outcome, $event ) {
		$status  = (string) $status;
		$outcome = (string) $outcome;
		$event   = (string) $event;

		if ( 'create' === $event ) {
			return '' === $status ? [ 'draft', '' ] : 'invalid_transition';
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return 'invalid_status';
		}

		if ( 'trash' === $status ) {
			return 'trashed';
		}

		if ( in_array( $event, self::RECORD_ONLY, true ) ) {
			return [ $status, $outcome ];
		}

		switch ( $event ) {
			case 'submit':
				return 'draft' === $status ? [ 'pending', '' ] : 'invalid_transition';

			case 'needs_info':
				return 'pending' === $status ? [ 'draft', 'needs_info' ] : 'invalid_transition';

			case 'reject':
				return 'pending' === $status ? [ 'draft', 'rejected' ] : 'invalid_transition';

			case 'approve':
				return 'pending' === $status ? [ 'publish', '' ] : 'invalid_transition';

			case 'provider_cancel':
				return 'pending' === $status ? [ 'draft', 'cancelled' ] : 'invalid_transition';

			// Early renewal: a verified request goes back to a draft so the Vendor can add fresh
			// documents, and the outcome says why. Unlike reopen, the Vendor keeps the badge until
			// the old date passes; the component never unticks on this event.
			case 'renew':
				return 'publish' === $status ? [ 'draft', 'renewal' ] : 'invalid_transition';

			case 'reopen':
				if ( 'publish' === $status || ( 'draft' === $status && in_array( $outcome, [ 'needs_info', 'rejected' ], true ) ) ) {
					return [ 'pending', '' ];
				}

				return 'invalid_transition';

			case 'expire':
				return 'publish' === $status ? [ 'draft', 'expired' ] : 'invalid_transition';

			case 'revoke':
				return 'publish' === $status ? [ 'draft', 'revoked' ] : 'invalid_transition';

			case 'trash':
				return [ 'trash', $outcome ];
		}

		return 'invalid_event';
	}

	/**
	 * Derives the card the applicant sees. Nothing here is stored.
	 *
	 * @param array $facts Keys: has_vendor, has_request, status, outcome, paid, payment_required,
	 *                     provider, provider_status, vendor_verified, has_documents.
	 * @return string One of CARD_STATES.
	 */
	public static function card_state( array $facts ) {
		$has_vendor  = ! empty( $facts['has_vendor'] );
		$has_request = ! empty( $facts['has_request'] );
		$status      = isset( $facts['status'] ) ? (string) $facts['status'] : '';
		$outcome     = isset( $facts['outcome'] ) ? (string) $facts['outcome'] : '';

		if ( ! $has_request ) {
			return $has_vendor ? 'not_started' : 'no_vendor';
		}

		if ( 'pending' === $status ) {
			$provider        = isset( $facts['provider'] ) ? (string) $facts['provider'] : '';
			$provider_status = isset( $facts['provider_status'] ) ? (string) $facts['provider_status'] : '';

			if ( 'stripe_identity' === $provider && in_array( $provider_status, [ 'requires_input', 'processing' ], true ) ) {
				return 'processing';
			}

			return 'pending';
		}

		if ( 'publish' === $status ) {
			return ! empty( $facts['renewal_due'] ) ? 'renewal_due' : 'verified';
		}

		if ( 'draft' === $status ) {
			switch ( $outcome ) {
				case 'renewal':
					// The old date passed before the renewal was sent: the badge has gone, so say so.
					return ! empty( $facts['vendor_verified'] ) ? 'renewing' : 'expired';
				case 'needs_info':
					return 'needs_info';
				case 'rejected':
					return 'rejected';
				case 'expired':
					return 'expired';
				case 'cancelled':
					return 'cancelled';
			}

			if ( ! empty( $facts['payment_required'] ) && empty( $facts['paid'] ) ) {
				return 'payment_needed';
			}
		}

		return 'not_started';
	}

	/**
	 * The word shown under the account menu item, untranslated.
	 *
	 * The component maps these keys to translated strings; keeping the keys here means the test
	 * can assert on them without a gettext stub.
	 *
	 * @param string $card_state Card state.
	 * @return string '' | 'pending' | 'action_needed' | 'expired'
	 */
	public static function menu_word( $card_state ) {
		switch ( $card_state ) {
			case 'pending':
			case 'processing':
				return 'pending';
			case 'needs_info':
				return 'action_needed';
			case 'expired':
				return 'expired';
			case 'renewal_due':
				return 'renewal_due';
		}

		return '';
	}

	/**
	 * The hp-status modifier for a card state.
	 *
	 * @param string $card_state Card state.
	 * @return string
	 */
	public static function pill_modifier( $card_state ) {
		switch ( $card_state ) {
			case 'pending':
			case 'processing':
				return 'pending';
			case 'verified':
			case 'renewal_due':
			case 'renewing':
				return 'publish';
			case 'rejected':
				return 'error';
			case 'expired':
				return 'trash';
			case 'no_vendor':
				return '';
		}

		return 'draft';
	}

	/**
	 * Whether the document form is shown under the card.
	 *
	 * @param string $card_state Card state.
	 * @param bool   $supports_documents Whether the active provider still collects documents.
	 * @return bool
	 */
	public static function form_open( $card_state, $supports_documents ) {
		if ( ! $supports_documents ) {
			return false;
		}

		return in_array( $card_state, [ 'not_started', 'needs_info', 'rejected', 'expired', 'cancelled', 'renewal_due', 'renewing' ], true );
	}

	/**
	 * Whether a verification is inside its renewal window.
	 *
	 * The window is the reminder window: from the day the reminder email goes out
	 * (`reminder_days` before the date) up to and including the date itself. No date, or a
	 * window of zero days, means there is nothing to renew early.
	 *
	 * @param string $until Expiry date as Y-m-d, or an empty string.
	 * @param string $today Today as Y-m-d, in the site's timezone.
	 * @param int    $days Days in the window.
	 * @return bool
	 */
	public static function renewal_due( $until, $today, $days ) {
		$days = (int) $days;

		if ( $days < 1 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $until ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $today ) ) {
			return false;
		}

		$opens = ( new \DateTimeImmutable( $until ) )->sub( new \DateInterval( 'P' . $days . 'D' ) )->format( 'Y-m-d' );

		// Y-m-d strings compare correctly as strings.
		return $today >= $opens && $today <= $until;
	}

	/**
	 * Whether the owner may add or remove documents in this status.
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	public static function owner_can_edit( $status ) {
		return 'draft' === $status;
	}
}
