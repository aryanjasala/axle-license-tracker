<?php
/**
 * The license report: one JSON shape for REST, abilities, WP-CLI and exports.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the report. It never contains a license key, only fingerprints, and
 * leaves email addresses out unless asked (the admin's own export).
 */
class Report {

	/**
	 * Fields of each component in the report.
	 */
	public const FIELDS = array( 'id', 'kind', 'name', 'version', 'active', 'type', 'vendor', 'sdk', 'source', 'state', 'state_note', 'as_of', 'stale', 'expires', 'latest', 'risk', 'risk_signals', 'renewal', 'days_left', 'cycle', 'cost', 'currency', 'auto_renew', 'seats', 'seats_used', 'plan', 'purchase_source', 'owner', 'fingerprint', 'ignored', 'sources' );

	/**
	 * Extra fields in the admin's own export.
	 */
	public const PRIVATE_FIELDS = array( 'owner_email', 'account_url', 'account_email', 'invoice_url', 'notes', 'ignore_note' );

	/**
	 * Rows and totals.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Sets up the report.
	 *
	 * @param Tracker $tracker Rows and totals.
	 */
	public function __construct( Tracker $tracker ) {
		$this->tracker = $tracker;
	}

	/**
	 * The report.
	 *
	 * @param bool $all     Every component, not only tracked ones.
	 * @param bool $full Include emails, URLs and notes (the admin's own export).
	 * @return array
	 */
	public function build( bool $all = false, bool $full = false ): array {
		$rows   = $all ? $this->tracker->rows() : $this->tracker->tracked();
		$totals = Tracker::totals( $rows );

		$report = array(
			'site'       => home_url( '/' ),
			'name'       => get_bloginfo( 'name' ),
			'generated'  => gmdate( 'c' ),
			'plugin'     => VERSION,
			'totals'     => $totals,
			'components' => array_values(
				array_map(
					static function ( array $row ) use ( $full ): array {
						return self::component( $row, $full );
					},
					$rows
				)
			),
		);

		/**
		 * Filters the license report before it is served or exported.
		 *
		 * @param array $report  site, name, generated, plugin, totals and components.
		 * @param bool  $full Whether emails, URLs and notes are included.
		 */
		return (array) apply_filters( 'axle_license_tracker_report', $report, $full );
	}

	/**
	 * One row as a report entry.
	 *
	 * @param array $row     Row.
	 * @param bool  $full Include emails, URLs and notes.
	 * @return array
	 */
	public static function component( array $row, bool $full = false ): array {
		$entry = array();
		foreach ( $full ? array_merge( self::FIELDS, self::PRIVATE_FIELDS ) : self::FIELDS as $field ) {
			$entry[ $field ] = $row[ $field ] ?? null;
		}
		$entry['as_of'] = gmdate( 'c', (int) $row['as_of'] );

		return $entry;
	}
}
