<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching the
 * plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps the owner's settings and every vendor's dates by default.** Someone
 * who deletes the plugin by accident, or removes it to install a clean copy, gets everything back
 * when they reinstall. Destruction is opt-in, through the "Delete all data" checkbox on the
 * plugin's settings tab, and is never a surprise.
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

	// The one-off listing badge sync queued when "Badges to Expire" is switched to both. Queued
	// through HivePress's scheduler (Action Scheduler, group "hivepress"); with the plugin gone its
	// callback no longer exists.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'hpve_sync_listing_badges', [], 'hivepress' );
	}

	// Any ordinary transient the plugin has ever set. Nothing writes one today, but a transient is
	// stored as "_transient_{name}" plus a separate "_transient_timeout_{name}" row, so the prefix sweep
	// used for options below cannot match them: it anchors on the prefix at the start of the name.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard option names, which no WordPress API can enumerate.
	$transients = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $wpdb->esc_like( $prefix ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
		)
	);

	foreach ( (array) $transients as $transient_name ) {
		delete_option( $transient_name );
	}

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Everything below happens only when the owner asked for it.
	 * -------------------------------------------------------------------------------------------------
	 */

	if ( $delete_all ) {

		// The period, expiry date, expiry record and reminder marker on every vendor. The keys are
		// repeated here for the same reason as the prefix: the component that defines them is not loaded.
		// hp_verified itself is deliberately NOT in this list.
		foreach ( [ 'hp_hpve_verified_period', 'hp_hpve_verified_until', 'hp_hpve_verified_expired_time', 'hp_hpve_reminded_until' ] as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}

		// The owner's edited versions of the two emails. HivePress keeps an edited email as an hp_email
		// post whose slug is the email name (components/class-email.php:59-91), and with the plugin
		// gone those two names no longer exist, so the posts would sit under HivePress > Emails
		// describing emails nothing can send.
		$email_posts = get_posts(
			[
				'post_type'      => 'hp_email',
				'post_status'    => 'any',
				'post_name__in'  => [ 'hpve_vendor_verification_expire', 'hpve_vendor_verification_remind' ],
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
