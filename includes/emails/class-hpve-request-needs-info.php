<?php
/**
 * Needs more information email.
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
 * Sent to the applicant when a reviewer asks for more.
 *
 * @class Hpve_Request_Needs_Info
 */
class Hpve_Request_Needs_Info extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Verification Needs More Information', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a Vendor when a reviewer needs something more before their verification can be approved.', 'verification-expiry-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'note', 'verification_url' ],
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
				'subject' => esc_html__( 'Your verification needs more information', 'verification-expiry-for-hivepress' ),
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the applicant's name, 2: the note the reviewer wrote, 3: the link to the Verification page. All three are filled in automatically. */
						__( 'Hi, %1$s! We looked at your documents but need something more before we can approve you: %2$s Please update your documents and send them again here: %3$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%note%',
						'%verification_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
