<?php
/**
 * Runtime check for Verification Expiry for HivePress 2.0.0, run with WP-CLI.
 *
 * Phases, chosen with HPVE_PHASE:
 *
 *   HPVE_PHASE=seed  wp eval-file tests/runtime-check.php                      creates users and a Vendor, prints their IDs
 *   HPVE_PHASE=check wp eval-file tests/runtime-check.php --user=<applicant>   every in-process assertion
 *   HPVE_PHASE=clean wp eval-file tests/runtime-check.php                      removes everything it made
 *
 * Nothing calls Stripe: pre_http_request short-circuits every outbound request and records it, and
 * the Stripe provider is given a fake transport that answers from tests/fixtures. No email is sent:
 * pre_wp_mail records what would have gone. The counter lives inside hpve_check() as a static,
 * because wp eval-file includes this file inside a function (resources/testing-playbook.md).
 *
 * @package Verification_Expiry\Tests
 */

// phpcs:ignoreFile -- test harness, excluded from the ruleset.

use HivePress\Models;
use Verification_Expiry\Logic\Hpve_Stripe_Signature as Signature;
use Verification_Expiry\Providers\Hpve_Stripe_Http;
use Verification_Expiry\Providers\Hpve_Provider_Stripe;

function hpve_check( $label, $condition = true, $detail = '' ) {
	static $fails  = 0;
	static $passes = 0;

	if ( is_null( $label ) ) {
		return [ $passes, $fails ];
	}

	if ( $condition ) {
		++$passes;
		echo "  PASS  {$label}\n";
	} else {
		++$fails;
		echo "  FAIL  {$label}" . ( '' !== (string) $detail ? "  [{$detail}]" : '' ) . "\n";
	}
}

function hpve_data( $response ) {
	$data = $response->get_data();

	return is_array( $data ) ? $data : (array) $data;
}

function hpve_fixture() {
	$fixture = get_option( 'hpve_test_fixture' );

	return is_array( $fixture ) ? $fixture : [];
}

function hpve_fixture_json( $name ) {
	return json_decode( file_get_contents( __DIR__ . '/fixtures/' . $name ), true );
}

function hpve_pending_actions( $hook ) {
	return as_get_scheduled_actions(
		[
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'group'    => 'hivepress',
			'per_page' => 50,
		],
		'ids'
	);
}

function hpve_run_pending( $hook ) {
	$runner = ActionScheduler::runner();
	$guard  = 0;

	while ( hpve_pending_actions( $hook ) && $guard < 10 ) {
		$runner->run( 'hpve-test' );

		++$guard;
	}
}

/**
 * A REST request the controller methods accept.
 */
function hpve_rest( $method, $params = [], $body = '', $headers = [] ) {
	$request = new WP_REST_Request( $method, '/hivepress/v1/hpve-verification' );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	if ( '' !== $body ) {
		$request->set_body( $body );
	}

	foreach ( $headers as $key => $value ) {
		$request->set_header( $key, $value );
	}

	return $request;
}

/**
 * Stages a fixture file as $_FILES['file'] and short-circuits move_uploaded_file(), which refuses
 * anything PHP itself did not receive as an upload.
 */
function hpve_stage_file( $name, $bytes, $mime ) {
	$tmp = wp_tempnam( $name );

	file_put_contents( $tmp, $bytes );

	$_FILES['file'] = [
		'name'     => $name,
		'type'     => $mime,
		'tmp_name' => $tmp,
		'error'    => 0,
		'size'     => strlen( $bytes ),
	];
}

function hpve_png_bytes() {
	// A 2x2 red PNG, valid for every sniffer.
	return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAD0lEQVQI12P4z8DAwMDAAAANEQEBUY+n2QAAAABJRU5ErkJggg==' );
}

function hpve_pdf_bytes() {
	return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n160\n%%EOF\n";
}

$phase = getenv( 'HPVE_PHASE' ) ? getenv( 'HPVE_PHASE' ) : 'check';

/*
 * --------------------------------------------------------------------------------------------------
 * seed
 * --------------------------------------------------------------------------------------------------
 */
if ( 'seed' === $phase ) {
	$applicant = wp_insert_user(
		[
			'user_login'   => 'hpve_test_applicant',
			'user_email'   => 'hpve_test_applicant@example.com',
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => 'HPVE Applicant',
			'role'         => 'contributor',
		]
	);

	$other = wp_insert_user(
		[
			'user_login'   => 'hpve_test_other',
			'user_email'   => 'hpve_test_other@example.com',
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => 'HPVE Other',
			'role'         => 'contributor',
		]
	);

	$editor = wp_insert_user(
		[
			'user_login'   => 'hpve_test_editor',
			'user_email'   => 'hpve_test_editor@example.com',
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => 'HPVE Editor',
			'role'         => 'editor',
		]
	);

	if ( is_wp_error( $applicant ) || is_wp_error( $other ) || is_wp_error( $editor ) ) {
		echo "seed failed: users (already seeded? run HPVE_PHASE=clean first)\n";
		exit( 1 );
	}

	$vendor_id = wp_insert_post(
		[
			'post_type'   => 'hp_vendor',
			'post_status' => 'publish',
			'post_title'  => 'HPVE Test Vendor',
			'post_name'   => 'hpve-test-vendor',
			'post_author' => $applicant,
		]
	);

	// A second Vendor for the "other" user so they too can hold a request.
	$other_vendor_id = wp_insert_post(
		[
			'post_type'   => 'hp_vendor',
			'post_status' => 'publish',
			'post_title'  => 'HPVE Other Vendor',
			'post_name'   => 'hpve-other-vendor',
			'post_author' => $other,
		]
	);

	// Settings snapshot, restored by clean.
	$snapshot = [];

	foreach ( [ 'provider', 'stripe_webhook_secret_test', 'stripe_attempt_limit', 'stripe_admin_signoff', 'doc_retention_days', 'product_id', 'subscription_product_id', 'paid_priority', 'payment_required', 'request_emails', 'request_admin_email', 'storage_dir', 'storage_mode', 'default_period' ] as $name ) {
		$snapshot[ $name ] = get_option( 'hp_' . HPVE_OPTION_PREFIX . $name, null );
	}

	update_option(
		'hpve_test_fixture',
		[
			'applicant'       => $applicant,
			'other'           => $other,
			'editor'          => $editor,
			'vendor_id'       => $vendor_id,
			'other_vendor_id' => $other_vendor_id,
			'snapshot'        => $snapshot,
			'stripe_settings' => get_option( 'woocommerce_stripe_settings', null ),
		],
		false
	);

	echo "APPLICANT_ID={$applicant}\nOTHER_ID={$other}\nEDITOR_ID={$editor}\nVENDOR_ID={$vendor_id}\n";

	exit( 0 );
}

/*
 * --------------------------------------------------------------------------------------------------
 * clean
 * --------------------------------------------------------------------------------------------------
 */
