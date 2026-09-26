<?php
/**
 * Logic tests for Verification Expiry for HivePress 2.0.0.
 *
 * Plain PHP, no database, no network: the pure classes under includes/logic/ and the Stripe HTTP
 * client with a closure transport. Every table in design-final.md sections 4, 9.2 and 16.1 has a
 * row here. The first assertion is a deliberate failure the summary must report, so a run that
 * says everything passed without it is a run that cannot count.
 *
 * Usage: php tests/logic-tests.php   (or php tests/run.php for the whole matrix)
 *
 * @package Verification_Expiry\Tests
 */

// phpcs:ignoreFile -- test harness, excluded from the ruleset.

require __DIR__ . '/stubs.php';

use Verification_Expiry\Logic\Hpve_Request_State as State;
use Verification_Expiry\Logic\Hpve_Document_Types as Types;
use Verification_Expiry\Logic\Hpve_Payment_Rules as Rules;
use Verification_Expiry\Logic\Hpve_Path as Path;
use Verification_Expiry\Logic\Hpve_Stripe_Signature as Signature;
use Verification_Expiry\Logic\Hpve_Stripe_Mapper as Mapper;
use Verification_Expiry\Providers\Hpve_Stripe_Http as Http;

echo '=== Verification Expiry logic tests (WooCommerce Stripe gateway ' . ( HPVE_TEST_WC ? 'present' : 'ABSENT' ) . ") ===\n";

/* ===================== 0. the harness can fail ===================== */
echo "\n[0] self-test\n";
ok( '0.1 a deliberately failing assertion is counted (this line is EXPECTED to say FAIL)', false );

/* ===================== A. state machine ===================== */
echo "\n[A] Hpve_Request_State::transition\n";

eq( State::transition( '', '', 'create' ), [ 'draft', '' ], 'A1 create from nothing' );
eq( State::transition( 'draft', '', 'create' ), 'invalid_transition', 'A2 create twice refused' );
eq( State::transition( 'draft', '', 'submit' ), [ 'pending', '' ], 'A3 submit from draft' );
eq( State::transition( 'draft', 'needs_info', 'submit' ), [ 'pending', '' ], 'A4 submit clears needs_info' );
eq( State::transition( 'draft', 'rejected', 'submit' ), [ 'pending', '' ], 'A5 submit clears rejected' );
eq( State::transition( 'pending', '', 'submit' ), 'invalid_transition', 'A6 submit from pending refused' );
eq( State::transition( 'pending', '', 'needs_info' ), [ 'draft', 'needs_info' ], 'A7 needs_info from pending' );
eq( State::transition( 'pending', '', 'reject' ), [ 'draft', 'rejected' ], 'A8 reject from pending' );
eq( State::transition( 'pending', '', 'approve' ), [ 'publish', '' ], 'A9 approve from pending' );
eq( State::transition( 'draft', '', 'approve' ), 'invalid_transition', 'A10 approve from draft refused' );
eq( State::transition( 'publish', '', 'approve' ), 'invalid_transition', 'A11 approve twice refused' );
eq( State::transition( 'draft', 'needs_info', 'reopen' ), [ 'pending', '' ], 'A12 reopen from needs_info' );
eq( State::transition( 'draft', 'rejected', 'reopen' ), [ 'pending', '' ], 'A13 reopen from rejected' );
eq( State::transition( 'publish', '', 'reopen' ), [ 'pending', '' ], 'A14 reopen from publish' );
eq( State::transition( 'draft', '', 'reopen' ), 'invalid_transition', 'A15 reopen a fresh draft refused' );
eq( State::transition( 'publish', '', 'expire' ), [ 'draft', 'expired' ], 'A16 expire from publish' );
eq( State::transition( 'pending', '', 'expire' ), 'invalid_transition', 'A17 expire from pending refused' );
eq( State::transition( 'publish', '', 'revoke' ), [ 'draft', 'revoked' ], 'A18 revoke from publish' );
eq( State::transition( 'pending', '', 'provider_cancel' ), [ 'draft', 'cancelled' ], 'A19 provider_cancel from pending' );
eq( State::transition( 'draft', 'needs_info', 'trash' ), [ 'trash', 'needs_info' ], 'A20 trash keeps the outcome' );
eq( State::transition( 'trash', '', 'submit' ), 'trashed', 'A21 anything from trash refused' );
eq( State::transition( 'trash', '', 'paid' ), 'trashed', 'A22 record-only from trash refused' );
eq( State::transition( 'pending', '', 'paid' ), [ 'pending', '' ], 'A23 paid leaves the status alone' );
eq( State::transition( 'draft', 'rejected', 'refund' ), [ 'draft', 'rejected' ], 'A24 refund leaves the status and outcome alone' );
eq( State::transition( 'publish', '', 'renewal' ), [ 'publish', '' ], 'A25 renewal leaves publish alone' );
eq( State::transition( 'publish', '', 'renew' ), [ 'draft', 'renewal' ], 'A25a renew turns a verified request into a renewal draft' );
eq( State::transition( 'pending', '', 'renew' ), 'invalid_transition', 'A25b renew from pending refused' );
eq( State::transition( 'draft', 'expired', 'renew' ), 'invalid_transition', 'A25c renew after expiry refused (the expired form is the route)' );
eq( State::transition( 'draft', 'renewal', 'submit' ), [ 'pending', '' ], 'A25d a renewal draft submits like any draft' );
eq( State::transition( 'draft', 'renewal', 'expire' ), 'invalid_transition', 'A25e a renewal draft cannot expire again' );
eq( State::transition( 'draft', '', 'made_up' ), 'invalid_event', 'A26 unknown event' );
eq( State::transition( 'private', '', 'submit' ), 'invalid_status', 'A27 unknown status' );

