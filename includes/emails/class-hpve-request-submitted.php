<?php
/**
 * Request submitted email.
 *
 * Registered by HivePress itself from the file name (class-core.php:443-464, core 1.7.31), so this
 * file becomes the email "hpve_request_submitted", editable under HivePress > Emails because the
 * class carries a label. Tokens are passed to sprintf rather than written into the translatable
 * string (resources/wordpress-php-notes.md, "phpcbf rewrites %token%"), and the subject carries no
 * apostrophe (the 1.2.0 lesson: a subject with one rendered its entity in some mail clients).
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the applicant when their documents are sent for review.
 *
 * @class Hpve_Request_Submitted
 */
class Hpve_Request_Submitted extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Verification Request Submitted', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a Vendor when their verification documents have been sent for review.', 'verification-expiry-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'review_note', 'verification_url' ],
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
				'subject' => esc_html__( 'We have received your verification documents', 'verification-expiry-for-hivepress' ),
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the applicant's name, 2: a phrase such as ", usually within 3 working days" or nothing, 3: the link to the Verification page. All three are filled in automatically. */
						__( 'Hi, %1$s! Thanks, we have your documents and will review them%2$s. We will email you as soon as the review is done. You can check on it here: %3$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%review_note%',
						'%verification_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
