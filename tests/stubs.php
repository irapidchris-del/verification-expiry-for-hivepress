<?php
/**
 * WordPress stubs for the logic tests.
 *
 * The classes under includes/logic/ make no WordPress calls; includes/providers/class-hpve-stripe-http.php
 * needs WP_Error and is_wp_error() and reads one option through get_option(). That is the whole surface
 * stubbed here, backed by arrays the tests set. Nothing here is asserted on.
 *
 * Env: HPVE_WC=absent -> WC_Stripe_API is not defined, so the fallback API version is used.
 *
 * @package Verification_Expiry\Tests
 */

// phpcs:ignoreFile -- test harness, excluded from the ruleset.

define( 'ABSPATH', __DIR__ . '/' );
define( 'HPVE_TEST_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'HPVE_TEST_WC', 'absent' !== getenv( 'HPVE_WC' ) );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['_options'] = [];

class WP_Error {
	protected $code;
	protected $message;
	protected $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}

	public function get_error_messages() {
		return [ $this->message ];
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['_options'] ) ? $GLOBALS['_options'][ $name ] : $default;
}

function wp_remote_request( $url, $args = [] ) {
	throw new RuntimeException( 'The tests must never reach the network: ' . $url );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['code'] ) ? $response['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

if ( HPVE_TEST_WC ) {
	class WC_Stripe_API {
		const STRIPE_API_VERSION = '2026-03-25.dahlia';
	}
}

require HPVE_TEST_PLUGIN_DIR . '/includes/logic/class-hpve-request-state.php';
require HPVE_TEST_PLUGIN_DIR . '/includes/logic/class-hpve-document-types.php';
require HPVE_TEST_PLUGIN_DIR . '/includes/logic/class-hpve-payment-rules.php';
require HPVE_TEST_PLUGIN_DIR . '/includes/logic/class-hpve-path.php';
require HPVE_TEST_PLUGIN_DIR . '/includes/logic/class-hpve-stripe-signature.php';
require HPVE_TEST_PLUGIN_DIR . '/includes/logic/class-hpve-stripe-mapper.php';
require HPVE_TEST_PLUGIN_DIR . '/includes/providers/class-hpve-stripe-http.php';

/**
 * One assertion helper with a static counter that hands the count back when asked, because a
 * file-scope counter read through "global" is a different variable under wp eval-file
 * (resources/testing-playbook.md, "A wp eval-file probe cannot count its own failures").
 *
 * @param string|null $label Label, or null to read the counts.
 * @param bool        $condition Condition.
 * @param string      $detail Extra detail on failure.
 * @return array|void
 */
function ok( $label, $condition = true, $detail = '' ) {
	static $passed = 0;
	static $failed = 0;

	if ( null === $label ) {
		return [ $passed, $failed ];
	}

	if ( $condition ) {
		++$passed;
		echo "  PASS  {$label}\n";
	} else {
		++$failed;
		echo "  FAIL  {$label}" . ( '' !== (string) $detail ? "  [{$detail}]" : '' ) . "\n";
	}
}

function eq( $actual, $expected, $label ) {
	ok( $label, $actual === $expected, 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

function fixture( $name ) {
	$json = file_get_contents( __DIR__ . '/fixtures/' . $name );

	return json_decode( $json, true );
}
