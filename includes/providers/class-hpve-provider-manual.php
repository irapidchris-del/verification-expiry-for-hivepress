<?php
/**
 * Manual review provider.
 *
 * The default. A person opens Verifications in wp-admin, reads the documents and decides. The
 * provider itself does almost nothing: the request component has already moved the request to
 * pending and sent the applicant's and the admin's emails by the time start() is called.
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A human reviewer.
 */
final class Hpve_Provider_Manual implements Hpve_Provider_Interface {

	/**
	 * Gets the stored name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'manual';
	}

	/**
	 * Gets the label.
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Manual review', 'verification-expiry-for-hivepress' );
	}

	/**
	 * Always configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Nothing to set up.
	 *
	 * @return string
	 */
	public function get_setup_notice() {
		return '';
	}

	/**
	 * Documents are the whole point of manual review.
	 *
	 * @return bool
	 */
	public function supports_documents() {
		return true;
	}

	/**
	 * Nothing to start; the queue is the check.
	 *
	 * @param int $request_id Request ID.
	 * @return true
	 */
	public function start( $request_id ) {
		return true;
	}

	/**
	 * No hosted flow.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public function get_redirect_url( $request_id ) {
		return '';
	}

	/**
	 * No return leg.
	 *
	 * @param int   $request_id Request ID.
	 * @param array $params Parameters.
	 * @return true
	 */
	public function handle_return( $request_id, array $params ) {
		return true;
	}

	/**
	 * No webhook.
	 *
	 * @param string $body Body.
	 * @param array  $headers Headers.
	 * @return int
	 */
	public function handle_webhook( $body, array $headers ) {
		return 404;
	}

	/**
	 * Nothing to sync.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $event_id Event id.
	 * @return void
	 */
	public function sync( $request_id, $event_id ) {}
}
