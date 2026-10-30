<?php
/**
 * Plugin settings.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * The axle_license_tracker_settings option: reminder days, extra recipients,
 * alerts and the monthly digest.
 */
final class Settings {

	public const OPTION = 'axle_license_tracker_settings';

	/**
	 * Defaults.
	 *
	 * @return array{reminder_days: int[], recipients: string[], alerts: bool, digest: bool}
	 */
	public static function defaults(): array {
		return array(
			'reminder_days' => array( 30, 14, 7, 1 ),
			'recipients'    => array(),
			'alerts'        => true,
			'digest'        => false,
		);
	}

	/**
	 * All settings, with defaults for anything missing.
	 *
	 * @return array{reminder_days: int[], recipients: string[], alerts: bool, digest: bool}
	 */
	public function all(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Saves settings, merged over the current ones.
	 *
	 * @param array $changes Settings to change.
	 * @return array The saved settings.
	 */
	public function update( array $changes ): array {
		$settings = self::sanitize( array_merge( $this->all(), $changes ) );
		update_option( self::OPTION, $settings, true );

		return $settings;
	}

	/**
	 * Cleans settings from a form, an option or a command.
	 *
	 * @param mixed $raw Raw settings.
	 * @return array{reminder_days: int[], recipients: string[], alerts: bool, digest: bool}
	 */
	public static function sanitize( $raw ): array {
		$defaults = self::defaults();
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		$days = $raw['reminder_days'] ?? $defaults['reminder_days'];
		if ( is_string( $days ) ) {
			$days = preg_split( '/[\s,]+/', $days );
		}
		$days = self::days( (array) $days );

		$recipients = $raw['recipients'] ?? array();
		if ( is_string( $recipients ) ) {
			$recipients = preg_split( '/[\s,]+/', $recipients );
		}
		$recipients = array_values( array_unique( array_filter( array_map( 'sanitize_email', array_map( 'strval', (array) $recipients ) ), 'is_email' ) ) );

		return array(
			'reminder_days' => $days ? $days : $defaults['reminder_days'],
			'recipients'    => array_slice( $recipients, 0, 20 ),
			'alerts'        => isset( $raw['alerts'] ) ? (bool) $raw['alerts'] : $defaults['alerts'],
			'digest'        => isset( $raw['digest'] ) ? (bool) $raw['digest'] : $defaults['digest'],
		);
	}

	/**
	 * Reminder days: whole days from 0 to 365, unique, largest first, at most 10.
	 *
	 * @param array $days Raw days.
	 * @return int[]
	 */
	public static function days( array $days ): array {
		$clean = array();
		foreach ( $days as $day ) {
			if ( is_numeric( $day ) && (int) $day >= 0 && (int) $day <= 365 ) {
				$clean[] = (int) $day;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		rsort( $clean );

		return array_slice( $clean, 0, 10 );
	}
}
