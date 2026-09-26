<?php
/**
 * Runs the logic tests twice: with the WooCommerce Stripe gateway present and absent.
 *
 * The gateway's presence decides which Stripe-Version header the HTTP client sends, and nothing
 * else in the pure classes depends on the site, so two runs cover the matrix.
 *
 * Usage: php tests/run.php
 *
 * @package Verification_Expiry\Tests
 */

// phpcs:ignoreFile -- test harness, excluded from the ruleset.

$runs = [
	'logic: gateway present' => [ 'logic-tests.php', [] ],
	'logic: gateway absent'  => [ 'logic-tests.php', [ 'HPVE_WC' => 'absent' ] ],
];

$total_passed = 0;
$total_failed = 0;
$failed_runs  = [];

foreach ( $runs as $label => $run ) {
	list( $script, $env ) = $run;

	// putenv() rather than a "NAME=value cmd" prefix, which cmd.exe does not understand.
	foreach ( $env as $key => $value ) {
		putenv( $key . '=' . $value );
	}

	$output = [];
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $script ) . ' 2>&1', $output, $status );

	foreach ( array_keys( $env ) as $key ) {
		putenv( $key );
	}

	$summary = '';

	foreach ( $output as $line ) {
		if ( 0 === strpos( $line, 'RESULT:' ) ) {
			$summary = $line;
		}
	}

	if ( preg_match( '/RESULT: (\d+) passed, (\d+) failed/', $summary, $m ) ) {
		$total_passed += (int) $m[1];
		$total_failed += (int) $m[2];
	}

	if ( 0 !== $status ) {
		$failed_runs[] = $label;

		echo "\n--- {$label} ---\n" . implode( "\n", $output ) . "\n";
	}

	printf( "%-32s %s\n", $label, 0 === $status ? 'OK   ' . $summary : 'FAIL ' . $summary );
}

echo str_repeat( '-', 60 ) . "\n";
printf( "TOTAL: %d passed, %d failed (%d expected: the self-test in each run)\n", $total_passed, $total_failed, count( $runs ) );

exit( $failed_runs ? 1 : 0 );