if ( 'clean' === $phase ) {
	$fixture = hpve_fixture();

	$private_dir = hivepress()->hpve_storage->get_dir();

	foreach ( [ 'applicant', 'other', 'editor' ] as $key ) {
		if ( ! empty( $fixture[ $key ] ) ) {
			foreach ( get_posts( [ 'post_type' => 'hp_hpve_request', 'post_status' => 'any', 'author' => $fixture[ $key ], 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $id ) {
				foreach ( hivepress()->hpve_request->get_documents( $id ) as $attachment ) {
					wp_delete_attachment( $attachment->get_id(), true );
				}

				wp_delete_post( $id, true );

				// Anything an earlier, broken run left behind in the request's folder.
				if ( $private_dir && is_dir( $private_dir . '/' . $id ) ) {
					foreach ( (array) glob( $private_dir . '/' . $id . '/*' ) as $leftover ) {
						unlink( $leftover );
					}

					rmdir( $private_dir . '/' . $id );
				}
			}
		}
	}

	foreach ( [ 'vendor_id', 'other_vendor_id', 'product_id', 'sub_product_id' ] as $key ) {
		if ( ! empty( $fixture[ $key ] ) ) {
			wp_delete_post( (int) $fixture[ $key ], true );
		}
	}

	foreach ( (array) ( isset( $fixture['orders'] ) ? $fixture['orders'] : [] ) as $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->delete( true );
		}
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	foreach ( [ 'applicant', 'other', 'editor' ] as $key ) {
		if ( ! empty( $fixture[ $key ] ) ) {
			wp_delete_user( (int) $fixture[ $key ] );
		}
	}

	if ( isset( $fixture['snapshot'] ) ) {
		foreach ( $fixture['snapshot'] as $name => $value ) {
			if ( null === $value ) {
				delete_option( 'hp_' . HPVE_OPTION_PREFIX . $name );
			} else {
				update_option( 'hp_' . HPVE_OPTION_PREFIX . $name, $value );
			}
		}
	}

	if ( array_key_exists( 'stripe_settings', $fixture ) ) {
		if ( null === $fixture['stripe_settings'] ) {
			delete_option( 'woocommerce_stripe_settings' );
		} else {
			update_option( 'woocommerce_stripe_settings', $fixture['stripe_settings'] );
		}
	}

	$scratch = wp_get_upload_dir()['basedir'] . '/hpve-private-runtimecheck';

	if ( is_dir( $scratch ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $scratch, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $scratch );
	}

	foreach ( [ 'hpve_stripe_create_session', 'hpve_stripe_sync', 'hpve_stripe_redact', 'hpve_retention' ] as $hook ) {
		as_unschedule_all_actions( $hook, [], 'hivepress' );
	}

	global $wpdb;

	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_hpve_%' OR option_name LIKE '_transient_timeout_hpve_%'" ) as $name ) {
		delete_option( $name );
	}

	delete_option( 'hpve_test_fixture' );

	echo "cleaned\n";

	exit( 0 );
}

/*
 * --------------------------------------------------------------------------------------------------
 * check
 * --------------------------------------------------------------------------------------------------
 */
$fixture = hpve_fixture();

if ( empty( $fixture['applicant'] ) ) {
	echo "run HPVE_PHASE=seed first\n";
	exit( 1 );
}

$applicant = (int) $fixture['applicant'];
$other     = (int) $fixture['other'];
$editor    = (int) $fixture['editor'];
$vendor_id = (int) $fixture['vendor_id'];

$component  = hivepress()->hpve_request;
$storage    = hivepress()->hpve_storage;
$controller = new \HivePress\Controllers\Hpve_Request();

// No network, ever.
$GLOBALS['hpve_http_calls'] = [];
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$GLOBALS['hpve_http_calls'][] = $url;

		return new WP_Error( 'hpve_test_blocked', 'blocked by the runtime check' );
	},
	1,
	3
);

// No email, ever; record what would have gone.
$GLOBALS['hpve_mail'] = [];
add_filter(
	'pre_wp_mail',
	function ( $pre, $atts ) {
		$GLOBALS['hpve_mail'][] = $atts;

		return true;
	},
	10,
	2
);

// A redirect from an admin-post handler is caught rather than followed. WP-CLI's own handler only
// prints a backtrace, so it is removed first.
remove_filter( 'wp_redirect', 'WP_CLI\Utils\wp_redirect_handler' );
add_filter(
	'wp_redirect',
	function ( $location ) {
		throw new RuntimeException( 'REDIRECT:' . $location );
	}
);

// move_uploaded_file() refuses files PHP did not receive as uploads; copy instead.
add_filter(
	'pre_move_uploaded_file',
	function ( $move, $file, $new_file ) {
		return copy( $file['tmp_name'], $new_file );
	},
	10,
	3
);

$last_mail = function () {
	$mail = end( $GLOBALS['hpve_mail'] );

	return $mail ? $mail : [ 'subject' => '', 'message' => '', 'to' => '' ];
};

wp_set_current_user( $applicant );

echo "\n[1] Activation, capabilities, routes\n";

$type = get_post_type_object( 'hp_hpve_request' );
hpve_check( '1.1 post type registered', (bool) $type );
hpve_check( '1.2 edit_posts maps to the review capability', $type && $type->cap->edit_posts === HPVE_REVIEW_CAP, $type ? $type->cap->edit_posts : '' );
hpve_check( '1.3 create_posts is do_not_allow', $type && 'do_not_allow' === $type->cap->create_posts );
hpve_check( '1.4 not shown in REST', $type && ! $type->show_in_rest );

$request = $component->get_or_create( $applicant, $vendor_id );
hpve_check( '1.5 a draft is created for the applicant', $request && 'draft' === $request->get_status() );
$request_id = (int) $request->get_id();
hpve_check( '1.6 the Vendor points at the request', (int) get_post_meta( $vendor_id, 'hp_hpve_request_id', true ) === $request_id );
hpve_check( '1.7 get_or_create is idempotent', $component->get_or_create( $applicant, $vendor_id )->get_id() === $request_id );
hpve_check( '1.8 the applicant cannot edit their own request in wp-admin', ! user_can( $applicant, 'edit_post', $request_id ) );
hpve_check( '1.9 an editor can', user_can( $editor, 'edit_post', $request_id ) );
hpve_check( '1.10 another contributor cannot', ! user_can( $other, 'edit_post', $request_id ) );

$router = hivepress()->router;
foreach ( [ 'hpve_verification_page', 'hpve_verification_return_page', 'hpve_verification_buy_page', 'hpve_document_view_page', 'hpve_document_upload_action', 'hpve_request_submit_action', 'hpve_stripe_webhook_action' ] as $route ) {
	hpve_check( "1.11 route {$route} registered", (bool) $router->get_route( $route ) );
}

$rules = $GLOBALS['wp_rewrite']->wp_rewrite_rules();
$rule_keys = implode( "\n", array_keys( (array) $rules ) );
hpve_check( '1.12 rewrite rule for /account/verification', false !== strpos( $rule_keys, 'account/verification/?$' ) );
hpve_check( '1.13 rewrite rule for /verification-document/{id}', false !== strpos( $rule_keys, 'verification-document/(?P<attachment_id>' ) );
hpve_check( '1.14 the gate URL is a front-end route', false !== strpos( $storage->get_gate_url( 1 ), '/verification-document/1' ) );
hpve_check( '1.15 the account menu carries the item', isset( ( new \HivePress\Menus\User_Account() )->get_items()['hpve_verification'] ) );
hpve_check( '1.16 the log has the created line', 1 === count( array_filter( $component->get_log( $request_id ), function ( $l ) { return 'created' === $l->get_action(); } ) ) );

echo "\n[2] Storage and the upload route\n";

