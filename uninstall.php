<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching the
 * plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's settings, every vendor's dates and every verification
 * request by default.** Someone who deletes the plugin by accident, or removes it to install a clean
 * copy, gets everything back when they reinstall. Destruction is opt-in, through the "Delete all data"
 * checkbox on the plugin's settings tab, and is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in wp-admin/plugins.php:400-412 is
 * hard-coded with no do_action or apply_filters inside it, so a checkbox cannot be added to that
 * screen; the setting has to live on our own tab. Worse, WordPress prints "(will also delete its
 * data)" on that screen whenever an uninstall.php exists at all (wp-admin/plugins.php:379, WP 7.1),
 * whatever the file actually does, so the setting's own description tells the owner that the core
 * warning does not apply unless they ticked the box.
 *
 * **Nothing here ever touches a vendor's verified status**, whichever way the setting is set. The
 * plugin only ever decided WHEN the badge comes off; deleting the plugin means that stops being
 * decided, not that everyone loses their badge.
 *
 * HivePress is not loaded while this runs, so its cascade (which deletes a request's attachments with
 * the request) is absent, and WordPress itself only re-parents attachments on wp_delete_post(). The
 * delete branch therefore removes each request's documents and files by hand, then the request.
 *
 * @package Verification_Expiry
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Exit unless WordPress is genuinely uninstalling this plugin.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * The option prefix, repeated here rather than read from the main plugin file, because uninstall.php
 * runs on its own and that file is never loaded. Must match HPVE_OPTION_PREFIX with "hp_" in front.
 */
$hpve_prefix = 'hp_verification_expiry_for_hivepress_';

/**
 * Removes a directory tree. Only ever called on the plugin's own private folder.
 *
 * @param string $dir Directory.
 * @return void
 */
