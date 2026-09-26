<?php
/**
 * "Get verified" call to action block.
 *
 * Internal (no label, so no Gutenberg block and no shortcode). Injected at _order 5 into the
 * Vendor dashboard and the account settings page by the request component, and rendered only when
 * the viewer has a Vendor that is not verified or has expired, with no request pending.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Renders the call to action.
 */
class Hpve_Verification_Cta extends Block {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			$meta,
			[
				'label' => null,
			]
		);

		parent::init( $meta );
	}

	/**
	 * Renders block HTML.
	 *
	 * @return string
	 */
	public function render() {
		$component = hivepress()->hpve_request;

		if ( ! $component || ! $component->is_enabled() || ! is_user_logged_in() ) {
			return '';
		}

		$user_id = get_current_user_id();
		$vendor  = $component->get_vendor_for_user( $user_id );

		if ( ! $vendor ) {
			return '';
		}

		$verified = (bool) get_post_meta( $vendor->get_id(), 'hp_verified', true );

		if ( $verified ) {
			return '';
		}

		$request = $component->get_for_user( $user_id );

		if ( $request && 'pending' === (string) $request->get_status() ) {
			return '';
		}

		$expired = absint( get_post_meta( $vendor->get_id(), \HivePress\Components\Hpve_Verification::META_EXPIRED, true ) );

		return ( new Part(
			[
				'path'    => 'hpve-request/cta',
				'context' => [
					'expired'          => $expired > 0,
					'verification_url' => hivepress()->router->get_url( 'hpve_verification_page' ),
				],
			]
		) )->render();
	}
}