/* ===================== B. card states ===================== */
echo "\n[B] card_state, menu_word, pill_modifier, form_open\n";

eq( State::card_state( [] ), 'no_vendor', 'B1 nothing at all' );
eq( State::card_state( [ 'has_vendor' => true ] ), 'not_started', 'B2 Vendor, no request' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => '' ] ), 'not_started', 'B3 fresh draft' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => '', 'payment_required' => true, 'paid' => false ] ), 'payment_needed', 'B4 payment required and unpaid' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => '', 'payment_required' => true, 'paid' => true ] ), 'not_started', 'B5 payment required and paid' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'pending', 'outcome' => '', 'provider' => 'manual' ] ), 'pending', 'B6 pending, manual' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'pending', 'outcome' => '', 'provider' => 'stripe_identity', 'provider_status' => 'requires_input' ] ), 'processing', 'B7 pending, Stripe requires_input' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'pending', 'outcome' => '', 'provider' => 'stripe_identity', 'provider_status' => 'processing' ] ), 'processing', 'B8 pending, Stripe processing' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'pending', 'outcome' => '', 'provider' => 'stripe_identity', 'provider_status' => 'verified' ] ), 'pending', 'B9 pending, Stripe verified awaiting sign-off' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'needs_info' ] ), 'needs_info', 'B10 needs_info' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'publish', 'outcome' => '' ] ), 'verified', 'B11 verified' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'rejected' ] ), 'rejected', 'B12 rejected' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'expired' ] ), 'expired', 'B13 expired' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'cancelled' ] ), 'cancelled', 'B14 cancelled' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'revoked' ] ), 'not_started', 'B15 revoked shows as not started' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'needs_info', 'payment_required' => true ] ), 'needs_info', 'B16 needs_info wins over payment' );
eq( State::card_state( [ 'has_vendor' => false, 'has_request' => false, 'status' => 'trash' ] ), 'no_vendor', 'B17 trashed request counts as none' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'publish', 'outcome' => '', 'renewal_due' => true ] ), 'renewal_due', 'B17a verified inside the window' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'publish', 'outcome' => '', 'renewal_due' => false ] ), 'verified', 'B17b verified outside the window' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'renewal', 'vendor_verified' => true ] ), 'renewing', 'B17c renewal draft, badge still on' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'renewal', 'vendor_verified' => false ] ), 'expired', 'B17d renewal draft after the old date passed' );
eq( State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => 'renewal', 'vendor_verified' => true, 'payment_required' => true, 'paid' => false ] ), 'renewing', 'B17e renewing is not re-labelled as payment needed' );

foreach ( [ 'pending' => 'pending', 'processing' => 'pending', 'needs_info' => 'action_needed', 'expired' => 'expired', 'renewal_due' => 'renewal_due', 'renewing' => '', 'verified' => '', 'not_started' => '', 'rejected' => '', 'payment_needed' => '', 'cancelled' => '', 'no_vendor' => '' ] as $state => $word ) {
	eq( State::menu_word( $state ), $word, "B18 menu_word({$state})" );
}

foreach ( [ 'not_started' => 'draft', 'payment_needed' => 'draft', 'needs_info' => 'draft', 'cancelled' => 'draft', 'pending' => 'pending', 'processing' => 'pending', 'verified' => 'publish', 'renewal_due' => 'publish', 'renewing' => 'publish', 'rejected' => 'error', 'expired' => 'trash', 'no_vendor' => '' ] as $state => $modifier ) {
	eq( State::pill_modifier( $state ), $modifier, "B19 pill_modifier({$state})" );
}

