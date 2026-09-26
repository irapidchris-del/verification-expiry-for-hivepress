<?php
/**
 * Verification page template.
 *
 * The route name equals the template name, so core's body_class filter adds
 * hp-template--hpve-verification-page (components/class-template.php:217-228, core 1.7.31). The
 * label makes it editable in HivePress's template editor; the card and form are one block so an
 * owner cannot half-delete the flow, and the settings tab says when a published template overrides
 * this page.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The account Verification page.
 */
class Hpve_Verification_Page extends User_Account_Page {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label' => esc_html__( 'Verification Page', 'verification-expiry-for-hivepress' ),
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
							'hpve_verification' => [
								'type'   => 'hpve_verification',
								'mode'   => 'full',
								'_label' => esc_html__( 'Verification', 'verification-expiry-for-hivepress' ),
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
