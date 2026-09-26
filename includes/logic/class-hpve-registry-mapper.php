<?php
/**
 * Pure mapping for the free business registers: normalising what the applicant typed, comparing
 * the registered name with theirs, and turning a register's answer into a request action.
 *
 * No WordPress and no HTTP, so the logic tests drive every branch from fixtures. The provider
 * classes do the input and output around it; every decision a site owner could argue with is
 * made here, where it can be read in one file.
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Register answers to request actions.
 */
final class Hpve_Registry_Mapper {

	/**
	 * Company statuses that mean the company exists and is trading.
	 *
	 * Companies House publishes these values in its enumeration constants file
	 * (github.com/companieshouse/api-enumerations, constants.yml, read 2026-09-07). "open" and
	 * "registered" appear for Northern Ireland and Royal Charter bodies respectively.
	 */
	const STATUS_GOOD = [ 'active', 'open', 'registered' ];

	/**
	 * Statuses that mean the company is gone. There is nothing for a human to weigh here.
	 */
	const STATUS_DEAD = [ 'dissolved', 'converted-closed', 'closed', 'removed' ];

	/**
	 * Statuses that mean the company still exists but is in trouble.
	 *
	 * Deliberately neither a pass nor a fail. A company in administration is still trading and may
	 * be a perfectly good vendor, but it is not a decision to make automatically, so these go to a
	 * person however the site is configured.
	 */
	const STATUS_DISTRESSED = [ 'liquidation', 'receivership', 'administration', 'insolvency-proceedings', 'voluntary-arrangement' ];

	/**
	 * Company-name endings ignored when comparing two names.
	 *
	 * Someone whose Vendor name is "Ivy Lane Gardens" is the same business as "IVY LANE GARDENS LTD", and
	 * refusing that match would send almost every legitimate sole-trader-turned-limited applicant
	 * to a human for no reason.
	 */
	const NAME_SUFFIXES = [ 'limited', 'ltd', 'plc', 'llp', 'lp', 'cic', 'cio', 'company', 'co', 'holdings', 'group', 'uk' ];

	/**
	 * The country codes a VAT number can carry: GB for HMRC, the rest for VIES.
	 *
	 * Checked against a list rather than "any two letters", because any two letters happily reads
	 * "NOTANUMBER" as country NO and number TANUMBER, which is then sent to VIES as a real lookup.
	 * Greece is EL here and Northern Ireland is XI, which is how both file at VIES.
	 */
	const VAT_COUNTRIES = [
		'GB',
		'XI',
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'EL',
		'ES',
		'FI',
		'FR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
	];

	/**
	 * Normalises a company number the way Companies House stores it.
	 *
	 * Numbers are eight characters. An all-digit number typed without its leading zeros ("1234567")
	 * is padded, because that is how people read them off a letterhead and an unpadded number is a
	 * 404 rather than a mismatch, which reads to the applicant as "your company does not exist".
	 * Prefixed numbers (SC, NI, OC and the rest) are already eight characters and are left alone.
	 *
	 * @param string $value What the applicant typed.
	 * @return string Normalised number, or an empty string when it cannot be one.
	 */
	public static function normalise_company_number( $value ) {
		$value = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );

		if ( '' === $value || strlen( $value ) > 8 ) {
			return '';
		}

		if ( ctype_digit( $value ) ) {
			return str_pad( $value, 8, '0', STR_PAD_LEFT );
		}

		// A prefixed number is two letters and six digits.
		if ( preg_match( '/^[A-Z]{2}[0-9]{6}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Splits a VAT number into a country code and the rest.
	 *
	 * A number typed with no country code is treated as GB, because this plugin's own settings
	 * describe the field as a UK VAT number and a bare nine digits is what a UK invoice shows.
	 *
	 * @param string $value What the applicant typed.
	 * @return array{0: string, 1: string} Country code and number, both empty when unusable.
	 */
	public static function split_vat_number( $value ) {
		$value = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );

		if ( '' === $value ) {
			return [ '', '' ];
		}

		if ( preg_match( '/^([A-Z]{2})([0-9][0-9A-Z]*)$/', $value, $matches ) ) {

			// Greece files its numbers under EL at VIES but writes them GR; both are seen in the wild.
			$country = 'GR' === $matches[1] ? 'EL' : $matches[1];

			if ( ! in_array( $country, self::VAT_COUNTRIES, true ) || strlen( $matches[2] ) < 2 ) {
				return [ '', '' ];
			}

			return [ $country, $matches[2] ];
		}

		// UK numbers are nine digits, or twelve for a branch trader.
		if ( preg_match( '/^([0-9]{9}|[0-9]{12})$/', $value ) ) {
			return [ 'GB', $value ];
		}

		return [ '', '' ];
	}

	/**
	 * Reduces a business name to the part worth comparing.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function normalise_name( $name ) {
		$name = strtolower( (string) $name );

		// Treat "&" as "and" before punctuation goes, so "Cut & Co" matches "Cut and Co".
		$name = str_replace( '&', ' and ', $name );

		/*
		 * Apostrophes are DELETED rather than turned into a space, unlike every other mark. A
		 * register writes "O'BRIEN'S BAKERY" and a Vendor types "OBriens Bakery"; splitting on the
		 * apostrophe gives "o brien s bakery" against "obriens bakery", which do not match, and the
		 * applicant is sent away to correct a name that was already right.
		 */
		$name = str_replace( [ "'", '’', '`' ], '', $name );

		$name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$name = trim( (string) $name );

		if ( '' === $name ) {
			return '';
		}

		$words     = explode( ' ', $name );
		$remaining = count( $words );

		// Drop trailing company-form words only, never a leading one: "Co Op Gardens" keeps its "Co".
		while ( $remaining > 1 && in_array( end( $words ), self::NAME_SUFFIXES, true ) ) {
			array_pop( $words );

			--$remaining;
		}

		return implode( ' ', $words );
	}

