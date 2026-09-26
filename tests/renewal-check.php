<?php
/**
 * Early renewal, against a real HivePress install (2.1.0).
 *
 * One run seeds its own users and Vendors, checks, and removes everything it made, restoring
 * every option it touched. No email leaves the site: pre_wp_mail records what would have gone.
 *
 *   wp eval-file tests/renewal-check.php
 *
 * @package HivePress\Verification_Expiry
 */

// phpcs:ignoreFile -- a test harness, excluded from the gate and from the release zip.

use HivePress\Components\Hpve_Verification;
use HivePress\Models;

$hpve_rn_pass = 0;
$hpve_rn_fail = 0;

$hpve_rn = function ( $label, $ok ) use ( &$hpve_rn_pass, &$hpve_rn_fail ) {
	if ( $ok ) {
		$hpve_rn_pass++;
		echo "  ok   {$label}\n";
	} else {
		$hpve_rn_fail++;
		echo "  FAIL {$label}\n";
	}
};

$hpve_rn_mail = [];

add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( &$hpve_rn_mail ) {
		$hpve_rn_mail[] = [ 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'] ];
		return true;
	},
	1,
	2
);

$component = hivepress()->hpve_request;
$clock     = hivepress()->hpve_verification;
$prefix    = 'hp_' . HPVE_OPTION_PREFIX;
$today     = current_time( 'Y-m-d' );
$in_days   = function ( $days ) use ( $today ) {
	return ( new DateTimeImmutable( $today, wp_timezone() ) )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
};
$plus_year = function ( $date ) {
	return ( new DateTimeImmutable( $date, wp_timezone() ) )->add( new DateInterval( 'P1Y' ) )->format( 'Y-m-d' );
};
$actor     = [ 'name' => 'Renewal Test', 'user_id' => 1 ];

// Settings snapshot.
$snapshot = [];
foreach ( [ 'request_enable', 'default_period', 'reminder_days', 'payment_required', 'doc_retention_days', 'verified_email', 'scope' ] as $name ) {
	$snapshot[ $name ] = get_option( $prefix . $name, null );
}

update_option( $prefix . 'request_enable', '1' );
update_option( $prefix . 'default_period', 'year' );
update_option( $prefix . 'reminder_days', '7' );
update_option( $prefix . 'payment_required', '' );
update_option( $prefix . 'doc_retention_days', '30' );
update_option( $prefix . 'verified_email', '1' );
update_option( $prefix . 'scope', '' );

$made = [ 'users' => [], 'posts' => [] ];

$make_vendor = function ( $slug ) use ( &$made ) {
	$user = wp_insert_user(
		[
			'user_login' => 'hpve_rn_' . $slug,
			'user_email' => 'hpve_rn_' . $slug . '@example.com',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'contributor',
		]
	);

	$vendor = wp_insert_post(
		[
			'post_type'   => 'hp_vendor',
			'post_status' => 'publish',
			'post_title'  => 'HPVE Renewal ' . $slug,
			'post_author' => $user,
		]
	);

	$made['users'][] = $user;
	$made['posts'][] = $vendor;

	return [ $user, $vendor ];
};

$vendor_model = function ( $id ) {
	return Models\Vendor::query()->get_by_id( $id );
};

