<?php
/**
 * The hub: syncing the sites on a schedule and on demand.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Hub;

use AryanX\Axle\LicenseTracker\Report;
use AryanX\Axle\LicenseTracker\Service;
use AryanX\Axle\LicenseTracker\Settings;
use AryanX\Axle\LicenseTracker\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * Any site with the plugin can be a hub (a setting, off by default). An
 * hourly cron event syncs the sites that are due, a few at a time. Each site
 * is fetched about once a day, at a moment of its own, and a site that fails
 * is retried later and later. Everything is read only.
 */
class Hub implements Service {

	public const CRON = 'axle_license_tracker_hub_sync';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sites.
	 *
	 * @var Sites
	 */
	private Sites $sites;

	/**
	 * The client.
	 *
	 * @var Remote
	 */
	private Remote $remote;

	/**
	 * This site's rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Sets up the hub.
	 *
	 * @param Settings $settings Settings.
	 * @param Sites    $sites    Sites.
	 * @param Remote   $remote   The client.
	 * @param Tracker  $tracker  This site's rows.
	 */
	public function __construct( Settings $settings, Sites $sites, Remote $remote, Tracker $tracker ) {
		$this->settings = $settings;
		$this->sites    = $sites;
		$this->remote   = $remote;
		$this->tracker  = $tracker;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( self::CRON, array( $this, 'cron' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * The sites.
	 *
	 * @return Sites
	 */
	public function sites(): Sites {
		return $this->sites;
	}

	/**
	 * Whether this site is a hub.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		/**
		 * Filters whether this site acts as a hub. Default the saved setting.
		 *
		 * @param bool $enabled Whether the hub is on.
		 */
		return (bool) apply_filters( 'axle_license_tracker_hub_enabled', (bool) $this->settings->get( 'hub' ) );
	}

	/**
	 * Turns the hub on or off. The sites and their data stay while it is off.
	 *
	 * @param bool $enabled On or off.
	 */
	public function set_enabled( bool $enabled ): void {
		$was = (bool) $this->settings->get( 'hub' );
		$this->settings->update( array( 'hub' => $enabled ) );
		$this->ensure_scheduled();

		if ( $was !== $enabled ) {
			/**
			 * Fires after the hub was turned on or off.
			 *
			 * @param bool $enabled Whether it is on now.
			 */
			do_action( 'axle_license_tracker_hub_toggled', $enabled );
		}
	}

	/**
	 * Keeps the hourly event in line with the setting.
	 */
	public function ensure_scheduled(): void {
		$next = wp_next_scheduled( self::CRON );
		if ( $this->enabled() && ! $next ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		} elseif ( ! $this->enabled() && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * The scheduled run.
	 */
	public function cron(): void {
		if ( $this->enabled() ) {
			$this->sync_due();
		}
	}

	/**
	 * Seconds between two good syncs of one site.
	 *
	 * @return int
	 */
	public static function interval(): int {
		/**
		 * Filters the seconds between two syncs of one site.
		 *
		 * @param int $seconds Default one day, from one hour to a week.
		 */
		return max( HOUR_IN_SECONDS, min( WEEK_IN_SECONDS, (int) apply_filters( 'axle_license_tracker_hub_interval', DAY_IN_SECONDS ) ) );
	}

	/**
	 * When a site is due after a sync: the interval plus a delay of its own, so
	 * the sites are not all fetched in the same minute.
	 *
	 * @param string $id    Site id.
	 * @param int    $fails Failures in a row, 0 after a good sync.
	 * @param int    $now   Now.
	 * @return int Timestamp.
	 */
	public static function next_time( string $id, int $fails, int $now ): int {
		if ( $fails > 0 ) {
			/**
			 * Filters the seconds to wait after a failed sync.
			 *
			 * @param int    $seconds Default one hour doubled for each failure in a row, at most one day.
			 * @param int    $fails   Failures in a row.
			 * @param string $id      Site id.
			 */
			$wait = (int) apply_filters( 'axle_license_tracker_hub_backoff', min( DAY_IN_SECONDS, HOUR_IN_SECONDS * ( 2 ** min( 5, $fails - 1 ) ) ), $fails, $id );

			return $now + max( 5 * MINUTE_IN_SECONDS, $wait );
		}

		$interval = self::interval();

		return $now + $interval + ( crc32( $id ) % max( 1, intdiv( $interval, 4 ) ) );
	}

	/**
	 * Fetches one site and keeps the result.
	 *
	 * @param string $id Site id.
	 * @return array|\WP_Error The site after the sync, or why it failed.
	 */
	public function sync( string $id ) {
		$site = $this->sites->get( $id );
		if ( ! $site ) {
			return new \WP_Error( 'axle_license_tracker_unknown_site', __( 'That site is not in the hub.', 'axle-license-tracker' ) );
		}

		$now    = time();
		$report = $this->remote->fetch( $site );

		if ( is_wp_error( $report ) ) {
			$fails = $site['fails'] + 1;
			$this->sites->update(
				$id,
				array(
					'tried' => $now,
					'fails' => $fails,
					'error' => $report->get_error_message(),
					'next'  => self::next_time( $id, $fails, $now ),
				)
			);

			/**
			 * Fires when a remote site could not be fetched.
			 *
			 * @param string    $id     Site id.
			 * @param \WP_Error $error  Why, in words.
			 * @param int       $fails  Failures in a row.
			 */
			do_action( 'axle_license_tracker_hub_sync_failed', $id, $report, $fails );

			return $report;
		}

		$report['fetched'] = $now;
		$this->sites->save_data( $id, $report );
		$this->sites->update(
			$id,
			array(
				'tried' => $now,
				'ok'    => $now,
				'fails' => 0,
				'error' => '',
				'count' => count( $report['components'] ),
				'next'  => self::next_time( $id, 0, $now ),
			)
		);

		/**
		 * Fires after a remote site was fetched.
		 *
		 * @param string $id     Site id.
		 * @param array  $report The cleaned report: name, plugin, generated, fetched, components.
		 */
		do_action( 'axle_license_tracker_hub_synced', $id, $report );

		return (array) $this->sites->get( $id );
	}

	/**
	 * Syncs the sites that are due, a few per run.
	 *
	 * @param bool $force Sync every site, not only the due ones.
	 * @return array<string, array|\WP_Error> Result per site id.
	 */
	public function sync_due( bool $force = false ): array {
		/**
		 * Filters how many sites one run syncs. The rest wait for the next run.
		 *
		 * @param int $batch Default 5, from 1 to 25.
		 */
		$batch = max( 1, min( 25, (int) apply_filters( 'axle_license_tracker_hub_batch', 5 ) ) );
		$now   = time();
		$due   = array();
		foreach ( $this->sites->all() as $id => $site ) {
			if ( $force || $site['next'] <= $now ) {
				$due[ $id ] = $site['tried'];
			}
		}
		asort( $due );

		$results = array();
		foreach ( array_slice( array_keys( $due ), 0, $batch ) as $id ) {
			$results[ (string) $id ] = $this->sync( (string) $id );
		}

		// A forced sync of many sites finishes in the background.
		if ( $force && count( $due ) > $batch ) {
			foreach ( array_slice( array_keys( $due ), $batch ) as $id ) {
				$this->sites->update( (string) $id, array( 'next' => $now ) );
			}
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_single_event( $now + 30, self::CRON );
			}
		}

		return $results;
	}

	/**
	 * What every site reported, for the overview.
	 *
	 * @return array<string, array> Per site id: label, url, ok, error, fetched, components.
	 */
	public function sources(): array {
		$sources = array();
		foreach ( $this->sites->all() as $id => $site ) {
			$data           = $this->sites->data( $id );
			$sources[ $id ] = array(
				'id'         => $id,
				'label'      => $site['label'],
				'url'        => $site['url'],
				'ok'         => $site['ok'],
				'fails'      => $site['fails'],
				'error'      => $site['error'],
				'tried'      => $site['tried'],
				'fetched'    => (int) ( $data['fetched'] ?? 0 ),
				'components' => (array) ( $data['components'] ?? array() ),
			);
		}

		return $sources;
	}

	/**
	 * Every tracked license that is not ignored: this site's, and the remote
	 * sites' when asked.
	 *
	 * @param bool $remote Include what the remote sites last reported.
	 * @return array[] Combined rows (see Overview::rows()).
	 */
	public function rows( bool $remote = true ): array {
		$local = array();
		foreach ( $this->tracker->tracked() as $row ) {
			if ( ! $row['ignored'] ) {
				$local[] = Report::component( $row );
			}
		}

		return Overview::rows( $local, $remote ? $this->sources() : array(), (string) get_bloginfo( 'name' ) );
	}

	/**
	 * Rows that belong in a cost view: premium or bundled licenses, and anything with a cost entered.
	 *
	 * @param array[] $rows Combined rows.
	 * @return array[]
	 */
	public static function billable( array $rows ): array {
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return in_array( $row['type'] ?? '', array( 'premium' ), true ) || null !== ( $row['cost'] ?? null ) || '' !== ( $row['renewal'] ?? '' );
				}
			)
		);
	}
}
