<?php
/**
 * Comment types configuration.
 *
 * Marking the audit trail type non-public makes core's Comment component exclude it from comment
 * queries, counts, feeds and the wp-admin Comments screen (components/class-comment.php:54-135, core
 * 1.7.31), exactly as Notifications for HivePress does for its own type. The file keeps core's fixed
 * name; the key is prefixed and becomes "hp_hpve_log".
 *
 * @package Verification_Expiry\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'hpve_log' => [
		'public' => false,
	],
];
