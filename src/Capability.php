<?php
/**
 * Who may see and change license data.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * The capability for the screen, the actions and the abilities, and the one
 * for the read-only report a hub site fetches.
 */
final class Capability {

	public const REPORT = 'axle_license_tracker_read_report';
	public const ROLE   = 'axle_license_reporter';

	/**
	 * Capability to see Plugins > Licenses and change license details.
	 *
	 * @return string
	 */
	public static function manage(): string {
		/**
		 * Filters who may see and change license data.
		 *
		 * @param string $capability Default manage_options.
		 */
		$capability = trim( (string) apply_filters( 'axle_license_tracker_capability', 'manage_options' ) );

		return '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Capability to read the license report over REST, without any other access.
	 *
	 * @return string
	 */
	public static function report(): string {
		/**
		 * Filters the capability that may read the report at axle-license-tracker/v1/report.
		 *
		 * @param string $capability Default axle_license_tracker_read_report.
		 */
		$capability = trim( (string) apply_filters( 'axle_license_tracker_report_capability', self::REPORT ) );

		return '' !== $capability ? $capability : self::REPORT;
	}

	/**
	 * Whether the current user may manage licenses.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( self::manage() );
	}

	/**
	 * Whether the current user may read the report.
	 *
	 * @return bool
	 */
	public static function can_read_report(): bool {
		return current_user_can( self::manage() ) || current_user_can( self::report() );
	}
}
