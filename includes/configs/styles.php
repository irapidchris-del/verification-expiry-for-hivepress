<?php
/**
 * Styles configuration.
 *
 * Three rules of our own and nothing else; every other class on the card is core's
 * (resources/hivepress-ui.md, "Native look and feel"). Front-end scope only.
 *
 * @package Verification_Expiry\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'hpve_frontend' => [
		'handle'  => 'hpve-frontend',
		'src'     => plugin_dir_url( HPVE_FILE ) . 'assets/css/frontend.css',
		'version' => HPVE_VERSION . '.' . (int) filemtime( plugin_dir_path( HPVE_FILE ) . 'assets/css/frontend.css' ),
		'scope'   => [ 'frontend' ],
	],
];
