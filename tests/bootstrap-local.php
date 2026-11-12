<?php
/**
 * Stand-ins this plugin's unit tests need beyond the shared template stubs.
 *
 * @package AryanX\Axle\LicenseTracker
 */

// phpcs:ignoreFile

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}
