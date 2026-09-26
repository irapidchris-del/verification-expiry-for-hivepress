<?php
/**
 * Paid, now send documents email.
 *
 * Same registration and token rules as class-hpve-request-submitted.php. Always sent, whatever the
 * "applicant emails" setting says: a person who has just paid must be told what to do next.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to a buyer whose order created or paid for a request.
 *
 * @class Hpve_Request_Paid
 */
class Hpve_Request_Paid extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Verification Paid', 'verification-expiry-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a buyer when an order containing the verification product is paid, asking them to send their documents.', 'verification-expiry-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'order_number', 'priority_note', 'verification_url' ],
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
				'subject' => esc_html__( 'Thanks for your payment, now send your documents', 'verification-expiry-for-hivepress' ),
				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the buyer's name, 2: the order number, 3: a sentence about paid requests being reviewed first or nothing, 4: the link to the Verification page. All four are filled in automatically. */
						__( 'Hi, %1$s! Thanks for your payment (order #%2$s). %3$s The next step is to upload your documents so we can check them: %4$s', 'verification-expiry-for-hivepress' ),
						'%user_name%',
						'%order_number%',
						'%priority_note%',
						'%verification_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
