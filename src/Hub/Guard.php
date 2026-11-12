<?php
/**
 * Which addresses the hub may talk to.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * SSRF care for the hub: https only, no credentials in the address, no
 * loopback or private addresses. A filter lifts the last two rules for local
 * testing.
 */
final class Guard {

	/**
	 * Whether private and loopback addresses, and plain http, are allowed.
	 *
	 * @param string $url The address being checked.
	 * @return bool
	 */
	public static function allow_private( string $url = '' ): bool {
		/**
		 * Filters whether the hub may use http and loopback or private addresses.
		 * Off by default. Turn it on only for local testing.
		 *
		 * @param bool   $allow Default false.
		 * @param string $url   The address being checked.
		 */
		return (bool) apply_filters( 'axle_license_tracker_hub_allow_private', false, $url );
	}

	/**
	 * Checks and cleans a site address.
	 *
	 * @param string $url What the person typed.
	 * @return string|\WP_Error The address with a trailing slash and no query, or the reason it is refused.
	 */
	public static function check( string $url ) {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new \WP_Error( 'axle_license_tracker_url', __( 'Enter the full address of the site, such as https://client.example.com.', 'axle-license-tracker' ) );
		}

		$allow  = self::allow_private( $url );
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( trim( (string) $parts['host'], '[]' ) );

		if ( 'https' !== $scheme && ! ( $allow && 'http' === $scheme ) ) {
			return new \WP_Error( 'axle_license_tracker_url', __( 'Only https addresses are allowed, so the password is never sent in the clear.', 'axle-license-tracker' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'axle_license_tracker_url', __( 'Leave the user name and password out of the address. Enter them in their own fields.', 'axle-license-tracker' ) );
		}

		$clean = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . rtrim( (string) ( $parts['path'] ?? '' ), '/' ) . '/';

		if ( ! $allow ) {
			$private = new \WP_Error( 'axle_license_tracker_url', __( 'That address points inside a private network or to this server, so the hub will not call it.', 'axle-license-tracker' ) );
			if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) || '.local' === substr( $host, -6 ) || '.internal' === substr( $host, -9 ) ) {
				return $private;
			}
			if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) && false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $private;
			}
			// Resolves the name and refuses private results, as core does for its own safe requests.
			if ( ! wp_http_validate_url( $clean ) ) {
				return $private;
			}
		}

		return $clean;
	}
}