function hpve_uninstall_remove_dir( $dir ) {
	$entries = array_diff( (array) scandir( $dir ), [ '.', '..' ] );

	foreach ( $entries as $entry ) {
		$path = $dir . '/' . $entry;

		if ( is_dir( $path ) && ! is_link( $path ) ) {
			hpve_uninstall_remove_dir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own private folder on uninstall.
}

/**
 * Removes this plugin's traces from the site that is current when it is called.
 *
 * Written as a function so that on a network it can run once per site: the uninstaller runs in
 * the context of one site only.
 *
 * @param string $prefix Option prefix.
 * @return void
 */
function hpve_uninstall_site( $prefix ) {
	global $wpdb;

	// Read the owner's choice first, before anything is touched.
	$delete_all = ! empty( get_option( $prefix . 'delete_data' ) );

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Always cleaned, whichever way the setting is set.
	 * -------------------------------------------------------------------------------------------------
	 */

	// The updater's cached release lookup. A site transient lives under its own prefix, so neither the
	// option sweep below nor a plain delete_option() would ever reach it.
	delete_site_transient( 'verification_expiry_for_hivepress_release' );

	/*
	 * The updater's other two site transients and its background job.
	 *
	 * All three are regenerable runtime state belonging to the update check, not the owner's
	 * configuration, so they go unconditionally alongside the release cache above. The scheduled
	 * refresh is worse than debris: it is a job whose callback no longer exists. Unscheduled from both
	 * places it can be, because the refresh is queued through HivePress's scheduler (Action Scheduler)
	 * when HivePress is present and through WP-Cron when it is not.
	 */
	delete_site_transient( 'verification_expiry_for_hivepress_release_reason' );
	delete_site_transient( 'verification_expiry_for_hivepress_release_rate_limit' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'verification_expiry_for_hivepress_release_refresh', [], 'hivepress' );
		as_unschedule_all_actions( 'verification_expiry_for_hivepress_release_refresh' );
	}

	wp_clear_scheduled_hook( 'verification_expiry_for_hivepress_release_refresh' );

	// The plugin's own background jobs: the listing badge sync, the retention batches and the three
	// Stripe jobs. All queued through HivePress's scheduler (Action Scheduler, group "hivepress"); with
	// the plugin gone their callbacks no longer exist. as_unschedule_all_actions() with an empty args
	// array clears every pending action of that hook whatever its arguments.
	foreach ( [ 'hpve_sync_listing_badges', 'hpve_retention', 'hpve_stripe_create_session', 'hpve_stripe_sync', 'hpve_stripe_redact' ] as $hpve_hook ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hpve_hook, [], 'hivepress' );
			as_unschedule_all_actions( $hpve_hook );
		}

		wp_clear_scheduled_hook( $hpve_hook );
	}

	// Every ordinary transient the plugin sets: webhook dedupe (hpve_evt_*), single-use session URLs
	// (hpve_session_url_*), the start rate limit (hpve_rate_*) and the view-log coalescing
	// (hpve_viewed_*). A transient is stored as "_transient_{name}" plus a separate
	// "_transient_timeout_{name}" row, so the prefix sweep used for options below cannot match them: it
	// anchors on the prefix at the start of the name.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard option names, which no WordPress API can enumerate.
	$transients = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $wpdb->esc_like( $prefix ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%',
			'_transient_' . $wpdb->esc_like( 'hpve_' ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( 'hpve_' ) . '%'
		)
	);

	foreach ( (array) $transients as $transient_name ) {
		delete_option( $transient_name );
	}

	// The page routes this plugin added are gone with it; the cached rules would still name them.
	delete_option( 'rewrite_rules' );

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Everything below happens only when the owner asked for it.
	 * -------------------------------------------------------------------------------------------------
	 */

	if ( $delete_all ) {

		// The private folder, read before the options that name it are swept.
		$storage_dir  = (string) get_option( $prefix . 'storage_dir', '' );
		$storage_real = '' !== $storage_dir ? realpath( $storage_dir ) : false;

		// Every verification request, with its documents, their files and its history. Explicit because
		// HivePress's cascade is absent during uninstall and WordPress only re-parents attachments.
		$request_ids = get_posts(
			[
				'post_type'      => 'hp_hpve_request',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		foreach ( (array) $request_ids as $request_id ) {
			$request_id = (int) $request_id;

			$attachment_ids = get_posts(
				[
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'post_parent'    => $request_id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => 'hp_hpve_private', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off uninstall sweep.
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);

			foreach ( (array) $attachment_ids as $attachment_id ) {
				$attachment_id = (int) $attachment_id;

				// Unlink the file ourselves: in external mode WordPress refuses to delete a file that is
				// not under the uploads folder (wp-includes/functions.php, wp_delete_file_from_directory()).
				// The plugin's own filter that keeps a Windows "X:/" path absolute is not loaded here.
				$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

				if ( '' !== $file && ! preg_match( '#^([A-Za-z]:)?/#', $file ) ) {
					$file = wp_get_upload_dir()['basedir'] . '/' . $file;
				}

				$real = '' !== $file ? realpath( $file ) : false;

				if ( $real && $storage_real && 0 === strpos( wp_normalize_path( $real ), wp_normalize_path( $storage_real ) . '/' ) ) {
					wp_delete_file( $real );
				}

				wp_delete_attachment( $attachment_id, true );
			}

			// The audit trail. wp_delete_post() removes a post's comments as well, but only comments the
			// default query sees; the explicit type keeps trashed lines from surviving.
			$log_ids = get_comments(
				[
					'type'    => 'hp_hpve_log',
					'post_id' => $request_id,
					'status'  => 'any',
					'fields'  => 'ids',
				]
			);

			foreach ( (array) $log_ids as $log_id ) {
				wp_delete_comment( (int) $log_id, true );
			}

			wp_delete_post( $request_id, true );
		}

		// The private folder itself, only when its real path is the one the plugin recorded and it
		// carries the plugin's own name, so a bare uploads or web root can never be removed by mistake.
		if ( $storage_real && is_dir( $storage_real ) && false !== strpos( basename( $storage_real ), 'hpve-private-' ) ) {
			hpve_uninstall_remove_dir( $storage_real );
		}

		// The period, expiry date, expiry record and reminder marker on every vendor and listing (the
		// same four keys on both post types), plus the two request pointers on vendors. The keys are
		// repeated here for the same reason as the prefix: the component that defines them is not loaded.
		// hp_verified itself is deliberately NOT in this list.
		foreach ( [ 'hp_hpve_verified_period', 'hp_hpve_verified_until', 'hp_hpve_verified_expired_time', 'hp_hpve_reminded_until', 'hp_hpve_request_id', 'hp_hpve_subscription_id' ] as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}

		// Order and subscription meta (_hpve_request_id) is left in place on purpose: it is
		// WooCommerce's record of what was bought, and an order should keep saying what it paid for.

		// The owner's edited versions of the twelve emails. HivePress keeps an edited email as an
		// hp_email post whose slug is the email name (components/class-email.php:59-91), and with the
		// plugin gone those names no longer exist, so the posts would sit under HivePress > Emails
		// describing emails nothing can send.
		$email_posts = get_posts(
			[
				'post_type'      => 'hp_email',
				'post_status'    => 'any',
				'post_name__in'  => [
					'hpve_vendor_verification_verified',
					'hpve_vendor_verification_expire',
					'hpve_vendor_verification_remind',
					'hpve_listing_verification_verified',
					'hpve_listing_verification_expire',
					'hpve_listing_verification_remind',
					'hpve_request_submitted',
					'hpve_request_received',
					'hpve_request_needs_info',
					'hpve_request_rejected',
					'hpve_request_paid',
					'hpve_request_signoff',
				],
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		foreach ( (array) $email_posts as $email_post_id ) {
			wp_delete_post( (int) $email_post_id, true );
		}

		// Delete the options by prefix. This runs once, while the plugin is being deleted, so there is
		// nothing worth caching.
		//
		// The "delete all data" option itself is excluded here and removed at the very end. If this run
		// fails part-way through, the flag is still set, so a second attempt finishes the job. Sweeping it
		// away first would silently flip the site back to "retain" with half the data already gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard option names, which no WordPress API can enumerate.
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
				$wpdb->esc_like( $prefix ) . '%',
				$prefix . 'delete_data'
			)
		);

		foreach ( (array) $option_names as $option_name ) {

			// Use the options API so persistent object caches are invalidated too.
			delete_option( $option_name );
		}

		// Last, and only once everything above has succeeded.
		delete_option( $prefix . 'delete_data' );
	}
}

/*
 * A network install runs this file once, in one site's context. Every other site on the network has
 * its own vendors and its own settings, so each one is visited in turn. On a single site the loop is
 * skipped entirely and nothing changes.
 */
if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $hpve_site_id ) {
		switch_to_blog( (int) $hpve_site_id );

		hpve_uninstall_site( $hpve_prefix );

		restore_current_blog();
	}
} else {
	hpve_uninstall_site( $hpve_prefix );
}
