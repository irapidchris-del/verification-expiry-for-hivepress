<?php
/**
 * Verification provider interface.
 *
 * A provider is whatever checks an applicant's identity once they press Send: a person reading
 * documents in wp-admin ("manual"), or a service such as Stripe Identity. The request lifecycle,
 * the emails and the audit trail belong to the request component; a provider only starts a
 * check, tells the applicant where to go, receives the result and hands it back through
 * hivepress()->hpve_request->apply_event(). Nothing in a provider writes a request status
 * directly.
 *
 * Registering a third provider: return its class from the hpve_verification_providers filter,
 * keyed by the name get_name() returns, and require_once its file yourself. The settings tab
 * lists every registered provider by get_label() and shows get_setup_notice() beside one that
 * is not configured. Full notes: handover.md, "The provider interface".
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

interface Hpve_Provider_Interface {

	/**
	 * The stored name, e.g. "manual" or "stripe_identity". Machine-facing, never translated.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * The name shown in the settings dropdown.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether the provider can actually run a check right now (keys present and so on).
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * One plain sentence for the settings tab when is_configured() is false, else an empty string.
	 *
	 * @return string
	 */
	public function get_setup_notice();

	/**
	 * Whether the site still collects documents on the request form with this provider active.
	 *
	 * @return bool
	 */
	public function supports_documents();

	/**
	 * Called by apply_event( 'submit' ) once the request is pending. Queues work; never blocks.
	 *
	 * @param int $request_id Request ID.
	 * @return true|\WP_Error
	 */
	public function start( $request_id );

	/**
	 * A URL the applicant should be sent to, or an empty string while none is ready. Single use.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public function get_redirect_url( $request_id );

	/**
	 * The applicant is back from the provider. Must never decide anything; queue a sync instead.
	 *
	 * @param int   $request_id Request ID.
	 * @param array $params Query parameters, untrusted.
	 * @return true|\WP_Error
	 */
	public function handle_return( $request_id, array $params );

	/**
	 * A webhook arrived. Verifies, dedupes and queues; returns the HTTP status to answer with.
	 *
	 * @param string $body Raw request body.
	 * @param array  $headers Lower-cased header names to values.
	 * @return int
	 */
	public function handle_webhook( $body, array $headers );

	/**
	 * Background job: fetch the current result, map it and apply the event.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $event_id What triggered the sync: a webhook event id, 'return' or 'manual'.
	 * @return void
	 */
	public function sync( $request_id, $event_id );
}