$dir  = $storage->get_dir();
$mode = $storage->get_mode();
hpve_check( '2.1 a private folder was resolved (' . $mode . ')', '' !== $dir && is_dir( $dir ), $dir );
hpve_check( '2.2 .htaccess and index.php written', file_exists( $dir . '/.htaccess' ) && file_exists( $dir . '/index.php' ) );
hpve_check( '2.3 mode stored', in_array( get_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_mode' ), [ 'external', 'uploads' ], true ) );

$uploads_root = wp_normalize_path( wp_get_upload_dir()['basedir'] );
hpve_check( '2.4 external mode means outside uploads and outside ABSPATH', 'external' !== $mode || ( 0 !== strpos( wp_normalize_path( $dir ), $uploads_root ) && 0 !== strpos( wp_normalize_path( $dir ), untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/' ) ) );

hpve_stage_file( 'passport photo.png', hpve_png_bytes(), 'image/png' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id', 'render' => true ] ) );
hpve_check( '2.5 owner upload of a PNG returns 201', 201 === $response->get_status(), wp_json_encode( $response->get_data() ) );
$png_id = 201 === $response->get_status() ? (int) $response->get_data()['data']['id'] : 0;
hpve_check( '2.6 the rendered row links through the gate and not to a file', $png_id && false !== strpos( $response->get_data()['data']['html'], '/verification-document/' . $png_id ) && false === strpos( $response->get_data()['data']['html'], 'wp-content/uploads' ) );

$file = get_attached_file( $png_id );
hpve_check( '2.7 get_attached_file() resolves to a real file', $png_id && $file && is_file( $file ), (string) $file );
hpve_check( '2.8 the file sits under {dir}/{request_id}/', $file && 0 === strpos( wp_normalize_path( realpath( $file ) ), wp_normalize_path( realpath( $dir ) ) . '/' . $request_id . '/' ), wp_normalize_path( (string) $file ) );
hpve_check( '2.9 the request folder has its own index.php', file_exists( $dir . '/' . $request_id . '/index.php' ) );
$meta = wp_get_attachment_metadata( $png_id );
hpve_check( '2.10 no thumbnails were generated', empty( $meta['sizes'] ) );
hpve_check( '2.11 no -scaled copy', ! glob( $dir . '/' . $request_id . '/*-scaled.*' ) );
hpve_check( '2.12 no file landed in the uploads folder', ! glob( $uploads_root . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ) . '/*passport-photo*' ) );
hpve_check( '2.13 wp_get_attachment_url() is the gate', wp_get_attachment_url( $png_id ) === $storage->get_gate_url( $png_id ) );
$src = wp_get_attachment_image_src( $png_id, 'thumbnail' );
hpve_check( '2.14 wp_get_attachment_image_src() is the gate for every size', $src && $src[0] === $storage->get_gate_url( $png_id ) );
hpve_check( '2.15 the guid is the gate', get_post_field( 'guid', $png_id ) === $storage->get_gate_url( $png_id ) );
hpve_check( '2.16 wp_get_attachment_image() carries no real path', false === strpos( wp_get_attachment_image( $png_id, 'full' ), 'hpve-private' ) );
hpve_check( '2.17 the private marker and size are stored', '1' === get_post_meta( $png_id, 'hp_hpve_private', true ) && absint( get_post_meta( $png_id, 'hp_hpve_doc_size', true ) ) === strlen( hpve_png_bytes() ) );
hpve_check( '2.18 the original name is the title', 'passport-photo.png' === get_the_title( $png_id ) || 'passport photo.png' === get_the_title( $png_id ), get_the_title( $png_id ) );
hpve_check( '2.19 the stored file name is randomised', false === strpos( basename( $file ), 'passport photo' ) && 1 === preg_match( '/^[a-z0-9]{12}-/', basename( $file ) ) , basename( $file ) );
hpve_check( '2.20 attachment linked to the request the HivePress way', 'hpve_request' === get_post_meta( $png_id, 'hp_parent_model', true ) && (int) get_post_field( 'post_parent', $png_id ) === $request_id );

hpve_stage_file( 'insurance.pdf', hpve_pdf_bytes(), 'application/pdf' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_insurance' ] ) );
hpve_check( '2.21 a PDF uploads too', 201 === $response->get_status(), wp_json_encode( $response->get_data() ) );
$pdf_id = 201 === $response->get_status() ? (int) $response->get_data()['data']['id'] : 0;
$pdf_meta = wp_get_attachment_metadata( $pdf_id );
hpve_check( '2.22 no PDF preview images', empty( $pdf_meta['sizes'] ) );

hpve_stage_file( 'evil.php', "<?php echo 1;", 'application/x-php' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
hpve_check( '2.23 a PHP file is refused (400)', 400 === $response->get_status() );

hpve_stage_file( 'notes.txt', 'hello', 'text/plain' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
hpve_check( '2.24 a text file is refused (400)', 400 === $response->get_status() );

hpve_stage_file( 'x.png', hpve_png_bytes(), 'image/png' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'images' ] ) );
hpve_check( '2.25 a foreign field is refused (400)', 400 === $response->get_status() );

$_FILES['file']['size'] = 500 * 1024 * 1024;
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
hpve_check( '2.26 an oversized file is refused (400)', 400 === $response->get_status() );

wp_set_current_user( $other );
hpve_stage_file( 'x.png', hpve_png_bytes(), 'image/png' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
hpve_check( '2.27 another user cannot upload to this request (403)', 403 === $response->get_status() );
$response = $controller->delete_document( hpve_rest( 'DELETE', [ 'attachment_id' => $png_id ] ) );
hpve_check( '2.28 another user cannot delete a document (403)', 403 === $response->get_status() );
wp_set_current_user( 0 );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
hpve_check( '2.29 a guest gets 401', 401 === $response->get_status() );
wp_set_current_user( $applicant );

// Media library exclusion for a non-reviewer, inclusion for a reviewer.
$args = hivepress()->hpve_storage->exclude_from_library_ajax( [] );
hpve_check( '2.30 the media modal query excludes private files for a contributor', ! empty( $args['meta_query'] ) );
wp_set_current_user( $editor );
hpve_check( '2.31 and not for a reviewer', empty( hivepress()->hpve_storage->exclude_from_library_ajax( [] )['meta_query'] ) );
wp_set_current_user( $applicant );

hpve_check( '2.32 get_real_path() refuses an attachment outside the private folder', '' === $storage->get_real_path( 1 ) );

// Deletion unlinks the file.
$second_png = null;
hpve_stage_file( 'second.png', hpve_png_bytes(), 'image/png' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
$second_png = 201 === $response->get_status() ? (int) $response->get_data()['data']['id'] : 0;
$second_path = get_attached_file( $second_png );
$response = $controller->delete_document( hpve_rest( 'DELETE', [ 'attachment_id' => $second_png ] ) );
hpve_check( '2.33 the owner can delete a document while the request is a draft (204)', 204 === $response->get_status() );
hpve_check( '2.34 the file is gone from disk (' . $mode . ' mode)', $second_path && ! file_exists( $second_path ) );
hpve_check( '2.35 the row is gone too', null === get_post( $second_png ) );

// Uploads mode, forced into a scratch folder.
$original_dir  = get_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_dir' );
$original_mode = get_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_mode' );
$scratch       = $uploads_root . '/hpve-private-runtimecheck';
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_dir', $scratch, false );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_mode', 'uploads', false );
$storage->reset();
hpve_check( '2.36 uploads mode resolves to the scratch folder', $storage->get_dir() === $scratch && 'uploads' === $storage->get_mode(), $storage->get_dir() );
hpve_stage_file( 'uploads mode.png', hpve_png_bytes(), 'image/png' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_proof_of_address' ] ) );
$up_id = 201 === $response->get_status() ? (int) $response->get_data()['data']['id'] : 0;
$up_meta = get_post_meta( $up_id, '_wp_attached_file', true );
// On Linux the stored path is relative to uploads; on Windows the uploads folder carries backslashes
// that never match the forward-slash path, so WordPress stores it absolute. Both resolve (2.38).
hpve_check( '2.37 uploads mode stores a path under the scratch folder', $up_id && false !== strpos( $up_meta, 'hpve-private-runtimecheck/' . $request_id . '/' ), (string) $up_meta );
$up_file = get_attached_file( $up_id );
hpve_check( '2.38 and get_attached_file() resolves it', $up_file && is_file( $up_file ) );
hpve_check( '2.39 uploads mode URL is still the gate', wp_get_attachment_url( $up_id ) === $storage->get_gate_url( $up_id ) );
$response = $controller->delete_document( hpve_rest( 'DELETE', [ 'attachment_id' => $up_id ] ) );
hpve_check( '2.40 delete in uploads mode unlinks the file', 204 === $response->get_status() && ! file_exists( $up_file ) );
hpve_check( '2.41 the emptied request folder is removed', ! is_dir( $scratch . '/' . $request_id ) );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_dir', $original_dir, false );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'storage_mode', $original_mode, false );
$storage->reset();
hpve_check( '2.42 back to the original folder', $storage->get_dir() === $original_dir );

// A normal upload after ours lands in the usual place.
$normal = wp_upload_bits( 'hpve-normal.png', null, hpve_png_bytes() );
hpve_check( '2.43 a normal upload after ours lands in the uploads folder', empty( $normal['error'] ) && 0 === strpos( wp_normalize_path( $normal['file'] ), $uploads_root . '/' ) );
if ( empty( $normal['error'] ) ) {
	unlink( $normal['file'] );
}

echo "\n[3] Submit and the applicant emails\n";

$GLOBALS['hpve_mail'] = [];
$response = $controller->submit_request( hpve_rest( 'POST', [ 'request_id' => $request_id ] ) );
hpve_check( '3.1 submit without consent is refused (400)', 400 === $response->get_status() );

$response = $controller->submit_request( hpve_rest( 'POST', [ 'request_id' => $request_id, 'consent' => '1' ] ) );
hpve_check( '3.2 submit with consent and the required document returns 200', 200 === $response->get_status(), wp_json_encode( $response->get_data() ) );
$request = $component->get_request( $request_id );
hpve_check( '3.3 the request is pending', 'pending' === $request->get_status() );
hpve_check( '3.4 submitted_time, consent_time and attempts recorded', $request->get_submitted_time() > 0 && $request->get_consent_time() > 0 && 1 === (int) $request->get_attempts() );
hpve_check( '3.5 provider recorded as manual', 'manual' === $request->get_provider() );
hpve_check( '3.6 card state is pending', 'pending' === $component->get_card_state( $request, Models\Vendor::query()->get_by_id( $vendor_id ) ) );

$subjects = array_map( function ( $m ) { return $m['subject']; }, $GLOBALS['hpve_mail'] );
hpve_check( '3.7 applicant Submitted email queued', in_array( 'We have received your verification documents', $subjects, true ), implode( ' | ', $subjects ) );
hpve_check( '3.8 admin Received email queued to admin_email', (bool) array_filter( $GLOBALS['hpve_mail'], function ( $m ) { return false !== strpos( $m['subject'], 'New verification request from HPVE Test Vendor' ) && $m['to'] === get_option( 'admin_email' ); } ) );
hpve_check( '3.9 no unresolved %token% in any body', ! array_filter( $GLOBALS['hpve_mail'], function ( $m ) { return 1 === preg_match( '/%[a-z_0-9$]+%/', $m['message'] ); } ) );
hpve_check( '3.10 the Submitted body carries the review note and link', (bool) array_filter( $GLOBALS['hpve_mail'], function ( $m ) { return false !== strpos( $m['message'], 'working days' ) && false !== strpos( $m['message'], '/account/verification' ); } ) );

hpve_stage_file( 'late.png', hpve_png_bytes(), 'image/png' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
hpve_check( '3.11 the owner cannot upload to a pending request (403)', 403 === $response->get_status() );
$response = $controller->delete_document( hpve_rest( 'DELETE', [ 'attachment_id' => $png_id ] ) );
hpve_check( '3.12 nor delete from it (403)', 403 === $response->get_status() );
$response = $controller->submit_request( hpve_rest( 'POST', [ 'request_id' => $request_id, 'consent' => '1' ] ) );
hpve_check( '3.13 nor submit it twice (403)', 403 === $response->get_status() );

// The account page renders for the applicant (--user= made hivepress()->request->get_user() real).
$html = ( new \HivePress\Blocks\Hpve_Verification() )->render();
hpve_check( '3.14 the block renders the pending card', false !== strpos( $html, 'hpve-card--pending' ) && false !== strpos( $html, 'hp-status--pending' ) );
hpve_check( '3.15 the pending card lists the documents through the gate', false !== strpos( $html, '/verification-document/' . $png_id ) );
hpve_check( '3.16 the block output carries no real file path', false === strpos( $html, 'hpve-private-' ) );
hpve_check( '3.17 the CTA is hidden while pending', '' === ( new \HivePress\Blocks\Hpve_Verification_Cta() )->render() );

echo "\n[4] Review\n";

wp_set_current_user( $editor );
$review = hivepress()->hpve_review;

$do_review = function ( $decision, $post = [] ) use ( $review, $request_id, $editor ) {
	$_REQUEST = [ 'request_id' => $request_id, 'decision' => $decision, '_wpnonce' => wp_create_nonce( 'hpve_review_' . $request_id ) ];
	$_POST    = $post;
	$_SERVER['HTTP_REFERER'] = admin_url( 'post.php?post=' . $request_id . '&action=edit' );

	try {
		$review->handle_review();
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}

	return 'no redirect';
};

$GLOBALS['hpve_mail'] = [];
$result = $do_review( 'needs_info', [ 'hpve_note' => "Please send the back of the card too.\n<b>bold</b>" ] );
$request = $component->get_request( $request_id );
hpve_check( '4.1 needs_info through the admin-post handler redirects with the notice', false !== strpos( $result, 'hpve_notice=needs_info' ), $result );
hpve_check( '4.2 the request is back to draft with outcome needs_info', 'draft' === $request->get_status() && 'needs_info' === $request->get_outcome() );
hpve_check( '4.3 the note is stored stripped of tags', false === strpos( (string) $request->get_note(), '<b>' ) && false !== strpos( (string) $request->get_note(), 'back of the card' ) );
hpve_check( '4.4 reviewer recorded', (int) $request->get_reviewer__id() === $editor );
hpve_check( '4.5 Needs Info email queued with the note', (bool) array_filter( $GLOBALS['hpve_mail'], function ( $m ) { return 'Your verification needs more information' === $m['subject'] && false !== strpos( $m['message'], 'back of the card' ); } ) );
hpve_check( '4.6 card state needs_info', 'needs_info' === $component->get_card_state( $request, null ) );

wp_set_current_user( $applicant );
$html = ( new \HivePress\Blocks\Hpve_Verification() )->render();
hpve_check( '4.7 the applicant sees the note and the form', false !== strpos( $html, 'back of the card' ) && false !== strpos( $html, 'hp-form--hpve-request-submit' ) && false !== strpos( $html, 'data-id="' . $request_id . '"' ) );
hpve_check( '4.8 the form button says Send again', false !== strpos( $html, 'Send again' ) );
$response = $controller->submit_request( hpve_rest( 'POST', [ 'request_id' => $request_id, 'consent' => '1', 'applicant_note' => 'Here is the back.' ] ) );
$request = $component->get_request( $request_id );
hpve_check( '4.9 resubmission moves back to pending and clears the note', 200 === $response->get_status() && 'pending' === $request->get_status() && '' === (string) $request->get_note() && 2 === (int) $request->get_attempts() );
hpve_check( '4.10 the applicant note was saved', 'Here is the back.' === $request->get_applicant_note() );

wp_set_current_user( $editor );
$result = $do_review( 'reject', [ 'hpve_reason' => '' ] );
hpve_check( '4.11 reject without a reason is refused', false !== strpos( $result, 'reason_required' ), $result );
$GLOBALS['hpve_mail'] = [];
$result = $do_review( 'reject', [ 'hpve_reason' => 'The name on the card does not match.' ] );
$request = $component->get_request( $request_id );
hpve_check( '4.12 reject with a reason', false !== strpos( $result, 'hpve_notice=rejected' ) && 'draft' === $request->get_status() && 'rejected' === $request->get_outcome() );
hpve_check( '4.13 Rejected email queued with the reason', (bool) array_filter( $GLOBALS['hpve_mail'], function ( $m ) { return 'Your verification was not approved' === $m['subject'] && false !== strpos( $m['message'], 'does not match' ); } ) );
hpve_check( '4.14 the Vendor is still not verified', ! get_post_meta( $vendor_id, 'hp_verified', true ) );

$result = $do_review( 'reopen' );
$request = $component->get_request( $request_id );
hpve_check( '4.15 reopen moves back to pending', 'pending' === $request->get_status() && '' === (string) $request->get_outcome() );

$result = $do_review( 'approve' );
$request = $component->get_request( $request_id );
hpve_check( '4.16 approve through the handler', false !== strpos( $result, 'hpve_notice=approved' ), $result );
hpve_check( '4.17 the request is published', 'publish' === $request->get_status() );
hpve_check( '4.18 the Verified box is ticked', (bool) get_post_meta( $vendor_id, 'hp_verified', true ) );
hpve_check( '4.19 1.x wrote META_UNTIL (period ' . hivepress()->hpve_verification->resolve_period( $vendor_id ) . ')', '' === hivepress()->hpve_verification->resolve_period( $vendor_id ) || '' !== (string) get_post_meta( $vendor_id, \HivePress\Components\Hpve_Verification::META_UNTIL, true ) );
hpve_check( '4.20 the log has an approved line naming the editor', (bool) array_filter( $component->get_log( $request_id ), function ( $l ) { return 'approved' === $l->get_action() && false !== strpos( $l->get_text(), 'HPVE Editor' ); } ) );
hpve_check( '4.21 card state verified', 'verified' === $component->get_card_state( $request, Models\Vendor::query()->get_by_id( $vendor_id ) ) );

$result = $do_review( 'approve' );
hpve_check( '4.22 approving twice is refused as invalid', false !== strpos( $result, 'hpve_notice=invalid' ), $result );

// Expiry hand-off: back-date the date and run the hourly job.
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'default_period', 'month' );
update_post_meta( $vendor_id, \HivePress\Components\Hpve_Verification::META_UNTIL, '2020-01-01' );
hivepress()->hpve_verification->expire_vendors();
$request = $component->get_request( $request_id );
hpve_check( '4.23 expire_vendors() unticked the box', ! get_post_meta( $vendor_id, 'hp_verified', true ) );
hpve_check( '4.24 and the request moved to expired', 'draft' === $request->get_status() && 'expired' === $request->get_outcome() );
wp_set_current_user( $applicant );
hpve_check( '4.25 the CTA shows again after expiry', false !== strpos( ( new \HivePress\Blocks\Hpve_Verification_Cta() )->render(), 'has expired' ) );
wp_set_current_user( $editor );

// Hand tick with a pending request approves it.
$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
$vendor = Models\Vendor::query()->get_by_id( $vendor_id );
$vendor->set_verified( true )->save_verified();
$request = $component->get_request( $request_id );
hpve_check( '4.26 ticking the Verified box by hand approves a pending request', 'publish' === $request->get_status() );
hpve_check( '4.27 and the log says how', (bool) array_filter( $component->get_log( $request_id ), function ( $l ) { return 'approved' === $l->get_action() && false !== strpos( $l->get_text(), 'ticking the Verified box' ); } ) );

// Hand untick logs revoked.
delete_post_meta( $vendor_id, \HivePress\Components\Hpve_Verification::META_EXPIRED );
$vendor = Models\Vendor::query()->get_by_id( $vendor_id );
$vendor->set_verified( false )->save_verified();
$request = $component->get_request( $request_id );
hpve_check( '4.28 unticking by hand logs revoked and shows as not started', 'draft' === $request->get_status() && 'revoked' === $request->get_outcome() && 'not_started' === $component->get_card_state( $request, $vendor ) );

// A stray status write is logged.
wp_update_post( [ 'ID' => $request_id, 'post_status' => 'pending' ] );
hpve_check( '4.29 a status write outside apply_event() is logged as status_changed', (bool) array_filter( $component->get_log( $request_id ), function ( $l ) { return 'status_changed' === $l->get_action(); } ) );
wp_update_post( [ 'ID' => $request_id, 'post_status' => 'draft' ] );
$request = $component->get_request( $request_id );

// Bulk approve counts.
wp_set_current_user( $editor );
$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
$other_request = $component->get_or_create( $other, (int) $fixture['other_vendor_id'] );
$redirect = $review->handle_bulk_actions( admin_url( 'edit.php' ), 'hpve_approve', [ $request_id, $other_request->get_id(), 999999 ] );
hpve_check( '4.30 bulk approve counts one approved and two skipped', false !== strpos( $redirect, 'hpve_approved=1' ) && false !== strpos( $redirect, 'hpve_skipped=2' ), $redirect );
hpve_check( '4.31 bulk approve ticked the box', (bool) get_post_meta( $vendor_id, 'hp_verified', true ) );

// Vendors column second line and the review list columns render without notices.
ob_start();
hivepress()->hpve_verification->render_admin_columns( 'hpve_verification', $vendor_id );
$column = ob_get_clean();
hpve_check( '4.32 the Vendors column renders', false !== strpos( $column, 'Verified' ) );
ob_start();
$review->render_column( 'hpve_status', $request_id );
$review->render_column( 'hpve_documents', $request_id );
$review->render_column( 'hpve_paid', $request_id );
$review->render_column( 'hpve_submitted', $request_id );
$review->render_column( 'hpve_reviewer', $request_id );
$columns = ob_get_clean();
hpve_check( '4.33 the queue columns render (Approved, types, Free)', false !== strpos( $columns, 'Approved' ) && false !== strpos( $columns, 'types' ) && false !== strpos( $columns, 'Free' ) );

$GLOBALS['post'] = get_post( $request_id );
ob_start();
foreach ( [ 'decision', 'documents', 'history', 'applicant', 'payment' ] as $part ) {
	$review->render_box( get_post( $request_id ), [ 'args' => [ 'part' => $part ] ] );
}
$boxes = ob_get_clean();
hpve_check( '4.34 the five review boxes render', false !== strpos( $boxes, 'Re-open for review' ) && false !== strpos( $boxes, 'passport' ) && false !== strpos( $boxes, 'HPVE Applicant' ) && false !== strpos( $boxes, 'Free' ) );
hpve_check( '4.35 the review boxes carry no real file path', false === strpos( $boxes, 'hpve-private-' ) );

echo "\n[5] WooCommerce\n";

$payment = hivepress()->hpve_payment;
$product = new WC_Product_Simple();
$product->set_name( 'HPVE Verification' );
$product->set_regular_price( '9.99' );
$product->set_virtual( true );
$product->set_status( 'publish' );
$product_id = $product->save();
$fixture['product_id'] = $product_id;
update_option( 'hpve_test_fixture', $fixture, false );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'product_id', $product_id );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'paid_priority', '1' );
hpve_check( '5.1 has_product() sees the product', $payment->has_product() );
hpve_check( '5.2 the price text is plain', false !== strpos( $payment->get_price_text(), '9.99' ) && false === strpos( $payment->get_price_text(), '&' ), $payment->get_price_text() );

// Untick the Vendor so the paid flow starts from a draft.
$vendor = Models\Vendor::query()->get_by_id( $vendor_id );
$vendor->set_verified( false )->save_verified();
$request = $component->get_request( $request_id );
$component->apply_event( $request, 'reopen' );

$GLOBALS['hpve_mail'] = [];
$order = wc_create_order( [ 'customer_id' => $other ] );
$order->add_product( wc_get_product( $product_id ), 1 );
$order->calculate_totals();
$order->save();
$fixture['orders'][] = $order->get_id();
update_option( 'hpve_test_fixture', $fixture, false );
$order->update_status( 'processing' );
$other_request = $component->get_for_user( $other );
hpve_check( '5.3 a processing order marks the buyer\'s request paid', $other_request && $other_request->is_paid() && (int) $other_request->get_order_id() === $order->get_id() );
hpve_check( '5.4 paid first: menu_order 1', 1 === (int) get_post_field( 'menu_order', $other_request->get_id() ) );
hpve_check( '5.5 the order carries the request id', (int) wc_get_order( $order->get_id() )->get_meta( '_hpve_request_id' ) === (int) $other_request->get_id() );
hpve_check( '5.6 the Paid email went to the buyer', (bool) array_filter( $GLOBALS['hpve_mail'], function ( $m ) { return 'Thanks for your payment, now send your documents' === $m['subject'] && 'hpve_test_other@example.com' === $m['to']; } ) );
hpve_check( '5.7 the log has the paid line', (bool) array_filter( $component->get_log( $other_request->get_id() ), function ( $l ) { return 'paid' === $l->get_action() && 'WooCommerce' === $l->get_author(); } ) );
$before = count( $component->get_log( $other_request->get_id() ) );
wc_get_order( $order->get_id() )->update_status( 'completed' );
hpve_check( '5.8 processing to completed is a no-op', count( $component->get_log( $other_request->get_id() ) ) === $before );
hpve_check( '5.9 payment never approves', 'draft' === $component->get_request( $other_request->get_id() )->get_status() );
wc_get_order( $order->get_id() )->update_status( 'refunded' );
$other_request = $component->get_request( $other_request->get_id() );
hpve_check( '5.10 a refund is recorded and keeps paid', 'refunded' === $other_request->get_payment_state() && $other_request->is_paid() );

// Payment required blocks uploads and submit for an unpaid draft.
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'payment_required', '1' );
wp_set_current_user( $applicant );
hpve_stage_file( 'x.png', hpve_png_bytes(), 'image/png' );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
$request = $component->get_request( $request_id );
$component->apply_event( $request, 'needs_info', [ 'note' => 'x' ] );
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $request_id, 'parent_field' => 'hpve_doc_photo_id' ] ) );
hpve_check( '5.11 payment required refuses an unpaid upload (403)', 403 === $response->get_status() );
hpve_check( '5.12 card state is payment_needed for a fresh unpaid draft', 'payment_needed' === \Verification_Expiry\Logic\Hpve_Request_State::card_state( [ 'has_request' => true, 'status' => 'draft', 'outcome' => '', 'payment_required' => true, 'paid' => false ] ) );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'payment_required', '' );

// Subscription renewal restarts the clock for a verified Vendor only.
if ( function_exists( 'wcs_create_subscription' ) ) {
	$sub_product = new WC_Product_Subscription();
	$sub_product->set_name( 'HPVE Subscription' );
	$sub_product->set_regular_price( '4.99' );
	$sub_product->set_virtual( true );
	$sub_product->set_status( 'publish' );
	$sub_product->update_meta_data( '_subscription_period', 'month' );
	$sub_product->update_meta_data( '_subscription_period_interval', '1' );
	$sub_product->update_meta_data( '_subscription_price', '4.99' );
	$sub_product_id = $sub_product->save();
	$fixture['sub_product_id'] = $sub_product_id;
	update_option( 'hpve_test_fixture', $fixture, false );
	update_option( 'hp_' . HPVE_OPTION_PREFIX . 'subscription_product_id', $sub_product_id );

	$parent_order = wc_create_order( [ 'customer_id' => $applicant ] );
	$parent_order->add_product( wc_get_product( $sub_product_id ), 1 );
	$parent_order->calculate_totals();
	$parent_order->save();
	$fixture['orders'][] = $parent_order->get_id();

	$subscription = wcs_create_subscription(
		[
			'order_id'         => $parent_order->get_id(),
			'customer_id'      => $applicant,
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'status'           => 'pending',
		]
	);

	if ( ! is_wp_error( $subscription ) ) {
		$subscription->add_product( wc_get_product( $sub_product_id ), 1 );
		$subscription->calculate_totals();
		$subscription->save();
		$fixture['orders'][] = $subscription->get_id();
		update_option( 'hpve_test_fixture', $fixture, false );

		$subscription->update_status( 'active' );
		$request = $component->get_request( $request_id );
		hpve_check( '5.13 subscription activation marks the request paid with the subscription id', $request->is_paid() && (int) $request->get_subscription_id() === $subscription->get_id() );
		hpve_check( '5.14 the Vendor carries the subscription id', (int) get_post_meta( $vendor_id, 'hp_hpve_subscription_id', true ) === $subscription->get_id() );

		// Not verified: renewal only logs.
		$vendor = Models\Vendor::query()->get_by_id( $vendor_id );
		if ( $vendor->is_verified() ) {
			$vendor->set_verified( false )->save_verified();
		}
		$payment->renew_subscription( $subscription, $parent_order );
		hpve_check( '5.15 renewal for an unverified Vendor only logs', (bool) array_filter( $component->get_log( $request_id ), function ( $l ) { return 'renewal' === $l->get_action() && false !== strpos( $l->get_text(), 'nothing to extend' ); } ) );

		// Verified with an old date: renewal restarts the clock from today.
		$request = $component->get_request( $request_id );
		$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
		$component->apply_event( $request, 'approve', [ 'actor' => [ 'name' => 'Test', 'user_id' => $editor ] ] );
		update_post_meta( $vendor_id, \HivePress\Components\Hpve_Verification::META_UNTIL, '2027-01-01' );
		$payment->renew_subscription( $subscription, $parent_order );
		$until = (string) get_post_meta( $vendor_id, \HivePress\Components\Hpve_Verification::META_UNTIL, true );
		hpve_check( '5.16 renewal restarts the clock from today with the Vendor\'s period', $until === hivepress()->hpve_verification->calculate_until( 'month' ), $until );
		hpve_check( '5.17 the log says until when', (bool) array_filter( $component->get_log( $request_id ), function ( $l ) { return 'renewal' === $l->get_action() && false !== strpos( $l->get_text(), 'verified until' ); } ) );

		$subscription->update_status( 'on-hold' );
		hpve_check( '5.18 on hold is recorded and the badge stays', 'on_hold' === $component->get_request( $request_id )->get_payment_state() && get_post_meta( $vendor_id, 'hp_verified', true ) );
	} else {
		hpve_check( '5.13 wcs_create_subscription()', false, $subscription->get_error_message() );
	}
} else {
	echo "  SKIP  5.13-5.18 WooCommerce Subscriptions absent\n";
}

echo "\n[6] Stripe Identity (fake transport, no network)\n";

if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
	// The gateway is inactive on this site; the provider gates on the class, so stand one in.
	class WC_Stripe_Helper {}
}

