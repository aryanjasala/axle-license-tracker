<?php
/**
 * CSV and JSON exports.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * The tracked components as CSV or JSON. No keys: only fingerprints.
 */
class Export {

	/**
	 * The report.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Sets up exports.
	 *
	 * @param Report $report The report.
	 */
	public function __construct( Report $report ) {
		$this->report = $report;
	}

	/**
	 * The export as a string.
	 *
	 * @param string $format csv or json.
	 * @return string
	 */
	public function render( string $format ): string {
		$report = $this->report->build( false, true );
		if ( 'json' === $format ) {
			return (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		return self::csv( (array) $report['components'] );
	}

	/**
	 * Report components as CSV.
	 *
	 * @param array[] $components Report entries.
	 * @return string
	 */
	public static function csv( array $components ): string {
		$columns = array_merge( array_diff( Report::FIELDS, array( 'sources', 'risk_signals' ) ), Report::PRIVATE_FIELDS );
		$lines   = array( self::line( $columns ) );
		foreach ( $components as $component ) {
			$cells = array();
			foreach ( $columns as $column ) {
				$value = $component[ $column ] ?? '';
				if ( 'cost' === $column && is_numeric( $value ) ) {
					$value = Details::from_minor( (int) $value, (string) ( $component['currency'] ?? '' ) );
				}
				$cells[] = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : ( is_scalar( $value ) ? (string) $value : '' );
			}
			$lines[] = self::line( $cells );
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One CSV line. Cells that a spreadsheet would run as a formula get a quote in front.
	 *
	 * @param string[] $cells Cells.
	 * @return string
	 */
	private static function line( array $cells ): string {
		return implode(
			',',
			array_map(
				static function ( string $cell ): string {
					if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $cell ) ) {
						$cell = "'" . $cell;
					}
					return '"' . str_replace( '"', '""', $cell ) . '"';
				},
				$cells
			)
		);
	}

	/**
	 * File name for a download.
	 *
	 * @param string $format csv or json.
	 * @return string
	 */
	public static function filename( string $format ): string {
		return 'licenses-' . sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.' . ( 'json' === $format ? 'json' : 'csv' );
	}
}
