<?php
/**
 * What the next twelve months of renewals will cost.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over rows (this site's, or the hub's combined rows). Money
 * stays in minor units and in its own currency, never converted.
 */
final class Forecast {

	/**
	 * The dates a row renews on inside a window.
	 *
	 * A yearly license renews once, a monthly one every month from its date. A
	 * date in the past is rolled forward only for a license that renews by
	 * itself and has not expired; otherwise it needs a new date from a person.
	 *
	 * @param array  $row   Row with renewal, cycle, auto_renew and state.
	 * @param string $today Today, Y-m-d.
	 * @param string $last  Last day of the window, Y-m-d.
	 * @return array{dates: string[], reason: string} reason is '' when dates are usable, else none, past, one-off or lifetime.
	 */
	public static function dates( array $row, string $today, string $last ): array {
		$cycle = (string) ( $row['cycle'] ?? '' );
		if ( in_array( $cycle, array( 'one-off', 'lifetime' ), true ) ) {
			return array(
				'dates'  => array(),
				'reason' => $cycle,
			);
		}

		$renewal = (string) ( $row['renewal'] ?? '' );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $renewal, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return array(
				'dates'  => array(),
				'reason' => 'none',
			);
		}

		$monthly = 'monthly' === $cycle;
		$step    = $monthly ? 1 : 12;
		$year    = (int) $m[1];
		$month   = (int) $m[2];
		$day     = (int) $m[3];
		$date    = $renewal;
		$moved   = 0;

		if ( $date < $today ) {
			if ( 'yes' !== ( $row['auto_renew'] ?? '' ) || 'expired' === ( $row['state'] ?? '' ) ) {
				return array(
					'dates'  => array(),
					'reason' => 'past',
				);
			}
			// Rolls forward from the anchor, keeping its day of the month.
			while ( $date < $today && $moved < 1200 ) {
				$moved += $step;
				$date   = self::add_months( $year, $month, $day, $moved );
			}
		}

		$dates = array();
		$found = 0;
		while ( $date <= $last && $found < 13 ) {
			$dates[] = $date;
			++$found;
			if ( ! $monthly ) {
				break;
			}
			$moved += $step;
			$date   = self::add_months( $year, $month, $day, $moved );
		}

