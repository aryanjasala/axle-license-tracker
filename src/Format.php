<?php
/**
 * Dates the way the site shows them.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the plain Y-m-d dates the plugin stores into text for emails and notices.
 */
final class Format {

	/**
	 * A Y-m-d date in the site's date format, such as "23 November 2026".
	 *
	 * A stored date has no time and no zone, so it is read as noon UTC and
	 * printed in UTC. That keeps the day the same in every site time zone.
	 *
	 * @param string $date Y-m-d. Anything else comes back unchanged.
	 * @return string
	 */
	public static function date( string $date ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		$time = strtotime( $date . ' 12:00:00 UTC' );
		if ( false === $time ) {
			return $date;
		}
		$format = (string) get_option( 'date_format' );

		return wp_date( '' !== $format ? $format : 'F j, Y', $time, new \DateTimeZone( 'UTC' ) );
	}
}