try {
	echo "\n[R1] A verified Vendor inside the window can renew early\n";

	list( $user_a, $vendor_a ) = $make_vendor( 'a' );
	$request = $component->get_or_create( $user_a, $vendor_a );
	$made['posts'][] = $request->get_id();

	$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
	$component->apply_event( $request, 'approve', [ 'actor' => $actor ] );

	$hpve_rn( 'R1.1 approved request is published', 'publish' === $request->get_status() );
	$hpve_rn( 'R1.2 Vendor ticked', (bool) get_post_meta( $vendor_a, 'hp_verified', true ) );
	$hpve_rn( 'R1.3 first period runs a year from today', $plus_year( $today ) === get_post_meta( $vendor_a, Hpve_Verification::META_UNTIL, true ) );
	$hpve_rn( 'R1.4 outside the window the card says verified', 'verified' === $component->get_card_state( $request, $vendor_model( $vendor_a ) ) );
	$hpve_rn( 'R1.5 outside the window nothing starts', false === $component->maybe_start_renewal( $request ) && 'publish' === $request->get_status() );

	$old_until = $in_days( 3 );
	update_post_meta( $vendor_a, Hpve_Verification::META_UNTIL, $old_until );
	update_post_meta( $vendor_a, Hpve_Verification::META_REMINDED, $old_until );

	$hpve_rn( 'R1.6 three days before the date the card says renewal due', 'renewal_due' === $component->get_card_state( $request, $vendor_model( $vendor_a ) ) );
	$hpve_rn( 'R1.7 menu word', 'Renewal due' === $component->get_menu_word( 'renewal_due' ) );

	$hpve_rn( 'R1.8 the first action starts the renewal', true === $component->maybe_start_renewal( $request ) );
	$hpve_rn( 'R1.9 request is a renewal draft', 'draft' === $request->get_status() && 'renewal' === $request->get_outcome() );
	$hpve_rn( 'R1.10 the badge stays on', (bool) get_post_meta( $vendor_a, 'hp_verified', true ) );
	$hpve_rn( 'R1.11 the old decision date is cleared', ! $request->get_reviewed_time() );
	$hpve_rn( 'R1.12 card says renewing', 'renewing' === $component->get_card_state( $request, $vendor_model( $vendor_a ) ) );
	$hpve_rn( 'R1.13 a second call does nothing', false === $component->maybe_start_renewal( $request ) );

	$component->run_retention();
	$fresh = $component->get_request( $request->get_id() );
	$hpve_rn( 'R1.14 retention leaves a renewal draft alone', ! $fresh->get_docs_deleted_time() );

	$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
	$hpve_rn( 'R1.15 renewal submits to pending', 'pending' === $request->get_status() );

	// Counted from here: submitting also emails the Vendor ("we have your documents").
	$hpve_rn_mail = [];
	$component->apply_event( $request, 'approve', [ 'actor' => $actor ] );
	$hpve_rn( 'R1.16 renewal approved', 'publish' === $request->get_status() );
	$hpve_rn( 'R1.17 new period runs from the OLD date, not today', $plus_year( $old_until ) === get_post_meta( $vendor_a, Hpve_Verification::META_UNTIL, true ) );
	$hpve_rn( 'R1.18 reminder marker cleared for the new date', '' === (string) get_post_meta( $vendor_a, Hpve_Verification::META_REMINDED, true ) );
	$hpve_rn( 'R1.19 still verified', (bool) get_post_meta( $vendor_a, 'hp_verified', true ) );
	$hpve_rn( 'R1.20 Vendor Verified email sent once with the new date', 1 === count( array_filter( $hpve_rn_mail, function ( $m ) { return 'hpve_rn_a@example.com' === ( is_array( $m['to'] ) ? reset( $m['to'] ) : $m['to'] ); } ) ) );
	$hpve_rn( 'R1.21 card back to verified', 'verified' === $component->get_card_state( $request, $vendor_model( $vendor_a ) ) );

	echo "\n[R2] The old date passes while the renewal is still a draft\n";

	update_post_meta( $vendor_a, Hpve_Verification::META_UNTIL, $in_days( 2 ) );
	$component->maybe_start_renewal( $request );
	update_post_meta( $vendor_a, Hpve_Verification::META_UNTIL, $in_days( -1 ) );
	$clock->expire_vendors();

	$hpve_rn( 'R2.1 the expiry job removes the badge', ! get_post_meta( $vendor_a, 'hp_verified', true ) );
	$hpve_rn( 'R2.2 the request stays a renewal draft', 'draft' === $request->get_status() && 'renewal' === $request->get_outcome() );
	$hpve_rn( 'R2.3 card says expired', 'expired' === $component->get_card_state( $request, $vendor_model( $vendor_a ) ) );

	$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
	$component->apply_event( $request, 'approve', [ 'actor' => $actor ] );
	$hpve_rn( 'R2.4 approval after expiry re-verifies from today', (bool) get_post_meta( $vendor_a, 'hp_verified', true ) && $plus_year( $today ) === get_post_meta( $vendor_a, Hpve_Verification::META_UNTIL, true ) );

	echo "\n[R3] An admin ticking the box on a pending request adds one period, not two\n";

	list( $user_b, $vendor_b ) = $make_vendor( 'b' );
	$request_b = $component->get_or_create( $user_b, $vendor_b );
	$made['posts'][] = $request_b->get_id();
	$component->apply_event( $request_b, 'submit', [ 'consent' => true, 'start_provider' => false ] );

	$vendor_model( $vendor_b )->set_verified( true )->save_verified();
	$request_b = $component->get_request( $request_b->get_id() );

	$hpve_rn( 'R3.1 the tick approves the request', 'publish' === $request_b->get_status() );
	$hpve_rn( 'R3.2 one year from today', $plus_year( $today ) === get_post_meta( $vendor_b, Hpve_Verification::META_UNTIL, true ) );

	echo "\n[R4] A resubmission after an expiry gets its documents deleted again\n";

	update_post_meta( $vendor_b, Hpve_Verification::META_UNTIL, $in_days( -1 ) );
	$clock->expire_vendors();
	$request_b = $component->get_request( $request_b->get_id() );
	$request_b->fill( [ 'docs_deleted_time' => time() - DAY_IN_SECONDS ] )->save( [ 'docs_deleted_time' ] );

	$hpve_rn( 'R4.1 expired request carries the old deletion marker', (bool) $request_b->get_docs_deleted_time() && 'expired' === $request_b->get_outcome() );
	$component->apply_event( $request_b, 'submit', [ 'consent' => true, 'start_provider' => false ] );
	$hpve_rn( 'R4.2 submitting clears it', ! $component->get_request( $request_b->get_id() )->get_docs_deleted_time() );

	echo "\n[R5] No window when reminders are off\n";

	update_option( $prefix . 'reminder_days', '0' );
	update_post_meta( $vendor_a, Hpve_Verification::META_UNTIL, $in_days( 2 ) );
	$hpve_rn( 'R5.1 zero reminder days, no renewal window', false === $component->is_renewal_due( $vendor_a ) );
	update_option( $prefix . 'reminder_days', '7' );

	echo "\n[R6] The reminder email links to the Verification page\n";

	$hpve_rn_mail = [];
	update_post_meta( $vendor_a, Hpve_Verification::META_UNTIL, $in_days( 5 ) );
	delete_post_meta( $vendor_a, Hpve_Verification::META_REMINDED );
	$clock->remind_vendors();
	$sent = array_values( array_filter( $hpve_rn_mail, function ( $m ) { return 'hpve_rn_a@example.com' === ( is_array( $m['to'] ) ? reset( $m['to'] ) : $m['to'] ); } ) );
	$hpve_rn( 'R6.1 reminder sent', 1 === count( $sent ) );
	$hpve_rn( 'R6.2 it links to the account Verification page', $sent && false !== strpos( $sent[0]['message'], hivepress()->router->get_url( 'hpve_verification_page' ) ) );
	$hpve_rn( 'R6.3 it says the Vendor can renew now', $sent && false !== stripos( wp_strip_all_tags( $sent[0]['message'] ), 'renew it now' ) );

	echo "\n[R7] What the Vendor sees on the Verification page\n";

	list( $user_c, $vendor_c ) = $make_vendor( 'c' );
	$request_c = $component->get_or_create( $user_c, $vendor_c );
	$made['posts'][] = $request_c->get_id();
	$component->apply_event( $request_c, 'submit', [ 'consent' => true, 'start_provider' => false ] );
	$component->apply_event( $request_c, 'approve', [ 'actor' => $actor ] );
	update_post_meta( $vendor_c, Hpve_Verification::META_UNTIL, $in_days( 4 ) );

	wp_set_current_user( $user_c );
	$html = ( new \HivePress\Blocks\Hpve_Verification() )->render();
	$text = wp_strip_all_tags( $html );

	$hpve_rn( 'R7.1 the pill says Renewal due', false !== strpos( $text, 'Renewal due' ) );
	$hpve_rn( 'R7.2 it says the badge stays on', false !== strpos( $text, 'Your badge stays on' ) );
	$hpve_rn( 'R7.3 the upload form is open', false !== strpos( $html, '<form' ) && false !== strpos( $html, 'hpve' ) );
	$hpve_rn( 'R7.4 no em dash in what the Vendor reads', false === strpos( $text, "\u{2014}" ) );

	$hpve_rn( 'R7.6 no label says "(required)" any more (2.1.1)', false === strpos( $text, '(required)' ) );
	$hpve_rn( 'R7.7 the required Photo ID is not marked optional (2.1.1)', ! preg_match( '/Photo ID\s*\(optional\)/', $text ) );
	$hpve_rn( 'R7.8 optional documents still say (optional)', (bool) preg_match( '/Insurance certificate\s*\(optional\)/', $text ) );

	$component->maybe_start_renewal( $request_c );
	$html = ( new \HivePress\Blocks\Hpve_Verification() )->render();
	$text = wp_strip_all_tags( $html );
	$hpve_rn( 'R7.5 after the first action the pill says Renewing', false !== strpos( $text, 'Renewing' ) );
	$hpve_rn( 'R7.9 a renewal is sent with "Send for review", not "Send again" (2.1.1)', false !== strpos( $html, 'Send for review' ) && false === strpos( $html, 'Send again' ) );
	wp_set_current_user( 0 );
} finally {
	foreach ( array_reverse( $made['posts'] ) as $id ) {
		foreach ( get_children( [ 'post_parent' => $id, 'fields' => 'ids', 'post_type' => 'any', 'post_status' => 'any' ] ) as $child ) {
			wp_delete_post( $child, true );
		}
		wp_delete_post( $id, true );
	}

	foreach ( $made['users'] as $id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
	}

	foreach ( $snapshot as $name => $value ) {
		null === $value ? delete_option( $prefix . $name ) : update_option( $prefix . $name, $value );
	}

	echo "\nRESULT: {$hpve_rn_pass} passed, {$hpve_rn_fail} failed\n";
}
