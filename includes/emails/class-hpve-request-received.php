<?php
/**
 * New request email to the site owner.
 *
 * Same registration and token rules as class-hpve-request-submitted.php.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the site email address when a verification request arrives.
 *
 * @class Hpve_Request_Received
 */
class Hpve_Request_Received extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Verification Request Received', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to the site email address when a Vendor sends a verification request for review.', 'verification-expiry-for-hivepress' ),
				'recipient'   => esc_html__( 'Site owner', 'verification-expiry-for-hivepress' ),
				'tokens'      => [ 'vendor_name', 'user_name', 'paid_note', 'review_url' ],
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Email arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'subject' => sprintf(
					/* translators: %s: the Vendor's name, filled in automatically. */
					esc_html__( 'New verification request from %s', 'verification-expiry-for-hivepress' ),
					'%vendor_name%'
				),
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the Vendor's name, 2: the applicant's account name, 3: a sentence about payment or nothing, 4: the link to the review screen. All four are filled in automatically. */
						__( 'A verification request from "%1$s" (%2$s) is waiting for review. %3$s Review it here: %4$s', 'verification-expiry-for-hivepress' ),
						'%vendor_name%',
						'%user_name%',
						'%paid_note%',
						'%review_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
