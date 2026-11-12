<?php
/**
 * Encryption for the application passwords of remote sites.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-GCM with a key derived from the site's salts (and the optional
 * AXLE_LICENSE_TRACKER_HUB_KEY constant). A password stored this way is of no
 * use on another site, or after the salts change.
 */
final class Crypt {

	private const CIPHER = 'aes-256-gcm';
	private const PREFIX = 'v1:';

	/**
	 * Whether this server can encrypt.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * The 32-byte key.
	 *
	 * @return string
	 */
	private static function key(): string {
		$extra = defined( 'AXLE_LICENSE_TRACKER_HUB_KEY' ) ? (string) constant( 'AXLE_LICENSE_TRACKER_HUB_KEY' ) : '';

		return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|' . $extra, true );
	}

	/**
	 * Encrypts a secret.
	 *
	 * @param string $plain The secret.
	 * @return string The sealed text, or an empty string when this server cannot encrypt.
	 */
	public static function seal( string $plain ): string {
		if ( ! self::available() ) {
			return '';
		}

		$iv  = random_bytes( 12 );
		$tag = '';
		$out = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $out || 16 !== strlen( $tag ) ) {
			return '';
		}

		return self::PREFIX . base64_encode( $iv . $tag . $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text for storage, not obfuscation.
	}

	/**
	 * Decrypts a secret.
	 *
	 * @param string $sealed What seal() returned.
	 * @return string|null The secret, or null when it cannot be read here.
	 */
	public static function open( string $sealed ): ?string {
		if ( 0 !== strpos( $sealed, self::PREFIX ) || ! self::available() ) {
			return null;
		}

		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Text to binary from storage.
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}

		$plain = openssl_decrypt( substr( $raw, 28 ), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

		return false === $plain ? null : $plain;
	}
}