// The renewal window: from `days` before the date up to and including the date.
eq( State::renewal_due( '2026-10-10', '2026-10-02', 7 ), false, 'B19a the day before the window opens' );
eq( State::renewal_due( '2026-10-10', '2026-10-03', 7 ), true, 'B19b the first day of the window' );
eq( State::renewal_due( '2026-10-10', '2026-10-10', 7 ), true, 'B19c the expiry date itself' );
eq( State::renewal_due( '2026-10-10', '2026-10-11', 7 ), false, 'B19d the day after the date' );
eq( State::renewal_due( '2026-03-02', '2026-02-23', 7 ), true, 'B19e across a month end (February)' );
eq( State::renewal_due( '2026-10-10', '2026-10-05', 0 ), false, 'B19f a zero-day window never opens' );
eq( State::renewal_due( '', '2026-10-05', 7 ), false, 'B19g no date, nothing to renew' );
eq( State::renewal_due( 'soon', '2026-10-05', 7 ), false, 'B19h a malformed date is refused' );

foreach ( [ 'not_started' => true, 'needs_info' => true, 'rejected' => true, 'expired' => true, 'cancelled' => true, 'renewal_due' => true, 'renewing' => true, 'pending' => false, 'processing' => false, 'verified' => false, 'payment_needed' => false, 'no_vendor' => false ] as $state => $open ) {
	eq( State::form_open( $state, true ), $open, "B20 form_open({$state}) with documents" );
	eq( State::form_open( $state, false ), false, "B21 form_open({$state}) without documents" );
}

eq( State::owner_can_edit( 'draft' ), true, 'B22 owner edits a draft' );
eq( State::owner_can_edit( 'pending' ), false, 'B23 owner cannot edit pending' );

/* ===================== C. document types ===================== */
echo "\n[C] Hpve_Document_Types::normalise\n";

$defaults = Types::normalise( null );
eq( count( $defaults ), 4, 'C1 absent option gives the four defaults' );
eq( $defaults[0]['key'], 'photo_id', 'C2 first default is photo_id' );
eq( $defaults[0]['required'], true, 'C3 photo_id is required' );
eq( $defaults[1]['required'], false, 'C4 qualification is optional' );
eq( $defaults[0]['formats'], [ 'jpg', 'jpeg', 'png', 'webp', 'pdf' ], 'C5 default formats are the hard list' );
eq( Types::normalise( false ), $defaults, 'C6 false reads as absent' );
eq( Types::normalise( '' ), [], 'C7 stored-empty means no document types' );
eq( Types::normalise( 'garbage' ), [], 'C8 a non-array is nothing' );

$rows = Types::normalise(
	[
		[ 'key' => 'Photo-ID', 'label' => ' Photo ', 'enabled' => '1', 'required' => '', 'formats' => [ 'PDF', 'exe', 'heic', 'jpg' ], 'max_mb' => '80', 'max_files' => '9' ],
		[ 'key' => 'insurance', 'label' => '', 'enabled' => '', 'required' => '1', 'formats' => null, 'max_mb' => '', 'max_files' => null ],
		[ 'key' => 'x', 'label' => 'too short' ],
		[ 'key' => 'photo_id', 'label' => 'duplicate' ],
		'not a row',
		[ 'key' => 'zz', 'label' => 'Last', 'enabled' => '1', 'required' => '1', 'formats' => [], 'max_mb' => 'abc', 'max_files' => '0' ],
	]
);

eq( count( $rows ), 2, 'C9 disabled, invalid and duplicate rows are dropped' );
eq( $rows[0]['key'], 'photo_id', 'C10 key is sanitised (Photo-ID to photo_id)' );
eq( $rows[0]['label'], 'Photo', 'C11 label trimmed' );
eq( $rows[0]['required'], false, 'C12 stored-empty required reads as off' );
eq( $rows[0]['formats'], [ 'jpg', 'pdf' ], 'C13 exe and heic dropped, order follows the hard list' );
eq( $rows[0]['max_mb'], 50, 'C14 max_mb clamped to 50' );
eq( $rows[0]['max_files'], 5, 'C15 max_files clamped to 5' );
eq( $rows[1]['key'], 'zz', 'C16 order kept' );
eq( $rows[1]['max_mb'], 10, 'C17 non-numeric max_mb falls back to 10' );
eq( $rows[1]['max_files'], 1, 'C18 max_files 0 clamped to 1' );
eq( $rows[1]['formats'], [ 'jpg', 'jpeg', 'png', 'webp', 'pdf' ], 'C19 empty formats means every allowed format' );

