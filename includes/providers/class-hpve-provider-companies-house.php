<?php
/**
 * Companies House provider: free UK company verification.
 *
 * The applicant types their registered company number. The plugin asks Companies House whether
 * that company exists, whether it is still trading, and what name is on the register, then
 * compares that name with the Vendor's.
 *
 * Free, but it needs a key: register at developer.company-information.service.gov.uk, create an
 * application and take its API key. The key authenticates the site, not the applicant, and
 * Companies House does not charge for the public read API (read 2026-09-07).
 *
 * @package Verification_Expiry\Providers
 */

namespace Verification_Expiry\Providers;

use Verification_Expiry\Logic\Hpve_Registry_Mapper as Mapper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * UK company register.
 */
final class Hpve_Provider_Companies_House extends Hpve_Provider_Registry {

	/**
	 * Gets the stored name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'companies_house';
	}

	/**
	 * Gets the label. An organisation's name, so it is not translated.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Companies House';
	}

	/**
	 * Configured once a key is present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) hivepress()->hpve_request->get_option( 'ch_api_key', '' ) );
	}

	/**
	 * One sentence for the settings tab when not configured.
	 *
	 * @return string
	 */
	public function get_setup_notice() {
		if ( $this->is_configured() ) {
			return '';
		}

		return esc_html__( 'Add a free Companies House API key below. Register at developer.company-information.service.gov.uk, create an application and copy its key.', 'verification-expiry-for-hivepress' );
	}

	/**
	 * The reference field's label.
	 *
	 * @return string
	 */
	public function get_reference_label() {
		return esc_html__( 'Company number', 'verification-expiry-for-hivepress' );
	}

	/**
	 * The instruction under that field.
	 *
	 * @return string
	 */
	public function get_reference_help() {
		return esc_html__( 'The eight-character number on your certificate of incorporation, for example 01234567 or SC123456. Leading zeros are optional.', 'verification-expiry-for-hivepress' );
	}

	/**
	 * Normalises a company number.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function normalise_reference( $value ) {
		return Mapper::normalise_company_number( $value );
	}

	/**
	 * Looks the company up and maps the answer.
	 *
	 * @param string $reference Normalised company number.
	 * @param string $claimed_name The applicant's business name.
	 * @return array|\WP_Error
	 */
	protected function look_up( $reference, $claimed_name ) {
		$profile = $this->get_http()->get_company( $reference );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		return Mapper::map_company( $profile, $claimed_name, $this->requires_name_match(), $this->requires_signoff() );
	}

	/**
	 * What the applicant is told when the number could never be looked up.
	 *
	 * @return string
	 */
	protected function get_invalid_message() {
		return esc_html__( 'That does not look like a company number. It is eight characters: either eight digits, or two letters and six digits, such as SC123456.', 'verification-expiry-for-hivepress' );
	}

	/**
	 * What the applicant is told when there is no such company.
	 *
	 * @return string
	 */
	protected function get_not_found_message() {
		return esc_html__( 'Companies House has no company with that number. Please check it on your certificate of incorporation and send it again.', 'verification-expiry-for-hivepress' );
	}
}