update_option( 'woocommerce_stripe_settings', [ 'testmode' => 'yes', 'test_secret_key' => 'sk_test_runtimecheck_dummy', 'secret_key' => '' ] );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'stripe_webhook_secret_test', 'whsec_test_dummy' );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'provider', 'stripe_identity' );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'stripe_attempt_limit', '3' );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'stripe_admin_signoff', '' );

if ( $GLOBALS['hpve_http_calls'] ) {
	echo '  NOTE  outbound calls blocked before the Stripe phase (WooCommerce and friends): ' . implode( ', ', array_unique( $GLOBALS['hpve_http_calls'] ) ) . "
";
}

$GLOBALS['hpve_http_calls']   = [];
$GLOBALS['hpve_stripe_calls'] = [];
$GLOBALS['hpve_stripe_fixture'] = 'stripe-session-requires-input-no-error.json';

$transport = function ( $url, $args ) {
	$GLOBALS['hpve_stripe_calls'][] = [ $url, $args['method'] ];

	return [ 'code' => 200, 'body' => wp_json_encode( hpve_fixture_json( $GLOBALS['hpve_stripe_fixture'] ) ) ];
};

$http = new Hpve_Stripe_Http( $transport, null, null, HPVE_VERSION );
hivepress()->hpve_provider->set_provider( new Hpve_Provider_Stripe( $http ) );