$all = Types::normalise( [ [ 'key' => 'a1', 'label' => 'A', 'enabled' => '' ] ], true );
eq( count( $all ), 1, 'C20 include_disabled keeps a disabled row' );
eq( $all[0]['enabled'], false, 'C21 and reports it disabled' );
eq( Types::field_name( 'photo_id' ), 'hpve_doc_photo_id', 'C22 field name' );
eq( Types::key_from_field( 'hpve_doc_photo_id' ), 'photo_id', 'C23 key from field' );
eq( Types::key_from_field( 'images' ), '', 'C24 foreign field gives nothing' );
eq( Types::key_from_field( 'hpve_doc_' ), '', 'C25 empty key gives nothing' );
eq( Types::max_bytes( [ 'max_mb' => 10 ] ), 10485760, 'C26 max_bytes' );
eq( Types::find( $defaults, 'insurance' )['label'], 'Insurance certificate', 'C27 find by key' );
eq( Types::find( $defaults, 'nope' ), null, 'C28 find miss' );
eq( Types::is_valid_key( 'a_b1' ), true, 'C29 valid key' );
eq( Types::is_valid_key( 'A' ), false, 'C30 invalid key' );

/* ===================== D. payment rules ===================== */
echo "\n[D] Hpve_Payment_Rules\n";

$paid = fixture( 'wc-order-paid.json' );
$ref  = fixture( 'wc-order-refunded.json' );

eq( Rules::for_order( $paid['product_ids'], $paid['from'], $paid['to'], [ 301, 0 ] ), 'paid', 'D1 pending to processing with our product is paid' );
eq( Rules::for_order( [ 301 ], 'pending', 'completed', [ 301 ] ), 'paid', 'D2 pending to completed is paid' );
eq( Rules::for_order( [ 301 ], 'processing', 'completed', [ 301 ] ), 'ignore', 'D3 processing to completed is nothing' );
eq( Rules::for_order( [ 301 ], 'on-hold', 'processing', [ 301 ] ), 'paid', 'D4 on-hold to processing is paid' );
eq( Rules::for_order( $ref['product_ids'], $ref['from'], $ref['to'], [ 301 ] ), 'refund', 'D5 refunded is recorded' );
eq( Rules::for_order( [ 301 ], 'processing', 'cancelled', [ 301 ] ), 'cancel', 'D6 cancelled is recorded' );
eq( Rules::for_order( [ 301 ], 'pending', 'failed', [ 301 ] ), 'cancel', 'D7 failed is recorded' );
eq( Rules::for_order( [ 999 ], 'pending', 'processing', [ 301 ] ), 'ignore', 'D8 an order without our product is ignored' );
eq( Rules::for_order( [ 301 ], 'pending', 'processing', [] ), 'ignore', 'D9 no product configured is ignored' );
eq( Rules::for_order( [ 301 ], 'pending', 'processing', [ '', 0 ] ), 'ignore', 'D10 blank settings are ignored' );
eq( Rules::for_order( [ 302 ], 'pending', 'processing', [ 301, 302 ] ), 'paid', 'D11 the subscription product counts too' );
eq( Rules::for_order( [ 301 ], 'pending', 'on-hold', [ 301 ] ), 'ignore', 'D12 on-hold is nothing' );

eq( Rules::for_subscription( 'active', 'pending', true ), 'activate', 'D13 first activation' );
eq( Rules::for_subscription( 'active', 'on-hold', false ), 'reactivate', 'D14 reactivation' );
eq( Rules::for_subscription( 'on-hold', 'active', false ), 'on_hold', 'D15 on hold' );
eq( Rules::for_subscription( 'cancelled', 'active', false ), 'cancelled', 'D16 cancelled' );
eq( Rules::for_subscription( 'expired', 'active', false ), 'expired', 'D17 expired' );
eq( Rules::for_subscription( 'pending-cancel', 'active', false ), 'pending_cancel', 'D18 pending cancel' );
eq( Rules::for_subscription( 'switched', 'active', false ), 'ignore', 'D19 anything else' );
eq( Rules::for_renewal( true ), 'extend', 'D20 renewal with a verified Vendor extends' );
eq( Rules::for_renewal( false ), 'log', 'D21 renewal without only logs' );
eq( Rules::payment_state_for_event( 'subscription_on_hold' ), 'on_hold', 'D22 payment state map' );
eq( Rules::payment_state_for_event( 'approve' ), '', 'D23 payment state for a non-payment event' );

/* ===================== E. path containment ===================== */
echo "\n[E] Hpve_Path::is_inside\n";

