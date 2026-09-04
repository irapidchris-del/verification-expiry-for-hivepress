<?php
/**
 * Listing verification expired email.
 *
 * Registered by HivePress itself from the file name, as "hpve_listing_verification_expire"
 * (class-core.php:443-464, core 1.7.31). See class-hpve-vendor-verification-expire.php for why
 * the class and file names are prefixed and why the tokens are passed to sprintf() rather than
 * written into the string.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the listing's owner when the listing's verified badge is removed because its date has passed.
 *
 * @class Hpve_Listing_Verification_Expire
 */
class Hpve_Listing_Verification_Expire extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Listing Verification Expired', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to the owner of a listing when the listing\'s verified badge is removed because its expiry date has passed.', 'verification-expiry-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'listing_title', 'listing_url', 'expiry_date', 'user', 'listing' ],
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
				'subject' => esc_html__( 'Your listing verification has expired', 'verification-expiry-for-hivepress' ),

				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the owner's name, 2: the listing title, 3: the expiry date, 4: the link to the listing. All four are filled in automatically. */
						__( 'Hi, %1$s! The verification of your listing "%2$s" was due for review on %3$s and has now ended, so the verified badge no longer shows on it. Please check that the listing and your contact details are still up to date, then get in touch with us to have it verified again: %4$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%listing_title%',
						'%expiry_date%',
						'%listing_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
