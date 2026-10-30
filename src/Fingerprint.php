<?php
/**
 * License key fingerprints.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * A short, safe stand-in for a license key: a 12-character SHA-256 prefix
 * plus the last 4 characters. It groups the same license across sites without
 * storing, showing or sending the key itself.
 */
final class Fingerprint {

	/**
	 * Fingerprint of a key.
	 *
	 * @param string $key License key.
	 * @return string Such as 9f86d081884c-AB12, or '' for no key.
	 */
	public static function of( string $key ): string {
		$key = trim( $key );
		if ( '' === $key ) {
			return '';
		}

		$tail = strlen( $key ) > 8 ? substr( $key, -4 ) : '';

		return substr( hash( 'sha256', $key ), 0, 12 ) . ( '' !== $tail ? '-' . $tail : '' );
	}

	/**
	 * Whether a value looks like a fingerprint made by of().
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		return (bool) preg_match( '/^[0-9a-f]{12}(-[^\s]{4})?$/', $value );
	}
}