eq( Path::is_inside( '/var/private/12/a.png', '/var/private' ), true, 'E1 plain child' );
eq( Path::is_inside( '/var/private/12/../../etc/passwd', '/var/private' ), false, 'E2 traversal' );
eq( Path::is_inside( '/var/private2/a.png', '/var/private' ), false, 'E3 prefix collision' );
eq( Path::is_inside( '/var/private', '/var/private' ), false, 'E4 the root itself is not inside' );
eq( Path::is_inside( '/var/private/', '/var/private/' ), false, 'E5 trailing separators' );
eq( Path::is_inside( '/var/private/12/', '/var/private/' ), true, 'E6 trailing separator on a child' );
eq( Path::is_inside( 'C:\\Sites\\app\\hpve-private-ab\\12\\a.png', 'C:/Sites/app/hpve-private-ab' ), true, 'E7 mixed separators' );
eq( Path::is_inside( 'c:/sites/APP/hpve-private-ab/12/a.png', 'C:/Sites/app/hpve-private-ab' ), true, 'E8 Windows paths compare case-insensitively' );
eq( Path::is_inside( '/var/PRIVATE/12/a.png', '/var/private' ), false, 'E9 POSIX paths are case-sensitive' );
eq( Path::is_inside( '', '/var/private' ), false, 'E10 empty path' );
eq( Path::is_inside( '/var/private/12/a.png', '' ), false, 'E11 empty root' );
eq( Path::normalise( '/a//b/./c/../d/' ), '/a/b/d', 'E12 normalise collapses dots and doubles' );

/* ===================== F. Stripe signature ===================== */
echo "\n[F] Hpve_Stripe_Signature::verify (fixtures signed at run time)\n";

$payload = '{"id":"evt_test_1","type":"identity.verification_session.verified"}';
$now     = 1757145600;
$secret  = 'whsec_test_dummy';

foreach ( fixture( 'stripe-signature-cases.json' ) as $case ) {
	$ts     = $now + (int) $case['offset'];
	$body   = $case['tamper'] ? $payload . 'x' : $payload;
	$parts  = [ 't=' . $ts ];
	$signed = Signature::sign( $payload, $secret, $ts );

	foreach ( $case['schemes'] as $scheme ) {
		if ( 'v1' === $scheme ) {
			$parts[] = 'v1=' . $signed;
		} elseif ( 'v1bad' === $scheme ) {
			$parts[] = 'v1=' . str_repeat( 'a', 64 );
		} else {
			$parts[] = 'v0=' . $signed;
		}
	}

	eq( Signature::verify( $body, implode( ',', $parts ), $case['secret'], $now ), $case['expected'], 'F ' . $case['name'] );
}

eq( Signature::verify( $payload, '', $secret, $now ), false, 'F empty header' );
eq( Signature::verify( $payload, 'garbage', $secret, $now ), false, 'F malformed header' );
eq( Signature::verify( $payload, 'v1=' . Signature::sign( $payload, $secret, $now ), $secret, $now ), false, 'F t missing' );
eq( Signature::verify( $payload, 't=abc,v1=' . Signature::sign( $payload, $secret, $now ), $secret, $now ), false, 'F t not numeric' );
eq( Signature::verify( $payload, 't=' . $now . ',v1=' . Signature::sign( $payload, $secret, $now ), $secret, $now, 0 ), true, 'F tolerance 0 still accepts the exact second' );

/* ===================== G. Stripe mapper ===================== */
echo "\n[G] Hpve_Stripe_Mapper::map\n";

eq( Mapper::map( 'verified', '', 1, 3, false ), [ 'action' => 'approve', 'note' => '' ], 'G1 verified approves' );
eq( Mapper::map( 'verified', '', 1, 3, true ), [ 'action' => 'hold_for_signoff', 'note' => '' ], 'G2 verified with sign-off holds' );
eq( Mapper::map( 'processing', '', 1, 3, false ), [ 'action' => 'pending', 'note' => '' ], 'G3 processing waits' );
eq( Mapper::map( 'requires_input', '', 1, 3, false ), [ 'action' => 'needs_info', 'note' => 'unfinished' ], 'G4 requires_input with no error is unfinished' );
eq( Mapper::map( 'requires_input', 'consent_declined', 1, 3, false ), [ 'action' => 'needs_info', 'note' => 'consent_declined' ], 'G5 consent declined offers the manual route' );

foreach ( Mapper::RETRYABLE as $code ) {
	eq( Mapper::map( 'requires_input', $code, 2, 3, false ), [ 'action' => 'needs_info', 'note' => 'stripe_reason' ], "G6 {$code} at 2 of 3 is needs_info" );
	eq( Mapper::map( 'requires_input', $code, 3, 3, false ), [ 'action' => 'reject', 'note' => 'stripe_reason' ], "G7 {$code} at 3 of 3 rejects" );
}

