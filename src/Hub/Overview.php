<?php
/**
 * The hub's combined view: rows, totals and duplicate keys across sites.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Hub;

use AryanX\Axle\LicenseTracker\Details;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over rows. A row from the hub has the fields of
 * Remote::component() plus `site` (the site id) and `site_label`; rows of this
 * site use the same fields from the report.
 */
final class Overview {

	/**
	 * Id of this site in the combined view.
	 */
	public const LOCAL = 'local';

	/**
	 * The combined rows: this site's, then each remote site's.
	 *
	 * @param array[] $local   This site's report entries (Report::component), or none.
	 * @param array[] $sources Per site: id, label, components (see Hub::sources()).
	 * @param string  $label   Name of this site.
	 * @param string  $today   Today, Y-m-d. Default today in UTC.
	 * @return array[] Rows, each with site and site_label and days_left.
	 */
	public static function rows( array $local, array $sources, string $label, ?string $today = null ): array {
		$today = $today ?? gmdate( 'Y-m-d' );
		$rows  = array();
		foreach ( $local as $entry ) {
			$rows[] = self::row( (array) $entry, self::LOCAL, $label, $today );
		}
		foreach ( $sources as $id => $source ) {
			foreach ( (array) $source['components'] as $entry ) {
				$rows[] = self::row( (array) $entry, (string) $id, (string) $source['label'], $today );
			}
		}

		return $rows;
	}

	/**
	 * One row.
	 *
	 * @param array  $entry Component fields.
	 * @param string $site  Site id.
	 * @param string $label Site name.
	 * @param string $today Today, Y-m-d.
	 * @return array
	 */
	private static function row( array $entry, string $site, string $label, string $today ): array {
		$renewal = (string) ( $entry['renewal'] ?? '' );
		$days    = null;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $renewal ) ) {
			$days = (int) floor( ( strtotime( $renewal . ' 00:00:00 UTC' ) - strtotime( $today . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
		}

		return array_merge(
			array(
				'id'          => '',
				'name'        => '',
				'version'     => '',
				'vendor'      => '',
				'state'       => 'unknown',
				'risk'        => '',
				'renewal'     => '',
				'cycle'       => '',
				'cost'        => null,
				'currency'    => '',
				'auto_renew'  => '',
				'seats'       => 0,
				'seats_used'  => 0,
				'fingerprint' => '',
				'expires'     => '',
			),
			$entry,
			array(
				'site'       => $site,
				'site_label' => $label,
				'days_left'  => $days,
			)
		);
	}

	/**
	 * Keys that sit on more than one site.
	 *
	 * A key counts once per site, however many plugins on that site share it.
	 * `limit` is the largest seat count any of its rows reports (0 when no
	 * reader knows it), and `over` is true only when a limit is known and the
	 * number of sites is above it.
	 *
	 * @param array[] $rows Combined rows.
	 * @return array<string, array{fingerprint: string, count: int, sites: string[], names: string[], limit: int, over: bool}> Keyed by fingerprint, most sites first.
	 */
	public static function duplicates( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$fingerprint = (string) $row['fingerprint'];
			if ( '' === $fingerprint ) {
				continue;
			}
			$groups[ $fingerprint ]['sites'][ (string) $row['site'] ] = (string) $row['site_label'];
			$groups[ $fingerprint ]['names'][ (string) $row['name'] ] = (string) $row['name'];
			$groups[ $fingerprint ]['limit']                          = max( (int) ( $groups[ $fingerprint ]['limit'] ?? 0 ), (int) $row['seats'] );
		}

		$found = array();
		foreach ( $groups as $fingerprint => $group ) {
			$count = count( $group['sites'] );
			if ( $count < 2 ) {
				continue;
			}
			$found[ (string) $fingerprint ] = array(
				'fingerprint' => (string) $fingerprint,
				'count'       => $count,
				'sites'       => array_values( $group['sites'] ),
				'names'       => array_values( $group['names'] ),
				'limit'       => (int) $group['limit'],
				'over'        => $group['limit'] > 0 && $count > $group['limit'],
			);
		}
		uasort(
			$found,
			static function ( array $a, array $b ): int {
				return array( (int) $b['over'], $b['count'] ) <=> array( (int) $a['over'], $a['count'] );
			}
		);

		return $found;
	}

	/**
	 * Yearly cost per currency and per vendor. Currencies are never mixed.
	 *
	 * Monthly licenses count twelve times, one-off and lifetime ones not at all
	 * (they do not recur), and a row without a cost or a currency is skipped.
	 *
	 * @param array[] $rows Combined rows.
	 * @return array{currencies: array<string, int>, vendors: array<string, array<string, int>>} Minor units.
	 */
	public static function costs( array $rows ): array {
		$currencies = array();
		$vendors    = array();
		foreach ( $rows as $row ) {
			if ( null === $row['cost'] || '' === $row['currency'] ) {
				continue;
			}
			$yearly = Details::yearly( (int) $row['cost'], (string) $row['cycle'] );
			if ( null === $yearly ) {
				continue;
			}
			$vendor                                 = '' !== $row['vendor'] ? (string) $row['vendor'] : __( 'Unknown vendor', 'axle-license-tracker' );
			$currencies[ $row['currency'] ]         = ( $currencies[ $row['currency'] ] ?? 0 ) + $yearly;
			$vendors[ $vendor ][ $row['currency'] ] = ( $vendors[ $vendor ][ $row['currency'] ] ?? 0 ) + $yearly;
		}
		ksort( $currencies );
		ksort( $vendors );
		foreach ( array_keys( $vendors ) as $vendor ) {
			ksort( $vendors[ $vendor ] );
		}

		return array(
			'currencies' => $currencies,
			'vendors'    => $vendors,
		);
	}

	/**
	 * Rows that renew within some days, or are expired, soonest first.
	 *
	 * @param array[] $rows Combined rows.
	 * @param int     $days Days ahead.
	 * @return array[]
	 */
	public static function expiring( array $rows, int $days ): array {
		$list = array_filter(
			$rows,
			static function ( array $row ) use ( $days ): bool {
				return 'expired' === $row['state'] || ( null !== $row['days_left'] && $row['days_left'] >= 0 && $row['days_left'] <= $days );
			}
		);
		usort(
			$list,
			static function ( array $a, array $b ): int {
				return ( $a['days_left'] ?? -1 ) <=> ( $b['days_left'] ?? -1 );
			}
		);

		return $list;
	}
}
