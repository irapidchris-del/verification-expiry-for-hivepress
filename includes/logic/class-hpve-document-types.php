<?php
/**
 * Document type settings normaliser.
 *
 * Pure PHP. The settings tab stores the document types as a HivePress repeater, and a repeater
 * has three stored shapes: absent (never saved, use the defaults), '' (every row deleted, which
 * options.php stores as an empty string) and an array of rows whose blanks are null
 * (resources/hivepress-settings.md, "Repeater rows are never dropped"). Every read goes through
 * normalise() so the rest of the plugin only ever sees a clean list.
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reads and cleans the document type rows.
 */
final class Hpve_Document_Types {

	/**
	 * The only file formats the plugin will ever accept, whatever the settings say.
	 *
	 * HEIC is deliberately absent: browsers cannot show it inline through the download gate and
	 * WordPress only reads it with Imagick present. iPhones hand Safari a JPEG when "Most
	 * Compatible" is chosen, and the readme says so.
	 */
	const FORMATS = [ 'jpg', 'jpeg', 'png', 'webp', 'pdf' ];

	/**
	 * Hard caps the settings screen cannot raise.
	 */
	const MAX_FILES_CAP = 5;
	const MAX_MB_CAP    = 50;
	const DEFAULT_MB    = 10;
	const DEFAULT_FILES = 2;

	/**
	 * Prefix of the model field one document type becomes.
	 */
	const FIELD_PREFIX = 'hpve_doc_';

	/**
	 * The four rows a fresh install starts with.
	 *
	 * Labels are passed in by the caller so they can be translated at seed time; the English
	 * fallbacks keep this class free of gettext.
	 *
	 * @param array $labels Optional map of key to [ label, help ].
	 * @return array
	 */
	public static function defaults( array $labels = [] ) {
		$rows = [
			[
				'key'      => 'photo_id',
				'label'    => 'Photo ID',
				'help'     => 'A passport, driving licence or national identity card. The name must match your profile.',
				'required' => true,
			],
			[
				'key'      => 'qualification',
				'label'    => 'Qualification certificate',
				'help'     => 'A certificate or diploma for the work you offer.',
				'required' => false,
			],
			[
				'key'      => 'insurance',
				'label'    => 'Insurance certificate',
				'help'     => 'Your current public liability or professional insurance certificate.',
				'required' => false,
			],
			[
				'key'      => 'proof_of_address',
				'label'    => 'Proof of address',
				'help'     => 'A utility bill or bank statement from the last three months.',
				'required' => false,
			],
		];

		foreach ( $rows as $index => $row ) {
			if ( isset( $labels[ $row['key'] ] ) ) {
				$rows[ $index ]['label'] = (string) $labels[ $row['key'] ][0];
				$rows[ $index ]['help']  = (string) $labels[ $row['key'] ][1];
			}

			$rows[ $index ]['enabled']   = true;
			$rows[ $index ]['formats']   = self::FORMATS;
			$rows[ $index ]['max_mb']    = self::DEFAULT_MB;
			$rows[ $index ]['max_files'] = self::DEFAULT_FILES;
		}

		return $rows;
	}

	/**
	 * Whether a key is usable as a field name suffix and a meta key.
	 *
	 * @param mixed $key Key.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[a-z0-9_]{2,32}$/', $key );
	}

	/**
	 * Cleans one stored key the way the settings field would.
	 *
	 * @param mixed $key Key.
	 * @return string
	 */
	public static function sanitize_key( $key ) {
		$key = strtolower( trim( (string) $key ) );
		$key = preg_replace( '/[^a-z0-9_]+/', '_', $key );

		return trim( (string) $key, '_' );
	}

	/**
	 * The model and form field name for a document type.
	 *
	 * @param string $key Type key.
	 * @return string
	 */
	public static function field_name( $key ) {
		return self::FIELD_PREFIX . $key;
	}

	/**
	 * The type key a field name belongs to, or an empty string.
	 *
	 * @param string $field_name Field name.
	 * @return string
	 */
	public static function key_from_field( $field_name ) {
		$field_name = (string) $field_name;

		if ( 0 !== strpos( $field_name, self::FIELD_PREFIX ) ) {
			return '';
		}

		$key = substr( $field_name, strlen( self::FIELD_PREFIX ) );

		return self::is_valid_key( $key ) ? $key : '';
	}

	/**
	 * Turns whatever the option holds into a clean list of rows.
	 *
	 * @param mixed $stored The option value: null/false (absent), '' (emptied) or an array.
	 * @param bool  $include_disabled Keep rows whose Enabled box is unticked.
	 * @return array
	 */
	public static function normalise( $stored, $include_disabled = false ) {
		if ( null === $stored || false === $stored ) {
			$stored = self::defaults();
		}

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$rows = [];
		$seen = [];

		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = self::sanitize_key( isset( $row['key'] ) ? $row['key'] : '' );

			if ( ! self::is_valid_key( $key ) || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			$enabled = self::truthy( isset( $row['enabled'] ) ? $row['enabled'] : true );

			if ( ! $enabled && ! $include_disabled ) {
				continue;
			}

			$formats = isset( $row['formats'] ) ? (array) $row['formats'] : [];
			$formats = array_values( array_intersect( self::FORMATS, array_map( 'strtolower', array_map( 'strval', $formats ) ) ) );

			if ( ! $formats ) {
				$formats = self::FORMATS;
			}

			$max_mb = isset( $row['max_mb'] ) && is_numeric( $row['max_mb'] ) ? (int) $row['max_mb'] : self::DEFAULT_MB;
			$max_mb = max( 1, min( self::MAX_MB_CAP, $max_mb ) );

			$max_files = isset( $row['max_files'] ) && is_numeric( $row['max_files'] ) ? (int) $row['max_files'] : self::DEFAULT_FILES;
			$max_files = max( 1, min( self::MAX_FILES_CAP, $max_files ) );

			$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';

			$rows[] = [
				'key'       => $key,
				'label'     => '' !== $label ? $label : ucwords( str_replace( '_', ' ', $key ) ),
				'help'      => isset( $row['help'] ) ? trim( (string) $row['help'] ) : '',
				'enabled'   => $enabled,
				'required'  => self::truthy( isset( $row['required'] ) ? $row['required'] : false ),
				'formats'   => $formats,
				'max_mb'    => $max_mb,
				'max_files' => $max_files,
			];
		}

		return $rows;
	}

	/**
	 * Finds one row by key.
	 *
	 * @param array  $rows Normalised rows.
	 * @param string $key Type key.
	 * @return array|null
	 */
	public static function find( array $rows, $key ) {
		foreach ( $rows as $row ) {
			if ( $row['key'] === $key ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The size limit of one row in bytes.
	 *
	 * @param array $row Normalised row.
	 * @return int
	 */
	public static function max_bytes( array $row ) {
		return (int) $row['max_mb'] * 1024 * 1024;
	}

	/**
	 * Reads a checkbox value the way options.php stores it: '' and null are off.
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	protected static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return '' !== $value && '0' !== $value;
		}

		return (bool) $value;
	}
}
