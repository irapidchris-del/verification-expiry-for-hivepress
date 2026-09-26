<?php
/**
 * Path containment test.
 *
 * Pure PHP. The download gate and the deletion listener refuse any file whose real path does
 * not sit under the private directory; this is the string half of that test, exercised by the
 * logic tests with traversal and prefix-collision inputs. The runtime pairs it with realpath().
 *
 * @package Verification_Expiry\Logic
 */

namespace Verification_Expiry\Logic;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Answers "is this path inside that directory?" without touching the disk.
 */
final class Hpve_Path {

	/**
	 * Normalises separators and resolves "." and ".." segments textually.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalise( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );

		$prefix = '';

		if ( preg_match( '#^([a-zA-Z]:)?/#', $path, $matches ) ) {
			$prefix = ( isset( $matches[1] ) ? strtoupper( $matches[1] ) : '' ) . '/';
			$path   = substr( $path, strlen( $prefix ) );
		}

		$parts = [];

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $parts );

				continue;
			}

			$parts[] = $segment;
		}

		return rtrim( $prefix . implode( '/', $parts ), '/' );
	}

	/**
	 * Whether $path is strictly inside $root (never equal to it).
	 *
	 * @param string $path File or directory path.
	 * @param string $root Directory path.
	 * @return bool
	 */
	public static function is_inside( $path, $root ) {
		$path = self::normalise( $path );
		$root = self::normalise( $root );

		if ( '' === $path || '' === $root ) {
			return false;
		}

		// Windows paths compare case-insensitively; everything else does not. A drive-letter
		// prefix is the honest signal, so the rule keys off it rather than off the host OS.
		if ( preg_match( '#^[A-Z]:/#', $root ) ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}

		return 0 === strpos( $path, $root . '/' );
	}
}
