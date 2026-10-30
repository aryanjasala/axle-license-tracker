<?php
/**
 * Puts the detection layers together into one row per component.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

use AryanX\Axle\LicenseTracker\Adapters\LicenseState;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the rows the screen, the report, the abilities and WP-CLI show, and
 * keeps the per-component records in step: last state and risk, when they
 * changed, and the key fingerprint.
 *
 * Stored vendor status is last-checked truth, not live truth, so each row
 * carries when that status was checked (or first read here).
 */
class Tracker {

	public const STATES = array( 'valid', 'expiring', 'expired', 'invalid', 'missing', 'unknown', 'not-applicable' );

	/**
	 * Installed components.
	 *
	 * @var Inventory
	 */
	private Inventory $inventory;

	/**
	 * Detection store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Update transient reader.
	 *
	 * @var Updates
	 */
	private Updates $updates;

	/**
	 * Fingerprint scan.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Layer 1.
	 *
	 * @var Classifier
	 */
	private Classifier $classifier;

	/**
	 * Adapters.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Known vendors.
	 *
	 * @var Vendors
	 */
	private Vendors $vendors;

	/**
	 * Per-component records.
	 *
	 * @var Licenses
	 */
	private Licenses $licenses;

	/**
	 * Bundled plugins.
	 *
	 * @var Bundles
	 */
	private Bundles $bundles;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Rows built in this request.
	 *
	 * @var array<string, array>|null
	 */
	private ?array $rows = null;

	/**
	 * Sets up the tracker.
	 *
	 * @param Inventory  $inventory  Installed components.
	 * @param Store      $store      Detection store.
	 * @param Updates    $updates    Update transient reader.
	 * @param Scanner    $scanner    Fingerprint scan.
	 * @param Classifier $classifier Layer 1.
	 * @param Registry   $registry   Adapters.
	 * @param Vendors    $vendors    Known vendors.
	 * @param Licenses   $licenses   Per-component records.
	 * @param Bundles    $bundles    Bundled plugins.
	 * @param Settings   $settings   Settings.
	 */
	public function __construct( Inventory $inventory, Store $store, Updates $updates, Scanner $scanner, Classifier $classifier, Registry $registry, Vendors $vendors, Licenses $licenses, Bundles $bundles, Settings $settings ) {
		$this->inventory  = $inventory;
		$this->store      = $store;
		$this->updates    = $updates;
		$this->scanner    = $scanner;
		$this->classifier = $classifier;
		$this->registry   = $registry;
		$this->vendors    = $vendors;
		$this->licenses   = $licenses;
		$this->bundles    = $bundles;
		$this->settings   = $settings;
	}

	/**
	 * The reminder window: the largest reminder day. A license that renews
	 * within it counts as expiring.
	 *
	 * @return int[] Reminder days, largest first.
	 */
	public function reminder_days(): array {
		/**
		 * Filters the days before a renewal date when reminders go out.
		 *
		 * @param int[] $days Default the saved setting, 30, 14, 7 and 1.
		 */
		$days = Settings::days( (array) apply_filters( 'axle_license_tracker_reminder_days', $this->settings->get( 'reminder_days' ) ) );

		return $days ? $days : Settings::defaults()['reminder_days'];
	}

	/**
	 * Every installed component as a row, keyed by component id.
	 *
	 * @return array<string, array>
	 */
	public function rows(): array {
		if ( null !== $this->rows ) {
			return $this->rows;
		}

		$now        = time();
		$components = $this->inventory->all();
		$versions   = $this->store->track_versions( $components, $now );
		$bundled    = $this->bundles->plugins();
		$bundler    = $this->bundles->theme_name();
		$window     = max( $this->reminder_days() );
		$today      = current_time( 'Y-m-d' );

		$rows = array();
		foreach ( $components as $id => $component ) {
			$rows[ $id ] = $this->build( $component, $versions[ $id ] ?? array(), $bundled, $bundler, $window, $today, $now );
		}

		uasort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$this->rows = $rows;

		return $rows;
	}

	/**
	 * Premium and bundled rows, plus any row with details entered by hand.
	 *
	 * @return array<string, array>
	 */
	public function tracked(): array {
		return array_filter(
			$this->rows(),
			static function ( array $row ): bool {
				return $row['tracked'];
			}
		);
	}

	/**
	 * One row.
	 *
	 * @param string $id Component id.
	 * @return array|null
	 */
	public function row( string $id ): ?array {
		$rows = $this->rows();

		return $rows[ $id ] ?? null;
	}