		return array(
			'dates'  => $dates,
			'reason' => '',
		);
	}

	/**
	 * A date some months later, with the day cut back to the month's length.
	 *
	 * @param int $year   Year.
	 * @param int $month  Month, 1 to 12.
	 * @param int $day    Day of the month.
	 * @param int $months Months to add.
	 * @return string Y-m-d.
	 */
	public static function add_months( int $year, int $month, int $day, int $months ): string {
		$index = ( $year * 12 + ( $month - 1 ) ) + $months;
		$y     = intdiv( $index, 12 );
		$mo    = ( $index % 12 ) + 1;
		$d     = min( $day, (int) gmdate( 't', (int) strtotime( sprintf( '%04d-%02d-01 00:00:00 UTC', $y, $mo ) ) ) );

		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
	}

	/**
	 * The last day of the window: the end of the month eleven months on.
	 *
	 * @param string $today  Today, Y-m-d.
	 * @param int    $months Months in the window.
	 * @return string Y-m-d.
	 */
	public static function window_end( string $today, int $months = 12 ): string {
		$ts    = (int) strtotime( $today . ' 00:00:00 UTC' );
		$first = self::add_months( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), 1, max( 1, $months ) );

		return gmdate( 'Y-m-d', (int) strtotime( $first . ' 00:00:00 UTC' ) - DAY_IN_SECONDS );
	}

	/**
	 * Builds the forecast.
	 *
	 * @param array[] $rows   Rows that are tracked and not ignored. Each needs name, vendor, cost, currency, cycle, renewal, auto_renew, state and optionally site_label.
	 * @param string  $today  Today, Y-m-d.
	 * @param int     $months Months, default 12.
	 * @return array{months: array<string, array>, totals: array<string, int>, vendors: array<string, array<string, int>>, one_off: array[], unknown_cost: array[], no_date: array[], first: string, last: string}
	 */
	public static function build( array $rows, string $today, int $months = 12 ): array {
		$months = max( 1, min( 24, $months ) );
		$last   = self::window_end( $today, $months );
		$ts     = (int) strtotime( $today . ' 00:00:00 UTC' );
		$result = array(
			'months'       => array(),
			'totals'       => array(),
			'vendors'      => array(),
			'one_off'      => array(),
			'unknown_cost' => array(),
			'no_date'      => array(),
			'first'        => $today,
			'last'         => $last,
		);
		for ( $i = 0; $i < $months; $i++ ) {
			$key                      = substr( self::add_months( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), 1, $i ), 0, 7 );
			$result['months'][ $key ] = array(
				'totals' => array(),
				'items'  => array(),
			);
		}

		foreach ( $rows as $row ) {
			$item  = array(
				'name'     => (string) $row['name'],
				'site'     => (string) ( $row['site_label'] ?? '' ),
				'vendor'   => '' !== (string) $row['vendor'] ? (string) $row['vendor'] : __( 'Unknown vendor', 'axle-license-tracker' ),
				'cycle'    => (string) $row['cycle'],
				'cost'     => null === $row['cost'] ? null : (int) $row['cost'],
				'currency' => (string) $row['currency'],
				'date'     => (string) $row['renewal'],
			);
			$known = null !== $item['cost'] && '' !== $item['currency'];
			$plan  = self::dates( $row, $today, $last );

			if ( in_array( $plan['reason'], array( 'one-off', 'lifetime' ), true ) ) {
				if ( $known ) {
					$result['one_off'][] = $item;
				}
				continue;
			}
			if ( ! $known ) {
				$result['unknown_cost'][] = $item;
				continue;
			}
			if ( '' !== $plan['reason'] ) {
				$item['reason']      = $plan['reason'];
				$result['no_date'][] = $item;
				continue;
			}

			foreach ( $plan['dates'] as $date ) {
				$key = substr( $date, 0, 7 );
				if ( ! isset( $result['months'][ $key ] ) ) {
					continue;
				}
				$charge                              = array_merge( $item, array( 'date' => $date ) );
				$result['months'][ $key ]['items'][] = $charge;
				$result['months'][ $key ]['totals'][ $item['currency'] ]   = ( $result['months'][ $key ]['totals'][ $item['currency'] ] ?? 0 ) + (int) $item['cost'];
				$result['totals'][ $item['currency'] ]                     = ( $result['totals'][ $item['currency'] ] ?? 0 ) + (int) $item['cost'];
				$result['vendors'][ $item['vendor'] ][ $item['currency'] ] = ( $result['vendors'][ $item['vendor'] ][ $item['currency'] ] ?? 0 ) + (int) $item['cost'];
			}
		}

		ksort( $result['totals'] );
		ksort( $result['vendors'] );
		foreach ( $result['months'] as $key => $month ) {
			ksort( $result['months'][ $key ]['totals'] );
			usort(
				$result['months'][ $key ]['items'],
				static function ( array $a, array $b ): int {
					return strcmp( $a['date'] . $a['name'], $b['date'] . $b['name'] );
				}
			);
		}

		/**
		 * Filters the twelve-month cost forecast.
		 *
		 * @param array  $result The forecast: months, totals, vendors, one_off, unknown_cost, no_date.
		 * @param string $today  Today, Y-m-d.
		 */
		return (array) apply_filters( 'axle_license_tracker_forecast', $result, $today );
	}

	/**
	 * The forecast as CSV lines: one per charge, then the items left out.
	 *
	 * @param array $forecast From build().
	 * @return string
	 */
	public static function csv( array $forecast ): string {
		$lines = array( Export::line( array( 'kind', 'date', 'site', 'product', 'vendor', 'cycle', 'currency', 'amount' ) ) );
		$add   = static function ( string $kind, array $item ) use ( &$lines ): void {
			$lines[] = Export::line(
				array(
					$kind,
					(string) $item['date'],
					(string) $item['site'],
					(string) $item['name'],
					(string) $item['vendor'],
					(string) $item['cycle'],
					(string) $item['currency'],
					null === $item['cost'] ? '' : Details::from_minor( (int) $item['cost'], (string) $item['currency'] ),
				)
			);
		};
		foreach ( $forecast['months'] as $month ) {
			foreach ( $month['items'] as $item ) {
				$add( 'charge', $item );
			}
		}
		foreach ( array(
			'one-off'      => 'one_off',
			'unknown-cost' => 'unknown_cost',
			'no-date'      => 'no_date',
		) as $kind => $key ) {
			foreach ( $forecast[ $key ] as $item ) {
				$add( $kind, $item );
			}
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}
}
