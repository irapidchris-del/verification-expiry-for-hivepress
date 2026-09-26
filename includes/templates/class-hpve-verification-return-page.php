<?php
/**
 * Verification return page template.
 *
 * Where a provider's hosted flow sends the applicant back. The route has already queued a sync;
 * this page only says so.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The return page.
 */
class Hpve_Verification_Return_Page extends User_Account_Page {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label' => esc_html__( 'Verification Return Page', 'verification-expiry-for-hivepress' ),
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Template arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_trees(
			[
				'blocks' => [
					'page_content' => [
						'blocks' => [
							'hpve_verification_return' => [
								'type'   => 'part',
								'path'   => 'hpve-request/return',
								'_label' => esc_html__( 'Return message', 'verification-expiry-for-hivepress' ),
								'_order' => 10,
							],
						],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