	/**
	 * Forgets rows built in this request, after something changed.
	 */
	public function flush(): void {
		$this->rows = null;
		$this->inventory->flush();
		$this->licenses->flush();
		$this->store->reload();
	}

	/**
	 * Saves the current state and risk of every tracked row and fires the change actions.
	 *
	 * @return array<string, array> The rows.
	 */
	public function sync(): array {
		$rows = $this->rows();
		$now  = time();

		foreach ( $rows as $id => $row ) {
			if ( ! $row['tracked'] ) {
				continue;
			}
			$this->licenses->ensure( $id );
			$this->licenses->set_meta( $id, '_alc_key_fp', '' !== $row['fingerprint'] ? $row['fingerprint'] : null );

			$old_state = (string) $this->licenses->meta( $id, '_alc_state', '' );
			if ( $old_state !== $row['state'] ) {
				$this->licenses->set_meta( $id, '_alc_state', $row['state'] );
				$this->licenses->set_meta( $id, '_alc_state_since', $now );
				$this->licenses->set_meta( $id, '_alc_alerts_sent', null );

				/**
				 * Fires when a component's license state changes. Old is '' the first time it is seen.
				 *
				 * @param array  $row Component row.
				 * @param string $old Previous state.
				 * @param string $new New state.
				 */
				do_action( 'axle_license_tracker_state_changed', $row, $old_state, $row['state'] );

				if ( 'expired' === $row['state'] ) {
					/**
					 * Fires when a component's license is found expired.
					 *
					 * @param array $row Component row.
					 */
					do_action( 'axle_license_tracker_license_expired', $row );
				}
			}

			$old_risk = (string) $this->licenses->meta( $id, '_alc_risk', '' );
			if ( $old_risk !== $row['risk'] ) {
				$this->licenses->set_meta( $id, '_alc_risk', $row['risk'] );

				/**
				 * Fires when a component's update risk changes. Old is '' the first time it is seen.
				 *
				 * @param array  $row Component row.
				 * @param string $old Previous risk.
				 * @param string $new New risk.
				 */
				do_action( 'axle_license_tracker_risk_changed', $row, $old_risk, $row['risk'] );
			}
		}

		// The records changed; rows read them back.
		$this->rows = null;
		$this->licenses->flush();
		$rows = $this->rows();

		/**
		 * Fires after a detection run, with every component row.
		 *
		 * @param array<string, array> $rows Rows keyed by component id.
		 */
		do_action( 'axle_license_tracker_detected', $rows );

		return $rows;
	}

	/**
	 * Re-reads everything: asks WordPress for updates (as core would on its
	 * own schedule), runs the fingerprint scan for up to 20 seconds, and syncs.
	 *
	 * @return array<string, array> The rows.
	 */
	public function recheck(): array {
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		$this->inventory->flush();
		// Like core's own "Check again": the next check asks right away instead of within 12 hours.
		foreach ( array( 'update_plugins', 'update_themes' ) as $transient ) {
			$current = get_site_transient( $transient );
			if ( is_object( $current ) && ! empty( $current->last_checked ) ) {
				$current->last_checked = 0;
				set_site_transient( $transient, $current );
			}
		}
		wp_update_plugins();
		wp_update_themes();

		$this->store->reload();
		$this->scanner->scan_pending( 0, 20 );
		if ( $this->scanner->pending() ) {
			$this->scanner->schedule();
		}

		$this->flush();

		return $this->sync();
	}

	/**
	 * Ignores a component, or stops ignoring it.
	 *
	 * @param string $id      Component id.
	 * @param bool   $ignore  Ignore or not.
	 * @param string $note    Why.
	 * @param int    $user_id Who.
	 */
	public function ignore( string $id, bool $ignore, string $note, int $user_id ): void {
		if ( $ignore ) {
			$this->licenses->set_meta(
				$id,
				'_alc_ignored',
				array(
					'note' => mb_substr( sanitize_textarea_field( $note ), 0, 500 ),
					'by'   => $user_id,
					'at'   => time(),
				)
			);
		} else {
			$this->licenses->set_meta( $id, '_alc_ignored', null );
		}
		$this->rows = null;
		$this->licenses->flush();

		/**
		 * Fires when a component is ignored or tracked again.
		 *
		 * @param string $id      Component id.
		 * @param bool   $ignored Whether it is ignored now.
		 * @param string $note    Why.
		 * @param int    $user_id Who.
		 */
		do_action( 'axle_license_tracker_ignored', $id, $ignore, $note, $user_id );
	}