	/**
	 * Whether two business names are close enough to treat as the same business.
	 *
	 * @param string $registered The name on the register.
	 * @param string $claimed The applicant's own name.
	 * @return bool
	 */
	public static function names_match( $registered, $claimed ) {
		$a = self::normalise_name( $registered );
		$b = self::normalise_name( $claimed );

		if ( '' === $a || '' === $b ) {
			return false;
		}

		return $a === $b;
	}

	/**
	 * Maps a Companies House profile to an action.
	 *
	 * @param array  $profile Decoded profile, or [ 'hpve_not_found' => true ].
	 * @param string $claimed_name The applicant's business name.
	 * @param bool   $require_name Whether a name mismatch should stop an automatic approval.
	 * @param bool   $signoff Whether a pass waits for an admin.
	 * @return array{action: string, note: string, name: string, status: string}
	 */
	public static function map_company( array $profile, $claimed_name, $require_name, $signoff ) {
		if ( ! empty( $profile['hpve_not_found'] ) ) {
			return self::result( 'needs_info', 'not_found', '', '' );
		}

		$name   = isset( $profile['company_name'] ) && is_string( $profile['company_name'] ) ? $profile['company_name'] : '';
		$status = isset( $profile['company_status'] ) && is_string( $profile['company_status'] ) ? strtolower( $profile['company_status'] ) : '';

		if ( '' === $status ) {
			return self::result( 'needs_info', 'unreadable', $name, $status );
		}

		if ( in_array( $status, self::STATUS_DEAD, true ) ) {
			return self::result( 'reject', 'dead', $name, $status );
		}

		if ( in_array( $status, self::STATUS_DISTRESSED, true ) ) {
			return self::result( 'hold_for_signoff', 'distressed', $name, $status );
		}

		if ( ! in_array( $status, self::STATUS_GOOD, true ) ) {
			// An unrecognised status is never approved automatically: the register added a value
			// this plugin has not been taught, and guessing which way it falls is how a dissolved
			// company would get a badge.
			return self::result( 'hold_for_signoff', 'unknown_status', $name, $status );
		}

		if ( $require_name && ! self::names_match( $name, $claimed_name ) ) {
			return self::result( 'needs_info', 'name_mismatch', $name, $status );
		}

		return self::result( $signoff ? 'hold_for_signoff' : 'approve', 'ok', $name, $status );
	}

	/**
	 * Maps a VAT lookup to an action.
	 *
	 * HMRC answers with a "target" object; VIES answers with an isValid flag and its own name and
	 * address fields. Both shapes are read here so the provider does not branch on which service
	 * replied.
	 *
	 * @param array  $answer Decoded answer, or [ 'hpve_not_found' => true ].
	 * @param string $claimed_name The applicant's business name.
	 * @param bool   $require_name Whether a name mismatch should stop an automatic approval.
	 * @param bool   $signoff Whether a pass waits for an admin.
	 * @return array{action: string, note: string, name: string, status: string}
	 */
	public static function map_vat( array $answer, $claimed_name, $require_name, $signoff ) {
		if ( ! empty( $answer['hpve_not_found'] ) ) {
			return self::result( 'needs_info', 'not_found', '', 'invalid' );
		}

		// VIES says so explicitly; HMRC says so by answering at all.
		if ( array_key_exists( 'isValid', $answer ) && ! $answer['isValid'] ) {
			return self::result( 'needs_info', 'not_found', '', 'invalid' );
		}

		$name = '';

		if ( isset( $answer['target']['name'] ) && is_string( $answer['target']['name'] ) ) {
			$name = $answer['target']['name'];
		} elseif ( isset( $answer['name'] ) && is_string( $answer['name'] ) ) {
			$name = $answer['name'];
		}

		// VIES returns "---" when the member state withholds the trader's name. That is not a
		// mismatch and must never be compared, or every trader in those states fails the check.
		if ( '' === trim( $name ) || '---' === trim( $name ) ) {
			return self::result( $signoff ? 'hold_for_signoff' : 'approve', 'no_name', '', 'valid' );
		}

		if ( $require_name && ! self::names_match( $name, $claimed_name ) ) {
			return self::result( 'needs_info', 'name_mismatch', $name, 'valid' );
		}

		return self::result( $signoff ? 'hold_for_signoff' : 'approve', 'ok', $name, 'valid' );
	}

	/**
	 * Builds a result array.
	 *
	 * @param string $action Action.
	 * @param string $note Note key.
	 * @param string $name Registered name.
	 * @param string $status Register status.
	 * @return array
	 */
	protected static function result( $action, $note, $name, $status ) {
		return [
			'action' => $action,
			'note'   => $note,
			'name'   => (string) $name,
			'status' => (string) $status,
		];
	}
}