foreach ( Mapper::TERMINAL as $code ) {
	eq( Mapper::map( 'requires_input', $code, 1, 3, false ), [ 'action' => 'reject', 'note' => 'stripe_reason' ], "G8 {$code} rejects at once" );
}

eq( Mapper::map( 'requires_input', 'id_number_mismatch', 1, 3, false ), [ 'action' => 'needs_info', 'note' => 'stripe_reason' ], 'G9 id_number codes are needs_info' );
eq( Mapper::map( 'requires_input', 'address_mismatch', 3, 3, false ), [ 'action' => 'needs_info', 'note' => 'stripe_reason' ], 'G10 address_mismatch never rejects' );
eq( Mapper::map( 'requires_input', 'something_new', 1, 3, false ), [ 'action' => 'needs_info', 'note' => 'stripe_reason' ], 'G11 unknown code is needs_info' );
eq( Mapper::map( 'canceled', '', 1, 3, false ), [ 'action' => 'cancelled', 'note' => 'cancelled' ], 'G12 canceled' );
eq( Mapper::map( 'weird', '', 1, 3, false ), [ 'action' => 'needs_info', 'note' => 'unknown' ], 'G13 unknown status' );
eq( Mapper::map( 'requires_input', 'document_expired', 0, 0, false ), [ 'action' => 'needs_info', 'note' => 'stripe_reason' ], 'G14 limit floors at 1' );

echo "\n[G] Hpve_Stripe_Mapper::parse_event\n";

$ev = Mapper::parse_event( fixture( 'stripe-event-verified.json' ) );
ok( 'G15 verified event parses', is_array( $ev ) );
eq( $ev['event_id'], 'evt_testverified001', 'G16 event id' );
eq( $ev['session_id'], 'vs_test1AbC', 'G17 session id' );
eq( $ev['request_id'], 101, 'G18 request id from metadata' );
eq( $ev['livemode'], false, 'G19 livemode' );
eq( Mapper::parse_event( fixture( 'stripe-event-other-type.json' ) ), 'not_identity', 'G20 other event types ignored' );
ok( 'G21 unknown-session event still parses (the request lookup refuses it later)', is_array( Mapper::parse_event( fixture( 'stripe-event-unknown-session.json' ) ) ) );
eq( Mapper::parse_event( null ), 'not_object', 'G22 not JSON' );
eq( Mapper::parse_event( [ 'type' => 'identity.verification_session.verified', 'id' => 'bad', 'data' => [ 'object' => [ 'id' => 'vs_1' ] ] ] ), 'bad_event_id', 'G23 bad event id' );
eq( Mapper::parse_event( [ 'type' => 'identity.verification_session.verified', 'id' => 'evt_1', 'data' => [ 'object' => [ 'id' => 'pi_1' ] ] ] ), 'bad_session_id', 'G24 bad session id' );
eq( Mapper::parse_event( [ 'type' => 'identity.verification_session.verified', 'id' => 'evt_1', 'data' => [ 'object' => [ 'id' => 'vs_1', 'metadata' => [ 'hpve_request_id' => 'x' ] ] ] ] ), 'bad_request_id', 'G25 bad request id' );
eq( Mapper::is_session_id( 'vs_abc' ), true, 'G26 session id shape' );
eq( Mapper::is_session_id( 'vs_' ), false, 'G27 empty session id' );

/* ===================== H. Stripe HTTP ===================== */
echo "\n[H] Hpve_Stripe_Http\n";

$dummy_key = 'sk_test_dummykey_0123456789';
$calls     = [];