	/**
	 * Summary numbers over tracked rows that are not ignored.
	 *
	 * @param array<string, array> $rows Rows.
	 * @return array{premium: int, expired: int, expiring: int, at_risk: int, blocked: int, missing: int, costs: array<string, int>}
	 */
	public static function totals( array $rows ): array {
		$totals = array(
			'premium'  => 0,
			'expired'  => 0,
			'expiring' => 0,
			'at_risk'  => 0,
			'blocked'  => 0,
			'missing'  => 0,
			'costs'    => array(),
		);
		foreach ( $rows as $row ) {
			if ( ! $row['tracked'] || $row['ignored'] ) {
				continue;
			}
			if ( in_array( $row['type'], array( 'premium', 'bundled' ), true ) ) {
				++$totals['premium'];
			}
			if ( 'expired' === $row['state'] ) {
				++$totals['expired'];
			}
			if ( 'expiring' === $row['state'] ) {
				++$totals['expiring'];
			}
			if ( 'missing' === $row['state'] ) {
				++$totals['missing'];
			}
			if ( in_array( $row['risk'], array( 'at-risk', 'blocked' ), true ) ) {
				++$totals['at_risk'];
			}
			if ( 'blocked' === $row['risk'] ) {
				++$totals['blocked'];
			}
			if ( null !== $row['cost'] && '' !== $row['currency'] ) {
				$yearly = Details::yearly( (int) $row['cost'], $row['cycle'] );
				if ( null !== $yearly ) {
					$totals['costs'][ $row['currency'] ] = ( $totals['costs'][ $row['currency'] ] ?? 0 ) + $yearly;
				}
			}
		}
		ksort( $totals['costs'] );

		return $totals;
	}

