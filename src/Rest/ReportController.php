<?php
/**
 * The read-only report route.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Rest;

use AryanX\Axle\LicenseTracker\Capability;
use AryanX\Axle\LicenseTracker\Report;
use AryanX\Axle\LicenseTracker\Service;

defined( 'ABSPATH' ) || exit;

/**
 * GET axle-license-tracker/v1/report: the same JSON as the get-license-report
 * ability. A user with the License reporter role (read plus the report
 * capability) can fetch it with an application password and nothing else,
 * which is what an agency hub needs.
 */
class ReportController implements Service {

	public const NAMESPACE = 'axle-license-tracker/v1';

	/**
	 * The report.
	 *
	 * @var Report
	 */
	private Report $report;

	/**
	 * Sets up the route.
	 *
	 * @param Report $report The report.
	 */
	public function __construct( Report $report ) {
		$this->report = $report;
	}

	/**
	 * Registers the route.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Adds the route.
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get' ),
				'permission_callback' => array( Capability::class, 'can_read_report' ),
			)
		);
	}

	/**
	 * Serves the report.
	 *
	 * @return \WP_REST_Response
	 */
	public function get(): \WP_REST_Response {
		return new \WP_REST_Response( $this->report->build() );
	}
}