hpve_check( '6.1 the Stripe provider is configured with the stub gateway and the test secret', hivepress()->hpve_provider->get_provider( 'stripe_identity' )->is_configured() );
hpve_check( '6.2 and is the active provider', 'stripe_identity' === hivepress()->hpve_provider->get_active_name() );
hpve_check( '6.3 the gateway description masks the key', false !== strpos( hivepress()->hpve_provider->get_provider( 'stripe_identity' )->describe_gateway(), 'ends ummy' ) && false === strpos( hivepress()->hpve_provider->get_provider( 'stripe_identity' )->describe_gateway(), 'sk_test_runtimecheck' ) );

// Start from a fresh draft on the applicant's request.
$vendor = Models\Vendor::query()->get_by_id( $vendor_id );
if ( $vendor->is_verified() ) {
	$vendor->set_verified( false )->save_verified();
}
$request = $component->get_request( $request_id );
if ( 'draft' !== $request->get_status() ) {
	$component->apply_event( $request, 'reopen' );
	$component->apply_event( $request, 'needs_info', [ 'note' => 'reset for the Stripe phase' ] );
}
wp_set_current_user( $applicant );
delete_transient( 'hpve_rate_' . $applicant );

$started = microtime( true );
$response = $controller->start_request( hpve_rest( 'POST', [ 'request_id' => $request_id ] ) );
$held = microtime( true ) - $started;
hpve_check( '6.4 the start route answers 202', 202 === $response->get_status(), wp_json_encode( $response->get_data() ) );
// The property guarded here is "the route only queues": a real Stripe call would hold the request
// for seconds (15 s timeout). Wall-clock on this shared machine measured 0.39 s, 0.69 s and 0.48 s
// across three runs of the same code (2026-09-06), so a tight cap only reports machine load; 6.6 is
// the assertion that nothing went out. The number is printed so a regression is still visible.
hpve_check( '6.5 and holds the request under 2 s, queue only (' . round( $held, 3 ) . ' s)', $held < 2 );
hpve_check( '6.6 no outbound HTTP on the request', [] === $GLOBALS['hpve_http_calls'] && [] === $GLOBALS['hpve_stripe_calls'] );
$request = $component->get_request( $request_id );
hpve_check( '6.7 the request is pending with provider stripe_identity and attempt 1', 'pending' === $request->get_status() && 'stripe_identity' === $request->get_provider() && 1 === (int) $request->get_provider_attempts() );
hpve_check( '6.8 a create-session job is queued with [id, attempt]', (bool) hpve_pending_actions( 'hpve_stripe_create_session' ) );
$response = $controller->start_request( hpve_rest( 'POST', [ 'request_id' => $request_id ] ) );
hpve_check( '6.9 a second start is refused (pending, and rate limited)', in_array( $response->get_status(), [ 403, 429 ], true ) );
$response = $controller->redirect_request( hpve_rest( 'GET', [ 'request_id' => $request_id ] ) );
hpve_check( '6.10 the redirect route has nothing yet', 200 === $response->get_status() && empty( hpve_data( $response )['data'] ) );

