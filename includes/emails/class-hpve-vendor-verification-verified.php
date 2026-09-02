<?php
/**
 * Vendor verified email.
 *
 * Registered by HivePress itself from the file name, as "hpve_vendor_verification_verified"
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
 * Sent to the vendor when an admin marks them as verified.
 *
 * @class Hpve_Vendor_Verification_Verified
 */
class Hpve_Vendor_Verification_Verified extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Vendor Verified', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a vendor when they are marked as verified. It can be switched off under HivePress > Settings > Verification Expiry.', 'verification-expiry-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'vendor_name', 'vendor_url', 'badges', 'expiry_date', 'expiry_note', 'user', 'vendor' ],
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
				'subject' => esc_html__( 'You are now verified', 'verification-expiry-for-hivepress' ),

				// Tokens passed as arguments, not written into the string: see the expiry email for why.
				// %expiry_note% is a whole sentence when the verification has a date and empty when it
				// does not, so the default body reads correctly either way; %expiry_date% is there for
				// site owners who would rather write their own sentence around the bare date.
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the vendor's name, 2: their profile name, 3: "your profile" or "your profile and listings", 4: a sentence about the review date, or nothing, 5: the link to their profile. All five are filled in automatically. */
						__( 'Hi, %1$s! Your profile "%2$s" has been verified, and the verified badge now shows on %3$s. %4$sYou can see it here: %5$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%vendor_name%',
						'%badges%',
						'%expiry_note%',
						'%vendor_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
