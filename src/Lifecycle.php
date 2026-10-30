<?php
/**
 * Activation and deactivation.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the License reporter role and the daily check on activation, and
 * removes the scheduled events on deactivation.
 */
final class Lifecycle {

	public const DAILY = 'axle_license_tracker_daily';
	public const SCAN  = 'axle_license_tracker_scan';

	/**
	 * Runs on activation.
	 *
	 * @param bool $network_wide Whether the plugin is network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		unset( $network_wide );
		self::add_role();
		self::schedule();
	}

	/**
	 * Runs on deactivation. The role stays, so users who have it keep it.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::DAILY );
		wp_clear_scheduled_hook( self::SCAN );
	}

	/**
	 * Adds the License reporter role: read, plus the report capability.
	 */
	public static function add_role(): void {
		if ( ! get_role( Capability::ROLE ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- wpcom_vip_add_role() only exists on VIP; this runs once, on activation.
			add_role(
				Capability::ROLE,
				'License reporter',
				array(
					'read'             => true,
					Capability::REPORT => true,
				)
			);
		}
	}

	/**
	 * Schedules the daily check when it is not scheduled yet.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
	}
}