	/**
	 * Builds one row.
	 *
	 * @param array                 $component Component.
	 * @param array                 $version   Tracked version.
	 * @param array<string, string> $bundled   Bundled plugins.
	 * @param string                $bundler   Theme that bundles them.
	 * @param int                   $window    Days before a renewal that count as expiring.
	 * @param string                $today     Today, Y-m-d in the site's time zone.
	 * @param int                   $now       Current time.
	 * @return array
	 */
	private function build( array $component, array $version, array $bundled, string $bundler, int $window, string $today, int $now ): array {
		$id        = (string) $component['id'];
		$detection = $this->classifier->classify( $component, $bundled, $bundler );
		$adapter   = '' !== $detection['adapter'] ? $this->registry->get( $detection['adapter'] ) : null;

		if ( $adapter ) {
			$license = $adapter->read( $component )->to_array();
		} elseif ( 'premium' === $detection['type'] ) {
			$license = ( new LicenseState(
				array(
					'state' => 'unknown',
					'note'  => __( 'No adapter reads this vendor\'s license. Add the renewal date by hand.', 'axle-license-tracker' ),
				)
			) )->to_array();
		} else {
			$license          = ( new LicenseState() )->to_array();
			$license['state'] = 'not-applicable';
			if ( 'bundled' === $detection['type'] ) {
				/* translators: %s: theme name. */
				$license['note'] = sprintf( __( 'Licensed and updated through %s.', 'axle-license-tracker' ), $detection['bundled_by'] );
			}
		}

		/**
		 * Filters a component's license state before expiry dates are applied.
		 *
		 * @param string $state     valid, expired, invalid, missing, unknown or not-applicable.
		 * @param array  $component Component.
		 * @param array  $license   What the adapter read: expires, checked, latest, note, ...
		 */
		$state = apply_filters( 'axle_license_tracker_license_state', $license['state'], $component, $license );
		$state = in_array( $state, self::STATES, true ) ? $state : $license['state'];

		$details  = $this->licenses->details( $id );
		$has_post = (bool) $this->licenses->find( $id );
		$sources  = array();
		$pick     = static function ( string $field, $manual, $detected ) use ( &$sources ) {
			if ( null !== $manual && '' !== $manual ) {
				$sources[ $field ] = 'manual';
				return $manual;
			}
			if ( null !== $detected && '' !== $detected && 0 !== $detected ) {
				$sources[ $field ] = 'detected';
				return $detected;
			}
			return '';
		};

		$vendor     = $pick( 'vendor', $details['vendor'] ?? null, $detection['vendor'] );
		$renewal    = $pick( 'renewal', $details['renewal'] ?? null, 'lifetime' === $license['expires'] ? '' : $license['expires'] );
		$auto_renew = $pick( 'auto_renew', $details['auto_renew'] ?? null, $license['auto_renew'] );
		$seats      = $pick( 'seats', $details['seats'] ?? null, $license['seats'] );
		$cycle      = (string) ( $details['cycle'] ?? ( 'lifetime' === $license['expires'] ? 'lifetime' : '' ) );

		// Expiring: a valid or unknown license that renews within the reminder window.
		$days_left = null;
		if ( '' !== $renewal ) {
			$days_left = (int) floor( ( strtotime( $renewal . ' 00:00:00 UTC' ) - strtotime( $today . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
		}
		if ( in_array( $state, array( 'valid', 'unknown' ), true ) && null !== $days_left && $days_left >= 0 && $days_left <= $window && 'lifetime' !== $cycle ) {
			$state = 'expiring';
		}

		$risk = Risk::assess( $component, $detection, $license, $version, $this->updates->last_seen( $id ), $now );

		$ignored = $this->licenses->meta( $id, '_alc_ignored', array() );
		$ignored = is_array( $ignored ) ? $ignored : array();
		$since   = (int) $this->licenses->meta( $id, '_alc_state_since', 0 );

		$source_key = (string) ( $details['purchase_source'] ?? '' );
		$purchase   = Details::purchase_sources();
		$row        = array(
			'id'              => $id,
			'kind'            => $component['kind'],
			'file'            => $component['file'],
			'slug'            => $component['slug'],
			'name'            => $component['name'],
			'version'         => $component['version'],
			'active'          => (bool) $component['active'],
			'type'            => $detection['type'],
			'tracked'         => in_array( $detection['type'], array( 'premium', 'bundled' ), true ) || ( $has_post && ! empty( $details ) ),
			'vendor'          => (string) $vendor,
			'sdk'             => $detection['sdk'],
			'source'          => $detection['source'],
			'adapter'         => $detection['adapter'],
			'bundled_by'      => $detection['bundled_by'],
			'state'           => $state,
			'state_note'      => $license['note'],
			'as_of'           => $license['checked'] ? $license['checked'] : ( $since ? $since : $now ),
			'as_of_source'    => $license['checked'] ? 'vendor' : 'first-read',
			'stale'           => $license['stale'],
			'expires'         => $license['expires'],
			'latest'          => $license['latest'],
			'risk'            => $risk['risk'],
			'risk_signals'    => $risk['signals'],
			'renewal'         => (string) $renewal,
			'days_left'       => $days_left,
			'cycle'           => $cycle,
			'cost'            => isset( $details['cost'] ) ? (int) $details['cost'] : null,
			'currency'        => (string) ( $details['currency'] ?? '' ),
			'auto_renew'      => (string) $auto_renew,
			'seats'           => '' === $seats ? 0 : (int) $seats,
			'seats_used'      => $license['seats_used'],
			'plan'            => (string) ( $details['plan'] ?? '' ),
			'purchase_source' => $source_key,
			'purchase_flag'   => '' !== $source_key && ! empty( $purchase[ $source_key ]['flag'] ),
			'owner'           => (string) ( $details['owner'] ?? '' ),
			'owner_email'     => (string) ( $details['owner_email'] ?? '' ),
			'account_url'     => (string) ( $details['account_url'] ?? '' ),
			'account_email'   => (string) ( $details['account_email'] ?? '' ),
			'invoice_url'     => (string) ( $details['invoice_url'] ?? '' ),
			'notes'           => (string) ( $details['notes'] ?? '' ),
			'sources'         => $sources,
			'fingerprint'     => $license['fingerprint'],
			'ignored'         => ! empty( $ignored ),
			'ignore_note'     => (string) ( $ignored['note'] ?? '' ),
			'scanned'         => $detection['scanned'],
			'license_url'     => '',
		);

		$url = $adapter ? $adapter->license_url( $component ) : '';
		if ( '' === $url ) {
			$vendor_entry = $this->vendors->match( $component );
			$url          = $vendor_entry ? $vendor_entry['license_url'] : (string) $component['uri'];
		}

		/**
		 * Filters where a component's license is managed.
		 *
		 * @param string $url URL: the vendor's license screen in wp-admin, its account page or the plugin's site.
		 * @param array  $row Component row.
		 */
		$row['license_url'] = esc_url_raw( (string) apply_filters( 'axle_license_tracker_vendor_license_url', $url, $row ) );

		return $row;
	}
}
