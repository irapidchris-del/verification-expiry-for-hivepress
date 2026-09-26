<?php
/**
 * VAT number provider: free business verification with no key at all.
 *
 * The applicant types their VAT registration number. A GB number goes to HMRC's "check a UK VAT
 * number" service; any other member-state prefix goes to the European Commission's VIES service.
 * Neither charges and neither needs a credential, which makes this the one provider here that a
 * site owner can switch on without signing up to anything.
 *
 * Its limit is worth being honest about in the settings copy: plenty of legitimate sole traders
 * are under the VAT threshold and have no number, so this suits a site whose Vendors are
 * established businesses and suits a site of individual freelancers badly.
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

use Verification_Expiry\Logic\Hpve_Registry_Mapper as Mapper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * HMRC and VIES VAT lookup.
 */
final class Hpve_Provider_Vat extends Hpve_Provider_Registry {

	/**
	 * Gets the stored name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'vat_number';
	}

	/**
	 * Gets the label.
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'VAT number check', 'verification-expiry-for-hivepress' );
	}

	/**
	 * Always configured: neither service takes a credential.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Nothing to set up.
	 *
	 * @return string
	 */
	public function get_setup_notice() {
		return '';
	}

	/**
	 * The reference field's label.
	 *
	 * @return string
	 */
	public function get_reference_label() {
		return esc_html__( 'VAT number', 'verification-expiry-for-hivepress' );
	}

	/**
	 * The instruction under that field.
	 *
	 * @return string
	 */
	public function get_reference_help() {
		return esc_html__( 'Your VAT registration number, for example GB123456789. A UK number can be typed as nine digits on its own.', 'verification-expiry-for-hivepress' );
	}

	/**
	 * Normalises a VAT number.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function normalise_reference( $value ) {
		list( $country, $number ) = Mapper::split_vat_number( $value );

		return '' === $country ? '' : $country . $number;
	}

	/**
	 * Looks the number up at whichever service owns it, and maps the answer.
	 *
	 * @param string $reference Normalised VAT number, country code first.
	 * @param string $claimed_name The applicant's business name.
	 * @return array|\WP_Error
	 */
	protected function look_up( $reference, $claimed_name ) {
		list( $country, $number ) = Mapper::split_vat_number( $reference );

		if ( '' === $country ) {
			return new \WP_Error( 'hpve_bad_vat', 'Unusable VAT number.' );
		}

		$answer = 'GB' === $country
			? $this->get_http()->get_uk_vat( $number )
			: $this->get_http()->get_eu_vat( $country, $number );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return Mapper::map_vat( $answer, $claimed_name, $this->requires_name_match(), $this->requires_signoff() );
	}

	/**
	 * What the applicant is told when the number could never be looked up.
	 *
	 * @return string
	 */
	protected function get_invalid_message() {
		return esc_html__( 'That does not look like a VAT number. A UK one is nine digits, optionally written with GB in front.', 'verification-expiry-for-hivepress' );
	}

	/**
	 * What the applicant is told when the number is not registered.
	 *
	 * @return string
	 */
	protected function get_not_found_message() {
		return esc_html__( 'That VAT number is not registered. Please check it on a recent VAT return and send it again.', 'verification-expiry-for-hivepress' );
	}
}