$transport = function ( $url, $args ) use ( &$calls ) {
	$calls[] = [ $url, $args ];

	if ( isset( $args['body'] ) && false !== strpos( $args['body'], 'fail=1' ) ) {
		return [ 'code' => 400, 'body' => wp_json_stub( [ 'error' => [ 'type' => 'invalid_request_error', 'code' => 'parameter_invalid', 'message' => 'Bad request with key ' . $GLOBALS['dummy_key'] ] ] ) ];
	}

	if ( false !== strpos( $url, 'vs_json_broken' ) ) {
		return [ 'code' => 200, 'body' => '{not json' ];
	}

	if ( false !== strpos( $url, 'vs_transport_error' ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error with ' . $GLOBALS['dummy_key'] );
	}

	return [ 'code' => 200, 'body' => wp_json_stub( fixture( 'stripe-session-verified.json' ) ) ];
};

function wp_json_stub( $data ) {
	return json_encode( $data );
}

$GLOBALS['dummy_key'] = $dummy_key;

$http = new Http( $transport, function () use ( $dummy_key ) { return $dummy_key; }, null, '2.0.0' );

list( $url, $args ) = $http->build_request( 'POST', 'identity/verification_sessions', [ 'type' => 'document', 'metadata' => [ 'hpve_request_id' => 101 ] ], 'hpve-101-1', $dummy_key );
eq( $url, 'https://api.stripe.com/v1/identity/verification_sessions', 'H1 create URL' );
eq( $args['method'], 'POST', 'H2 method' );
eq( $args['headers']['Authorization'], 'Bearer ' . $dummy_key, 'H3 bearer header' );
eq( $args['headers']['Idempotency-Key'], 'hpve-101-1', 'H4 idempotency key' );
eq( $args['headers']['Stripe-Version'], '2026-03-25.dahlia', 'H5 Stripe-Version (gateway constant or the pinned fallback)' );
eq( $args['user-agent'], 'verification-expiry-for-hivepress/2.0.0', 'H6 user agent names the plugin, not the site' );
eq( $args['timeout'], 15, 'H7 timeout' );
eq( $args['body'], 'type=document&metadata%5Bhpve_request_id%5D=101', 'H8 form-encoded body' );

list( $url, $args ) = $http->build_request( 'GET', 'identity/verification_sessions/vs_1', [], '', $dummy_key );
eq( $url, 'https://api.stripe.com/v1/identity/verification_sessions/vs_1', 'H9 retrieve URL' );
ok( 'H10 GET carries no body', ! isset( $args['body'] ) );
ok( 'H11 GET carries no idempotency key', ! isset( $args['headers']['Idempotency-Key'] ) );

list( $url ) = $http->build_request( 'POST', 'identity/verification_sessions/vs_1/redact', [], 'k', $dummy_key );
eq( $url, 'https://api.stripe.com/v1/identity/verification_sessions/vs_1/redact', 'H12 redact URL' );

$session = $http->retrieve_session( 'vs_test1AbC' );
ok( 'H13 retrieve decodes JSON', is_array( $session ) && 'verified' === $session['status'] );
eq( count( $calls ), 1, 'H14 exactly one transport call' );
eq( $calls[0][1]['method'], 'GET', 'H15 retrieve is a GET' );

$created = $http->create_session( [ 'type' => 'document' ], 'hpve-101-1' );
ok( 'H16 create decodes JSON', is_array( $created ) && isset( $created['id'] ) );

$broken = $http->retrieve_session( 'vs_json_broken' );
ok( 'H17 JSON decode error is a WP_Error', is_wp_error( $broken ) && 'hpve_stripe_invalid_json' === $broken->get_error_code() );

$failed = $http->create_session( [ 'fail' => 1 ], 'k' );
ok( 'H18 non-2xx is a WP_Error carrying Stripe\'s type and code', is_wp_error( $failed ) && 'hpve_stripe_api' === $failed->get_error_code() && 'parameter_invalid' === $failed->get_error_data()['code'] );
ok( 'H19 the key is masked in the API error message', false === strpos( $failed->get_error_message(), $dummy_key ) && false !== strpos( $failed->get_error_message(), '[key]' ) );

$transport_error = $http->retrieve_session( 'vs_transport_error' );
ok( 'H20 a transport WP_Error becomes hpve_stripe_transport', is_wp_error( $transport_error ) && 'hpve_stripe_transport' === $transport_error->get_error_code() );
ok( 'H21 the key is masked in the transport error', false === strpos( $transport_error->get_error_message(), $dummy_key ) );

$no_key = new Http( $transport, function () { return ''; }, null, '2.0.0' );
$result = $no_key->retrieve_session( 'vs_1' );
ok( 'H22 no key means no call and a WP_Error', is_wp_error( $result ) && 'hpve_stripe_no_key' === $result->get_error_code() && 5 === count( $calls ) );

foreach ( [ $failed, $transport_error, $broken, $result ] as $error ) {
	ok( 'H23 no error anywhere contains the key', false === strpos( serialize( [ $error->get_error_message(), $error->get_error_data(), $error->get_error_code() ] ), $dummy_key ) );
}

$GLOBALS['_options']['woocommerce_stripe_settings'] = [ 'testmode' => 'yes', 'test_secret_key' => 'sk_test_abcd1234', 'secret_key' => 'sk_live_wxyz9876' ];
$described = Http::describe_gateway_key();
eq( $described, [ 'present' => true, 'test' => true, 'last4' => '1234' ], 'H24 describe_gateway_key in test mode shows the last four only' );
$GLOBALS['_options']['woocommerce_stripe_settings']['testmode'] = 'no';
eq( Http::describe_gateway_key()['last4'], '9876', 'H25 live mode picks the live key' );
eq( ( new Http( $transport ) )->default_secret(), 'sk_live_wxyz9876', 'H26 default secret follows the gateway mode' );
$GLOBALS['_options']['woocommerce_stripe_settings'] = 'not an array';
eq( Http::describe_gateway_key(), [ 'present' => false, 'test' => false, 'last4' => '' ], 'H27 a broken option reads as no key' );

$fallback = new Http( $transport, null, null, '2.0.0' );
list( , $fallback_args ) = $fallback->build_request( 'GET', 'x', [], '', 'k' );
eq( $fallback_args['headers']['Stripe-Version'], HPVE_TEST_WC ? WC_Stripe_API::STRIPE_API_VERSION : Http::FALLBACK_API_VERSION, 'H28 API version comes from the gateway when present, else the fallback' );

/* ===================== I. copy checks ===================== */
echo "\n[I] copy and identity\n";

$plugin_dir = HPVE_TEST_PLUGIN_DIR;
$main       = file_get_contents( $plugin_dir . '/verification-expiry-for-hivepress.php' );
$readme     = file_get_contents( $plugin_dir . '/readme.txt' );

ok( 'I1 author credit byte for byte in the header', false !== strpos( $main, " * Author: ChrisB @ HivePress Community\n" ) );
ok( 'I2 author URI byte for byte in the header', false !== strpos( $main, " * Author URI: https://community.hivepress.io/u/chrisb/summary\n" ) );
ok( 'I3 readme contributor line', false !== strpos( $readme, 'Contributors: chrisb' ) );

preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $main, $header_version );
preg_match( "/define\( 'HPVE_VERSION', '([^']+)' \)/", $main, $constant_version );
preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $readme_version );
eq( trim( $header_version[1] ), '2.1.1', 'I4 header version' );
eq( $constant_version[1], '2.1.1', 'I5 constant version' );
eq( trim( $readme_version[1] ), '2.1.1', 'I6 readme stable tag' );
ok( 'I7 changelog entry landed', 1 === preg_match( '/^= 2\.0\.0 =/m', $readme ) );