hpve_run_pending( 'hpve_stripe_create_session' );
hpve_check( '6.11 the job called Stripe once through the fake transport (create)', 1 === count( $GLOBALS['hpve_stripe_calls'] ) && 'POST' === $GLOBALS['hpve_stripe_calls'][0][1] );
hpve_check( '6.12 and nothing reached the network', [] === $GLOBALS['hpve_http_calls'] );
$request = $component->get_request( $request_id );
hpve_check( '6.13 provider_ref stored, livemode false', 'vs_test1AbC' === $request->get_provider_ref() && ! $request->is_provider_livemode() );
$response = $controller->redirect_request( hpve_rest( 'GET', [ 'request_id' => $request_id ] ) );
hpve_check( '6.14 the redirect route hands the URL over once', 200 === $response->get_status() && ! empty( hpve_data( $response )['data']['url'] ) );
$response = $controller->redirect_request( hpve_rest( 'GET', [ 'request_id' => $request_id ] ) );
hpve_check( '6.15 and not twice', empty( hpve_data( $response )['data'] ) );
hpve_check( '6.16 the session URL is never stored on the request', false === strpos( serialize( get_post_meta( $request_id ) ), 'verify.stripe.com' ) );
hpve_check( '6.17 the card state is processing', 'processing' === $component->get_card_state( $request, null ) );

