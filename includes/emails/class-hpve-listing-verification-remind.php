<?php
/**
 * Listing verification reminder email.
 *
 * Registered by HivePress itself from the file name, as "hpve_listing_verification_remind"
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
 * Sent to the listing's owner a set number of days before the listing's verification ends.
 *
 * @class Hpve_Listing_Verification_Remind
 */
class Hpve_Listing_Verification_Remind extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Listing Verification Expiring', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to the owner of a listing a set number of days before the listing\'s verified badge is due to be removed. The number of days is set under HivePress > Settings > Verification Expiry.', 'verification-expiry-for-hivepress' ),
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
				'subject' => esc_html__( 'Your listing verification is due for review', 'verification-expiry-for-hivepress' ),

				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the owner's name, 2: the listing title, 3: the expiry date, 4: the link to the listing. All four are filled in automatically. */
						__( 'Hi, %1$s! The verification of your listing "%2$s" is due for review on %3$s. Please make sure the listing and your contact details are up to date before then, so it can be renewed: %4$s', 'verification-expiry-for-hivepress' ),
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