$scan = [];

foreach ( [ 'php', 'txt', 'pot', 'js', 'css' ] as $ext ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		if ( $file->getExtension() === $ext && false === strpos( $file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
			$scan[ $file->getPathname() ] = file_get_contents( $file->getPathname() );
		}
	}
}

$em_dash  = [];
$business = [];

foreach ( $scan as $path => $content ) {
	if ( false !== strpos( $content, "\xE2\x80\x94" ) ) {
		$em_dash[] = basename( $path );
	}

	if ( preg_match( '/freestylr|anthropic|claude/i', $content ) ) {
		$business[] = basename( $path );
	}
}

eq( $em_dash, [], 'I8 no em-dash in any shipped file' );
eq( $business, [], 'I9 no business or person named in any shipped file' );

$email_files = glob( $plugin_dir . '/includes/emails/class-hpve-request-*.php' );
eq( count( $email_files ), 6, 'I10 six request emails' );

foreach ( $email_files as $file ) {
	$src = file_get_contents( $file );

	preg_match( "/'subject' => (?:sprintf\(\s*(?:\/\*.*?\*\/\s*)?)?esc_html__\( '([^']*)'/s", $src, $subject );
	ok( 'I11 ' . basename( $file ) . ' subject found and free of apostrophes', isset( $subject[1] ) && false === strpos( $subject[1], "'" ) && false === strpos( $subject[1], '&#039;' ) );

	preg_match( "/'tokens'\s*=>\s*\[([^\]]*)\]/", $src, $tokens_match );
	preg_match_all( "/'([a-z_]+)'/", isset( $tokens_match[1] ) ? $tokens_match[1] : '', $token_names );
	preg_match_all( "/'%([a-z_]+)%'/", $src, $used );

	$unknown = array_diff( array_unique( $used[1] ), $token_names[1] );
	eq( array_values( $unknown ), [], 'I12 ' . basename( $file ) . ' every %token% used is in the token list' );

	ok( 'I13 ' . basename( $file ) . ' no numbered token corruption', 0 === preg_match( '/%[0-9]+\$[a-z_]{2,}%/', $src ) );
}

$pot = file_get_contents( $plugin_dir . '/languages/verification-expiry-for-hivepress.pot' );
eq( substr_count( $pot, 'of the plugin' ), 1, 'I14 POT carries only the Description identity entry' );
ok( 'I15 POT bugs address is the repository', false !== strpos( $pot, 'github.com/irapidchris-del/verification-expiry-for-hivepress/issues' ) );

/* ===================== summary ===================== */
list( $passed, $failed ) = ok( null );

echo "\nRESULT: {$passed} passed, {$failed} failed (1 failure is the self-test and is expected)\n";

exit( 1 === $failed ? 0 : 1 );