wp_set_current_user( $other );
$response = $controller->redirect_request( hpve_rest( 'GET', [ 'request_id' => $request_id ] ) );
hpve_check( '6.18 another user cannot poll the redirect (403)', 403 === $response->get_status() );
wp_set_current_user( 0 );

// Webhook.
$event_fixture = hpve_fixture_json( 'stripe-event-verified.json' );
$event_fixture['data']['object']['metadata']['hpve_request_id'] = (string) $request_id;
$body    = wp_json_encode( $event_fixture );
$ts      = time();
$sig     = 't=' . $ts . ',v1=' . Signature::sign( $body, 'whsec_test_dummy', $ts );
$webhook = function ( $body, $header ) use ( $controller ) {
	return $controller->handle_webhook( hpve_rest( 'POST', [], $body, [ 'stripe-signature' => $header ] ) );
};

hpve_check( '6.19 webhook without a signature is 400', 400 === $webhook( $body, '' )->get_status() );
hpve_check( '6.20 webhook with a bad signature is 403', 403 === $webhook( $body, 't=' . $ts . ',v1=' . str_repeat( 'a', 64 ) )->get_status() );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'stripe_webhook_secret_test', '' );
hpve_check( '6.21 webhook with no secret configured is 503', 503 === $webhook( $body, $sig )->get_status() );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'stripe_webhook_secret_test', 'whsec_test_dummy' );
$other_body = wp_json_encode( hpve_fixture_json( 'stripe-event-other-type.json' ) );
hpve_check( '6.22 an unrelated event type is acknowledged (200) and queues nothing', 200 === $webhook( $other_body, 't=' . $ts . ',v1=' . Signature::sign( $other_body, 'whsec_test_dummy', $ts ) )->get_status() && ! hpve_pending_actions( 'hpve_stripe_sync' ) );
$unknown_body = wp_json_encode( hpve_fixture_json( 'stripe-event-unknown-session.json' ) );
hpve_check( '6.23 an event for a session this request does not hold is ignored (200)', 200 === $webhook( $unknown_body, 't=' . $ts . ',v1=' . Signature::sign( $unknown_body, 'whsec_test_dummy', $ts ) )->get_status() && ! hpve_pending_actions( 'hpve_stripe_sync' ) );
$response = $webhook( $body, $sig );
hpve_check( '6.24 a valid verified event is 200 and queues a sync', 200 === $response->get_status() && 1 === count( hpve_pending_actions( 'hpve_stripe_sync' ) ) );
hpve_check( '6.25 the same event again is deduped', 200 === $webhook( $body, $sig )->get_status() && 1 === count( hpve_pending_actions( 'hpve_stripe_sync' ) ) );
hpve_check( '6.26 the webhook made no outbound call and changed nothing', 'pending' === $component->get_request( $request_id )->get_status() && 1 === count( $GLOBALS['hpve_stripe_calls'] ) );

// Sync: the job re-fetches the session, never trusting the event body.
$GLOBALS['hpve_stripe_fixture'] = 'stripe-session-verified.json';
hpve_run_pending( 'hpve_stripe_sync' );
$request = $component->get_request( $request_id );
hpve_check( '6.27 the sync job retrieved the session (GET)', 'GET' === end( $GLOBALS['hpve_stripe_calls'] )[1] );
hpve_check( '6.28 verified approves the request automatically', 'publish' === $request->get_status() && (bool) get_post_meta( $vendor_id, 'hp_verified', true ) );
hpve_check( '6.29 the approval line names Stripe Identity', (bool) array_filter( $component->get_log( $request_id ), function ( $l ) { return 'approved' === $l->get_action() && 'Stripe Identity' === $l->get_author(); } ) );

// A replayed event after approval cannot roll it back.
$GLOBALS['hpve_stripe_fixture'] = 'stripe-session-requires-input-document-expired.json';
hivepress()->hpve_provider->run_sync( $request_id, 'evt_replay' );
hpve_check( '6.30 a later sync on an approved request changes nothing', 'publish' === $component->get_request( $request_id )->get_status() );

// Livemode mismatch is refused.
$vendor = Models\Vendor::query()->get_by_id( $vendor_id );
$vendor->set_verified( false )->save_verified();
$request = $component->get_request( $request_id );
$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
$GLOBALS['hpve_stripe_fixture'] = 'stripe-session-livemode-true.json';
hivepress()->hpve_provider->run_sync( $request_id, 'evt_live' );
hpve_check( '6.31 a live-mode result on a test-mode session is ignored', 'pending' === $component->get_request( $request_id )->get_status() && (bool) array_filter( $component->get_log( $request_id ), function ( $l ) { return false !== strpos( $l->get_text(), 'other mode' ); } ) );

// Mapping through the provider: document_expired at 1 of 3 is needs_info with Stripe's reason.
$GLOBALS['hpve_stripe_fixture'] = 'stripe-session-requires-input-document-expired.json';
hivepress()->hpve_provider->run_sync( $request_id, 'evt_expired' );
$request = $component->get_request( $request_id );
hpve_check( '6.32 document_expired maps to needs_info with Stripe\'s reason', 'draft' === $request->get_status() && 'needs_info' === $request->get_outcome() && false !== strpos( (string) $request->get_note(), 'expired' ) );

