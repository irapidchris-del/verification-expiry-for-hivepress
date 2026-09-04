<?php
/**
 * Listing verified email.
 *
 * Registered by HivePress itself from the file name, as "hpve_listing_verification_verified"
 * (class-core.php:443-464, core 1.7.31), and editable under HivePress > Emails because the class
 * carries a `label`. See class-hpve-vendor-verification-expire.php for why the class and file
 * names are prefixed and why the tokens are passed to sprintf() rather than written into the string.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the listing's owner when an admin marks the listing as verified.
 *
 * @class Hpve_Listing_Verification_Verified
 */
class Hpve_Listing_Verification_Verified extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Listing Verified', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to the owner of a listing when the listing is marked as verified. It can be switched off under HivePress > Settings > Verification Expiry.', 'verification-expiry-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'listing_title', 'listing_url', 'expiry_date', 'expiry_note', 'user', 'listing' ],
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
				'subject' => esc_html__( 'Your listing is now verified', 'verification-expiry-for-hivepress' ),

				// %expiry_note% is a whole sentence when the verification has a date and empty when
				// it does not, so the default body reads correctly either way.
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the owner's name, 2: the listing title, 3: a sentence about the review date, or nothing, 4: the link to the listing. All four are filled in automatically. */
						__( 'Hi, %1$s! Your listing "%2$s" has been verified, and the verified badge now shows on it. %3$sYou can see it here: %4$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%listing_title%',
						'%expiry_note%',
						'%listing_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
