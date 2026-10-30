<?php
/**
 * Layer 3: is a component actually getting updates?
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Update risk for premium and bundled components, independent of what the
 * license says:
 *
 * - blocked: WordPress offers a newer version but has no package to download
 * - at-risk: the vendor's own data knows a newer version this site is not offered,
 *   the updater has not answered for longer than the silent days, or a
 *   vulnerability flag came in through the filter
 * - watch: the installed version has not changed for the stale-version days
 *   (a weak signal), or a bundled plugin that only updates with its theme
 * - ok: none of these
 */
class Risk {

	public const LEVELS = array( 'ok', 'watch', 'at-risk', 'blocked' );

	/**
	 * Days without an answer from the updater before a component is at risk.
	 *
	 * @return int Default 30, between 1 and 365.
	 */
	public static function silent_days(): int {
		/**
		 * Filters how long an updater may stay silent before the component is at risk.
		 *
		 * @param int $days Default 30.
		 */
		return max( 1, min( 365, (int) apply_filters( 'axle_license_tracker_silent_days', 30 ) ) );
	}

	/**
	 * Days on the same version before a premium component is worth watching.
	 *
	 * @return int Default 180, between 1 and 3650.
	 */
	public static function stale_version_days(): int {
		/**
		 * Filters how long a premium component may stay on one version before it is flagged.
		 *
		 * @param int $days Default 180.
		 */
		return max( 1, min( 3650, (int) apply_filters( 'axle_license_tracker_stale_version_days', 180 ) ) );
	}

	/**
	 * Works out the risk.
	 *
	 * @param array $component Component.
	 * @param array $detection What the classifier found.
	 * @param array $license   License state as an array (latest is used), or empty.
	 * @param array $version   Tracked version: version, since, first.
	 * @param int   $last_seen When the updater last answered, 0 for never.
	 * @param int   $now       Current time.
	 * @return array{risk: string, signals: string[], flags: string[]}
	 */
	public static function assess( array $component, array $detection, array $license, array $version, int $last_seen, int $now ): array {
		if ( ! in_array( $detection['type'], array( 'premium', 'bundled' ), true ) ) {
			return array(
				'risk'    => '',
				'signals' => array(),
				'flags'   => array(),
			);
		}

		$risk    = 'ok';
		$signals = array();
		$entry   = $detection['entry'];
		$offered = $entry && 'response' === $entry['list'] && '' !== $entry['new_version'] && version_compare( $entry['new_version'], (string) $component['version'], '>' );

		if ( $offered && '' === $entry['package'] ) {
			$risk = 'blocked';
			/* translators: %s: version number. */
			$signals[] = sprintf( __( 'Version %s is out, but this site cannot download it.', 'axle-license-tracker' ), $entry['new_version'] );
		}

		$latest = (string) ( $license['latest'] ?? '' );
		if ( 'blocked' !== $risk && '' !== $latest && version_compare( $latest, (string) $component['version'], '>' ) && ! $offered ) {
			$risk = 'at-risk';
			/* translators: %s: version number. */
			$signals[] = sprintf( __( 'The vendor knows of version %s, but WordPress is not offering it.', 'axle-license-tracker' ), $latest );
		}

		$silent = self::silent_days() * DAY_IN_SECONDS;
		$first  = (int) ( $version['first'] ?? $now );
		if ( 'blocked' !== $risk && $detection['updater'] && 'premium' === $detection['type'] ) {
			if ( $last_seen && $now - $last_seen > $silent ) {
				$risk = 'at-risk';
				/* translators: %d: number of days. */
				$signals[] = sprintf( __( 'The updater has not answered for %d days.', 'axle-license-tracker' ), (int) floor( ( $now - $last_seen ) / DAY_IN_SECONDS ) );
			} elseif ( ! $last_seen && $now - $first > $silent ) {
				$risk = 'at-risk';
				/* translators: %d: number of days. */
				$signals[] = sprintf( __( 'The updater has not answered once in %d days.', 'axle-license-tracker' ), (int) floor( ( $now - $first ) / DAY_IN_SECONDS ) );
			}
		}

		/**
		 * Filters vulnerability flags for a component, for sites that get them from
		 * a scanner such as Patchstack, Wordfence or WPScan. Any flag makes the
		 * component at risk.
		 *
		 * @param string[] $flags     Default none.
		 * @param array    $component Component.
		 */
		$flags = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_license_tracker_vulnerability_flags', array(), $component ) ) ) );
		if ( $flags ) {
			if ( 'blocked' !== $risk ) {
				$risk = 'at-risk';
			}
			foreach ( $flags as $flag ) {
				$signals[] = $flag;
			}
		}

		if ( 'ok' === $risk ) {
			$since = (int) ( $version['since'] ?? $now );
			if ( 'bundled' === $detection['type'] ) {
				$risk = 'watch';
				/* translators: %s: theme name. */
				$signals[] = sprintf( __( 'Updates only arrive with %s updates.', 'axle-license-tracker' ), $detection['bundled_by'] );
			} elseif ( $detection['updater'] && $now - $since > self::stale_version_days() * DAY_IN_SECONDS ) {
				$risk = 'watch';
				/* translators: %d: number of days. */
				$signals[] = sprintf( __( 'Same version for %d days. A weak signal: some plugins just change slowly.', 'axle-license-tracker' ), (int) floor( ( $now - $since ) / DAY_IN_SECONDS ) );
			}
		}

		/**
		 * Filters a component's update risk: ok, watch, at-risk or blocked.
		 *
		 * @param string   $risk      Risk.
		 * @param array    $component Component.
		 * @param string[] $signals   Why.
		 */
		$filtered = apply_filters( 'axle_license_tracker_update_risk', $risk, $component, $signals );

		return array(
			'risk'    => in_array( $filtered, self::LEVELS, true ) ? $filtered : $risk,
			'signals' => $signals,
			'flags'   => $flags,
		);
	}
}