// consent_declined offers the manual route; the form opens when documents are collected too.
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'doc_collect_with_provider', '1' );
$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
$GLOBALS['hpve_stripe_fixture'] = 'stripe-session-requires-input-consent-declined.json';
hivepress()->hpve_provider->run_sync( $request_id, 'evt_consent' );
$request = $component->get_request( $request_id );
hpve_check( '6.33 consent_declined maps to needs_info offering the document route', 'needs_info' === $request->get_outcome() && false !== strpos( (string) $request->get_note(), 'documents' ) );
wp_set_current_user( $applicant );
$html = ( new \HivePress\Blocks\Hpve_Verification() )->render();
hpve_check( '6.34 the card offers Stripe AND the document form', false !== strpos( $html, 'data-hpve-start' ) && false !== strpos( $html, 'hp-form--hpve-request-submit' ) );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'doc_collect_with_provider', '' );

// canceled.
$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
$GLOBALS['hpve_stripe_fixture'] = 'stripe-session-canceled.json';
hivepress()->hpve_provider->run_sync( $request_id, 'evt_cancel' );
hpve_check( '6.35 canceled maps to cancelled', 'cancelled' === $component->get_request( $request_id )->get_outcome() );

// Sign-off holds a verified result.
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'stripe_admin_signoff', '1' );
$GLOBALS['hpve_mail'] = [];
$request = $component->get_request( $request_id );
$component->apply_event( $request, 'submit', [ 'consent' => true, 'start_provider' => false ] );
$GLOBALS['hpve_stripe_fixture'] = 'stripe-session-verified.json';
hivepress()->hpve_provider->run_sync( $request_id, 'evt_signoff' );
$request = $component->get_request( $request_id );
hpve_check( '6.36 with sign-off on, verified stays pending', 'pending' === $request->get_status() && ! get_post_meta( $vendor_id, 'hp_verified', true ) );
hpve_check( '6.37 and the Signoff email goes to the site address', (bool) array_filter( $GLOBALS['hpve_mail'], function ( $m ) { return 'A verified request is waiting for your approval' === $m['subject'] && $m['to'] === get_option( 'admin_email' ); } ) );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'stripe_admin_signoff', '' );

hpve_check( '6.38 nothing in the whole Stripe phase reached the network', [] === $GLOBALS['hpve_http_calls'] );
hpve_check( '6.39 no log line, meta or email contains the key', false === strpos( serialize( [ get_post_meta( $request_id ), array_map( function ( $l ) { return $l->get_text(); }, $component->get_log( $request_id ) ), $GLOBALS['hpve_mail'] ] ), 'sk_test_runtimecheck' ) );

// Back to manual for the remaining phases.
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'provider', 'manual' );

echo "\n[7] Retention and privacy tools\n";

wp_set_current_user( $editor );
$request = $component->get_request( $request_id );
$component->apply_event( $request, 'needs_info', [ 'note' => 'housekeeping' ] );
$request = $component->get_request( $request_id );
$request->set_reviewed_time( time() - 40 * DAY_IN_SECONDS )->save( [ 'reviewed_time' ] );
update_option( 'hp_' . HPVE_OPTION_PREFIX . 'doc_retention_days', '30' );
$paths = array_map( function ( $a ) { return get_attached_file( $a->get_id() ); }, $component->get_documents( $request_id ) );
hpve_check( '7.1 the request still holds documents', count( $paths ) >= 2 );
$component->queue_retention();
hpve_check( '7.2 the daily event queues the first batch', (bool) hpve_pending_actions( 'hpve_retention' ) );
hpve_run_pending( 'hpve_retention' );
$request = $component->get_request( $request_id );
hpve_check( '7.3 retention deleted the documents', [] === $component->get_documents( $request_id ) && ! array_filter( $paths, 'file_exists' ) );
hpve_check( '7.4 docs_deleted_time set and the applicant-visible line written', $request->get_docs_deleted_time() > 0 && (bool) array_filter( $component->get_log( $request_id, true ), function ( $l ) { return 'documents_deleted' === $l->get_action(); } ) );
hpve_check( '7.5 the request folder is gone', ! is_dir( $storage->get_dir() . '/' . $request_id ) );

$export = $component->export_personal_data( 'hpve_test_applicant@example.com' );
hpve_check( '7.6 the exporter lists the request', ! empty( $export['data'] ) && 'hpve-request-' . $request_id === $export['data'][0]['item_id'] );

hpve_stage_file( 'erase me.png', hpve_png_bytes(), 'image/png' );
wp_set_current_user( $other );
$other_request = $component->get_for_user( $other );
$component->apply_event( $other_request, 'needs_info', [ 'note' => 'x' ] ) ;
$response = $controller->upload_document( hpve_rest( 'POST', [ 'parent' => $other_request->get_id(), 'parent_field' => 'hpve_doc_photo_id' ] ) );
$erase_path = 201 === $response->get_status() ? get_attached_file( (int) $response->get_data()['data']['id'] ) : '';
$erase = $component->erase_personal_data( 'hpve_test_other@example.com' );
hpve_check( '7.7 the eraser removes the request, its file and the Vendor pointer', $erase['items_removed'] && null === $component->get_for_user( $other ) && ( '' === $erase_path || ! file_exists( $erase_path ) ) && '' === (string) get_post_meta( (int) $fixture['other_vendor_id'], 'hp_hpve_request_id', true ) );

echo "\n[8] Settings tab and templates\n";

$settings = hivepress()->get_config( 'settings' );
$sections = $settings['verification_expiry']['sections'];
foreach ( [ 'requests', 'documents', 'payment', 'provider', 'removal' ] as $section ) {
	$key = HPVE_OPTION_PREFIX . $section;
	hpve_check( "8.1 section {$section} has a title, description and fields", isset( $sections[ $key ]['title'], $sections[ $key ]['description'] ) && ! empty( $sections[ $key ]['fields'] ) );

	foreach ( (array) $sections[ $key ]['fields'] as $name => $field ) {
		hpve_check( "8.2 field {$name} has a tooltip", ! empty( $field['description'] ) || ! empty( $field['caption'] ) );
	}
}
hpve_check( '8.3 the Documents description states the storage mode', false !== strpos( $sections[ HPVE_OPTION_PREFIX . 'documents' ]['description'], 'Documents are stored' ) );
hpve_check( '8.4 no em-dash in any settings string', false === strpos( wp_json_encode( $sections ), "—" ) );

$blocks = hivepress()->get_classes( 'blocks' );
hpve_check( '8.5 the Verification block registers (label) and the CTA does not', $blocks['hpve_verification']::get_meta( 'label' ) && ! $blocks['hpve_verification_cta']::get_meta( 'label' ) );
hpve_check( '8.6 shortcode registered', shortcode_exists( 'hivepress_hpve_verification' ) );
hpve_check( '8.7 the six new emails are registered', 6 === count( array_intersect_key( hivepress()->get_classes( 'emails' ), array_flip( [ 'hpve_request_submitted', 'hpve_request_received', 'hpve_request_needs_info', 'hpve_request_rejected', 'hpve_request_paid', 'hpve_request_signoff' ] ) ) ) );
hpve_check( '8.8 the field type is hidden from the attribute list', ! \HivePress\Fields\Hpve_Document_Upload::get_meta( 'label' ) );
hpve_check( '8.9 templates carry labels', \HivePress\Templates\Hpve_Verification_Page::get_meta( 'label' ) && \HivePress\Templates\Hpve_Verification_Return_Page::get_meta( 'label' ) );

wp_set_current_user( 0 );
$guest = ( new \HivePress\Blocks\Hpve_Verification() )->render();
hpve_check( '8.10 a guest sees the sign-in card', false !== strpos( $guest, 'Sign in to start your verification' ) );

list( $passes, $fails ) = hpve_check( null );

echo "\nRESULT: {$passes} passed, {$fails} failed\n";

exit( $fails ? 1 : 0 );
