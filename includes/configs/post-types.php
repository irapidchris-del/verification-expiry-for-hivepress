<?php
/**
 * Post types configuration.
 *
 * Core registers every key here as "hp_" + key (components/class-admin.php:117-119, core 1.7.31),
 * so "hpve_request" becomes post type "hp_hpve_request" (15 characters). With no show_in_menu key,
 * core moves the entry into the HivePress admin cluster and decorates it with the red pending count
 * (class-admin.php:166-177, :183-213). The file keeps core's fixed name; only the key is prefixed.
 *
 * The capability block is what keeps applicants out of wp-admin. Vendors are Contributors, who
 * hold edit_posts, so with the default capability_type they would see a Verifications menu listing
 * their own request. Mapping every edit and publish capability to HPVE_REVIEW_CAP (edit_others_posts
 * unless filtered) means only reviewers see the menu (wp-admin/menu.php:188-189) and, because
 * map_meta_cap resolves edit_post on an own draft to cap->edit_posts (wp-includes/capabilities.php,
 * the edit_post author branch), an applicant cannot open their own request's edit screen either.
 * create_posts is do_not_allow so nobody adds a request by hand: requests are created by the
 * account page and by paid orders only.
 *
 * @package Verification_Expiry\Configs
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'hpve_request' => [
		'public'              => false,
		'show_ui'             => true,
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'delete_with_user'    => true,
		'supports'            => [ 'title' ],
		'menu_icon'           => 'dashicons-id',
		'capability_type'     => 'post',
		'map_meta_cap'        => true,

		'capabilities'        => [
			'edit_posts'             => HPVE_REVIEW_CAP,
			'edit_others_posts'      => HPVE_REVIEW_CAP,
			'edit_private_posts'     => HPVE_REVIEW_CAP,
			'edit_published_posts'   => HPVE_REVIEW_CAP,
			'publish_posts'          => HPVE_REVIEW_CAP,
			'read_private_posts'     => HPVE_REVIEW_CAP,
			'delete_posts'           => 'delete_others_posts',
			'delete_others_posts'    => 'delete_others_posts',
			'delete_private_posts'   => 'delete_others_posts',
			'delete_published_posts' => 'delete_others_posts',
			'create_posts'           => 'do_not_allow',
		],

		'labels'              => [
			'name'               => esc_html__( 'Verifications', 'verification-expiry-for-hivepress' ),
			'singular_name'      => esc_html__( 'Verification request', 'verification-expiry-for-hivepress' ),
			'add_new'            => esc_html_x( 'Add New', 'verification request', 'verification-expiry-for-hivepress' ),
			'add_new_item'       => esc_html__( 'Add Verification Request', 'verification-expiry-for-hivepress' ),
			'edit_item'          => esc_html__( 'Review Verification Request', 'verification-expiry-for-hivepress' ),
			'new_item'           => esc_html__( 'Add Verification Request', 'verification-expiry-for-hivepress' ),
			'all_items'          => esc_html__( 'Verifications', 'verification-expiry-for-hivepress' ),
			'search_items'       => esc_html__( 'Search Verification Requests', 'verification-expiry-for-hivepress' ),
			'not_found'          => esc_html__( 'No verification requests found.', 'verification-expiry-for-hivepress' ),
			'not_found_in_trash' => esc_html__( 'No verification requests found.', 'verification-expiry-for-hivepress' ),
		],
	],
];
