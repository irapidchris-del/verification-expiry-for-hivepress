<?php
/**
 * Sign-off needed email to the site owner.
 *
 * Same registration and token rules as class-hpve-request-submitted.php. Only sent when the
 * "Stripe results need an admin to confirm them" setting is on.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the site email address when an automated check passed and awaits confirmation.
 *
 * @class Hpve_Request_Signoff
 */
class Hpve_Request_Signoff extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Verification Awaiting Sign-off', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to the site email address when an automated identity check has passed and a person must confirm it before the badge shows.', 'verification-expiry-for-hivepress' ),
				'recipient'   => esc_html__( 'Site owner', 'verification-expiry-for-hivepress' ),
				'tokens'      => [ 'user_name', 'review_url' ],
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
				'subject' => esc_html__( 'A verified request is waiting for your approval', 'verification-expiry-for-hivepress' ),
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the applicant's name, 2: the link to the review screen. Both are filled in automatically. */
						__( 'The automated identity check for %1$s has passed. Because sign-off is switched on, the badge will not show until you approve the request here: %2$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%review_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
