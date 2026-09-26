<?php
/**
 * Request rejected email.
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
 * Sent to the applicant when their request is not approved.
 *
 * @class Hpve_Request_Rejected
 */
class Hpve_Request_Rejected extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Verification Not Approved', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a Vendor when their verification request is not approved, with the reason.', 'verification-expiry-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'reason', 'verification_url' ],
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
				'subject' => esc_html__( 'Your verification was not approved', 'verification-expiry-for-hivepress' ),
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the applicant's name, 2: the reason the reviewer gave, 3: the link to the Verification page. All three are filled in automatically. */
						__( 'Hi, %1$s! We could not verify you this time: %2$s You can apply again with different documents here, and if you think this is a mistake please contact us: %3$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%reason%',
						'%verification_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
