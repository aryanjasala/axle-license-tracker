<?php
/**
 * User-facing labels and messages.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Every label and notice in one filterable list, so a site can reword them.
 */
final class Messages {

	/**
	 * Built list for this request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $cache = null;

	/**
	 * All messages, keyed by name.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$defaults = array(
			// License states.
			'state_valid'          => __( 'Valid', 'axle-license-tracker' ),
			'state_expiring'       => __( 'Expiring', 'axle-license-tracker' ),
			'state_expired'        => __( 'Expired', 'axle-license-tracker' ),
			'state_invalid'        => __( 'Invalid', 'axle-license-tracker' ),
			'state_missing'        => __( 'No key', 'axle-license-tracker' ),
			'state_unknown'        => __( 'Unknown', 'axle-license-tracker' ),
			'state_not-applicable' => __( 'Not needed', 'axle-license-tracker' ),
			// Update risk.
			'risk_ok'              => __( 'Updates arriving', 'axle-license-tracker' ),
			'risk_watch'           => __( 'Watch', 'axle-license-tracker' ),
			'risk_at-risk'         => __( 'At risk', 'axle-license-tracker' ),
			'risk_blocked'         => __( 'Update blocked', 'axle-license-tracker' ),
			// Component types.
			'type_directory'       => __( 'WordPress.org', 'axle-license-tracker' ),
			'type_premium'         => __( 'Premium', 'axle-license-tracker' ),
			'type_bundled'         => __( 'Bundled', 'axle-license-tracker' ),
			'type_custom'          => __( 'Custom code', 'axle-license-tracker' ),
			'type_unknown'         => __( 'Unknown', 'axle-license-tracker' ),
			// Notices after an action.
			'saved'                => __( 'License details saved.', 'axle-license-tracker' ),
			'rechecked'            => __( 'Licenses checked again. Stored license data was read again and WordPress asked for updates.', 'axle-license-tracker' ),
			'ignored'              => __( 'The component is ignored. It stays in the list but gets no reminders.', 'axle-license-tracker' ),
			'unignored'            => __( 'The component is tracked again.', 'axle-license-tracker' ),
			'settings'             => __( 'Settings saved.', 'axle-license-tracker' ),
			'invalid'              => __( 'Nothing was saved. Fix the fields marked below.', 'axle-license-tracker' ),
			'not_found'            => __( 'That component is not installed on this site.', 'axle-license-tracker' ),
			'no_premium'           => __( 'No premium plugins or themes found. Directory plugins get their updates from WordPress.org and need no license.', 'axle-license-tracker' ),
			'gpl_club'             => __( 'Builds from GPL clubs and resellers usually get no official updates.', 'axle-license-tracker' ),
		);

		/**
		 * Filters the labels and messages the plugin shows.
		 *
		 * @param array $messages Messages keyed by name.
		 */
		$filtered = (array) apply_filters( 'axle_license_tracker_messages', $defaults );
		$messages = $defaults;
		foreach ( array_keys( $defaults ) as $key ) {
			if ( isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] ) && '' !== trim( $filtered[ $key ] ) ) {
				$messages[ $key ] = $filtered[ $key ];
			}
		}

		self::$cache = $messages;

		return $messages;
	}

	/**
	 * One message.
	 *
	 * @param string $key Message name.
	 * @return string
	 */
	public static function get( string $key ): string {
		$all = self::all();

		return $all[ $key ] ?? $key;
	}

	/**
	 * Forgets the built list, for tests.
	 */
	public static function reset(): void {
		self::$cache = null;
	}
}
