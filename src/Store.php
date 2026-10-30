<?php
/**
 * Detection results that are kept between requests.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * The axle_license_tracker_detection option (not autoloaded):
 *
 * - `scan`: fingerprint scan results keyed by `component@version`
 * - `seen`: when each component last showed up in the update results
 * - `versions`: the installed version of each component and since when
 * - `digest`: the month the last digest went out
 */
class Store {

	public const OPTION = 'axle_license_tracker_detection';

	/**
	 * Loaded data.
	 *
	 * @var array|null
	 */
	private ?array $data = null;

	/**
	 * All data.
	 *
	 * @return array{scan: array, seen: array, versions: array, digest: string}
	 */
	public function all(): array {
		if ( null === $this->data ) {
			$raw        = get_option( self::OPTION, array() );
			$raw        = is_array( $raw ) ? $raw : array();
			$this->data = array(
				'scan'     => isset( $raw['scan'] ) && is_array( $raw['scan'] ) ? $raw['scan'] : array(),
				'seen'     => isset( $raw['seen'] ) && is_array( $raw['seen'] ) ? $raw['seen'] : array(),
				'versions' => isset( $raw['versions'] ) && is_array( $raw['versions'] ) ? $raw['versions'] : array(),
				'digest'   => isset( $raw['digest'] ) ? (string) $raw['digest'] : '',
			);
		}

		return $this->data;
	}

	/**
	 * One section.
	 *
	 * @param string $section scan, seen, versions or digest.
	 * @return mixed
	 */
	public function get( string $section ) {
		return $this->all()[ $section ] ?? null;
	}

	/**
	 * Replaces one section and saves.
	 *
	 * @param string $section Section.
	 * @param mixed  $value   New value.
	 */
	public function set( string $section, $value ): void {
		$data             = $this->all();
		$data[ $section ] = $value;
		$this->data       = $data;
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Forgets what was loaded, so the next read comes from the database.
	 */
	public function reload(): void {
		$this->data = null;
	}

	/**
	 * Records the installed version of each component, and since when it is installed.
	 *
	 * @param array<string, array> $components Components keyed by id.
	 * @param int                  $now        Current time.
	 * @return array<string, array{version: string, since: int, first: int}>
	 */
	public function track_versions( array $components, int $now ): array {
		$known   = (array) $this->get( 'versions' );
		$next    = array();
		$changed = false;
		foreach ( $components as $id => $component ) {
			$old = isset( $known[ $id ] ) && is_array( $known[ $id ] ) ? $known[ $id ] : null;
			if ( $old && (string) $old['version'] === (string) $component['version'] ) {
				$next[ $id ] = $old;
				continue;
			}
			$next[ $id ] = array(
				'version' => (string) $component['version'],
				'since'   => $now,
				'first'   => $old ? (int) $old['first'] : $now,
			);
			$changed     = true;
		}
		if ( $changed || count( $next ) !== count( $known ) ) {
			$this->set( 'versions', $next );
		}

		return $next;
	}
}
