<?php
/**
 * Vendor verification expired email.
 *
 * Registered by HivePress itself: core globs includes/emails/*.php across every registered
 * extension and derives the email name from the file name, so this file becomes the email
 * "hpve_vendor_verification_expire" (class-core.php:443-464, core 1.7.31). Giving the class a
 * truthy `label` is what makes it editable by the site owner under HivePress > Emails: the
 * Email component then looks for an hp_email post whose slug matches that name and swaps in
 * its subject and body (components/class-email.php:59-91).
 *
 * The class name carries the Hpve prefix deliberately. Core instantiates
 * \HivePress\Emails\{Filename} across all extensions and the autoloader loads exactly one file
 * per class name, so a second extension shipping class-vendor-verification-expire.php would
 * silently stop one of them loading.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the vendor when their verified status is removed because its date has passed.
 *
 * @class Hpve_Vendor_Verification_Expire
 */
class Hpve_Vendor_Verification_Expire extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Vendor Verification Expired', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a vendor when their verified status is removed because its expiry date has passed.', 'verification-expiry-for-hivepress' ),
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
				'subject' => esc_html__( 'Your verified status has expired', 'verification-expiry-for-hivepress' ),

				// A single plain-text sentence, like every core HivePress email, so the site owner's
				// edited version and any email design plugin treat it the same way as core's. Core
				// runs make_clickable() on the body, so the bare URL becomes a link by itself.
				//
				// The tokens are passed as arguments rather than written into the string. The I18n
				// sniff reads "%u" and "%e" inside "%user_name%" as printf placeholders and its fixer
				// renumbers them into tokens that match nothing (resources/wordpress-php-notes.md,
				// "phpcbf rewrites %token% inside a translatable string"). Kept out of the string,
				// they also stay out of the POT, where a translator could localise them into nothing.
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the vendor's name, 2: their profile name, 3: the expiry date, 4: the link to their profile. All four are filled in automatically. */
						__( 'Hi, %1$s! The verified badge on your profile "%2$s" was due for review on %3$s and has now been removed. Please check that your profile, listings and contact details are still up to date, then get in touch with us to be verified again: %4$s', 'verification-expiry-for-hivepress' ),
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
