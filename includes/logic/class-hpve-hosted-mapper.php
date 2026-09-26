<?php
/**
 * Pure mapping for the hosted identity providers: Persona and ComplyCube.
 *
 * Each service has its own vocabulary, so each gets its own map here rather than one shared table
 * of guesses. Both end in the same five actions the request component understands, and both share
 * the attempt-limit rule: a failed check is worth offering again until the owner's limit is
 * reached, after which the applicant is told why and given the document route instead of an
 * endless loop.
 *
 * No WordPress and no HTTP, so the logic tests drive every branch.
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Hosted provider results to request actions.
 */
final class Hpve_Hosted_Mapper {

	/**
	 * Persona inquiry statuses that mean the applicant passed.
	 *
	 * "approved" is the post-inquiry workflow's verdict; "completed" means every verification in
	 * the template passed but no workflow has judged it. A site with no post-inquiry workflow only
	 * ever sees "completed", so treating that as a failure would mean nobody ever passes
	 * (docs.withpersona.com/model-lifecycle, read 2026-09-07).
	 */
	const PERSONA_PASS = [ 'approved', 'completed' ];

	/**
	 * Persona statuses that mean a person should look.
	 */
	const PERSONA_REVIEW = [ 'needs-review', 'needs_review', 'marked-for-review' ];

	/**
	 * Persona statuses that mean the applicant is still working through it.
	 */
	const PERSONA_OPEN = [ 'created', 'pending', 'started', 'expired-with-progress' ];

	/**
	 * Persona statuses that mean this attempt did not succeed.
	 */
	const PERSONA_FAIL = [ 'declined', 'failed', 'expired' ];

	/**
	 * Maps a Persona inquiry status to an action.
	 *
	 * @param string $status Inquiry status.
	 * @param int    $attempts Attempts used so far.
	 * @param int    $limit Attempts allowed.
	 * @param bool   $signoff Whether a pass waits for an admin.
	 * @return array{action: string, note: string}
	 */
	public static function map_persona( $status, $attempts, $limit, $signoff ) {
		$status = strtolower( trim( (string) $status ) );

		if ( in_array( $status, self::PERSONA_PASS, true ) ) {
			return self::result( $signoff ? 'hold_for_signoff' : 'approve', 'ok' );
		}

		if ( in_array( $status, self::PERSONA_REVIEW, true ) ) {
			return self::result( 'hold_for_signoff', 'review' );
		}

		if ( in_array( $status, self::PERSONA_OPEN, true ) ) {
			return self::result( 'pending', 'unfinished' );
		}

		if ( in_array( $status, self::PERSONA_FAIL, true ) ) {
			$note = 'expired' === $status ? 'expired' : 'declined';

			return self::spend_attempt( $attempts, $limit, $note );
		}

		// A status this plugin has not been taught never decides anything by itself.
		return self::result( 'hold_for_signoff', 'unknown' );
	}

	/**
	 * Maps a ComplyCube workflow outcome to an action.
	 *
	 * ComplyCube reports three outcomes: "clear", "attention" and "rejected"
	 * (docs.complycube.com api reference, read 2026-09-07). "attention" is deliberately NOT a
	 * refusal: it means the check found something a human should read, which is exactly what the
	 * sign-off route is for.
	 *
	 * @param string $outcome Workflow outcome.
	 * @param int    $attempts Attempts used so far.
	 * @param int    $limit Attempts allowed.
	 * @param bool   $signoff Whether a pass waits for an admin.
	 * @return array{action: string, note: string}
	 */
	public static function map_complycube( $outcome, $attempts, $limit, $signoff ) {
		$outcome = strtolower( trim( (string) $outcome ) );

		if ( 'clear' === $outcome ) {
			return self::result( $signoff ? 'hold_for_signoff' : 'approve', 'ok' );
		}

		if ( 'attention' === $outcome ) {
			return self::result( 'hold_for_signoff', 'review' );
		}

		if ( 'rejected' === $outcome ) {
			return self::spend_attempt( $attempts, $limit, 'declined' );
		}

		if ( '' === $outcome || 'pending' === $outcome ) {
			return self::result( 'pending', 'unfinished' );
		}

		return self::result( 'hold_for_signoff', 'unknown' );
	}

	/**
	 * A failed attempt: offer another go until the limit, then stop.
	 *
	 * @param int    $attempts Attempts used so far.
	 * @param int    $limit Attempts allowed.
	 * @param string $note Note key.
	 * @return array
	 */
	protected static function spend_attempt( $attempts, $limit, $note ) {
		$limit = max( 1, (int) $limit );

		if ( (int) $attempts >= $limit ) {
			return self::result( 'reject', $note );
		}

		return self::result( 'needs_info', $note );
	}

	/**
	 * Builds a result array.
	 *
	 * @param string $action Action.
	 * @param string $note Note key.
	 * @return array
	 */
	protected static function result( $action, $note ) {
		return [
			'action' => $action,
			'note'   => $note,
		];
	}
}
