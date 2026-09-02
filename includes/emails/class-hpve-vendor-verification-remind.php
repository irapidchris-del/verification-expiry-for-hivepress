<?php
/**
 * Vendor verification reminder email.
 *
 * Registered by HivePress itself from the file name, as "hpve_vendor_verification_remind"
 * (class-core.php:443-464, core 1.7.31), and editable under HivePress > Emails because the class
 * carries a `label`. See class-hpve-vendor-verification-expire.php for why the class and file
 * names are prefixed.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the vendor a set number of days before their verified status ends.
 *
 * @class Hpve_Vendor_Verification_Remind
 */
class Hpve_Vendor_Verification_Remind extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Vendor Verification Expiring', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a vendor a set number of days before their verified status ends. The number of days is set under HivePress > Settings > Verification Expiry.', 'verification-expiry-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'vendor_name', 'vendor_url', 'expiry_date', 'user', 'vendor' ],
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
				'subject' => esc_html__( 'Your verified status is due for review', 'verification-expiry-for-hivepress' ),

				// Tokens passed as arguments, not written into the string: see the expiry email for why.
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the vendor's name, 2: their profile name, 3: the expiry date, 4: the link to their profile. All four are filled in automatically. */
						__( 'Hi, %1$s! Your verified status for "%2$s" is due for review on %3$s. Please make sure your profile, listings and contact details are up to date before then, so it can be renewed: %4$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%vendor_name%',
						'%expiry_date%',
						'%vendor_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
