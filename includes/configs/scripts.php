<?php
/**
 * Scripts configuration.
 *
 * Front-end scope only (components/class-asset.php:189-191, core 1.7.31): the account page, the
 * public Get Verified page and the Vendor dashboard. The file time rides along in the version so
 * caches refresh whenever the file changes. Nothing is loaded from a CDN.
 *
 * @package Verification_Expiry\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'hpve_frontend' => [
		'handle'  => 'hpve-frontend',
		'src'     => plugin_dir_url( HPVE_FILE ) . 'assets/js/frontend.js',
		'deps'    => [ 'jquery', 'hivepress-core' ],
		'version' => HPVE_VERSION . '.' . (int) filemtime( plugin_dir_path( HPVE_FILE ) . 'assets/js/frontend.js' ),
		'scope'   => [ 'frontend' ],

		'data'    => [
			'labels' => [
				'confirmDelete' => esc_html__( 'Remove this file?', 'verification-expiry-for-hivepress' ),
				'timeout'       => esc_html__( 'Sorry, that took too long. Please try again in a minute.', 'verification-expiry-for-hivepress' ),
				'failed'        => esc_html__( 'Something went wrong. Please try again.', 'verification-expiry-for-hivepress' ),
			],
		],
	],
];
