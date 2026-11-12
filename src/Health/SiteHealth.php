<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Health;

use AryanX\Axle\LicenseTracker\Admin\Page;
use AryanX\Axle\LicenseTracker\Hub\Hub;
use AryanX\Axle\LicenseTracker\Hub\Overview;
use AryanX\Axle\LicenseTracker\Service;
use AryanX\Axle\LicenseTracker\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a premium updates test to Tools > Site Health: critical when an update
 * is blocked, recommended when a component is at risk or its license expired.
 */
class SiteHealth implements Service {

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * The hub.
	 *
	 * @var Hub
	 */
	private Hub $hub;

	/**
	 * Sets up the checks.
	 *
	 * @param Tracker $tracker Rows.
	 * @param Hub     $hub     The hub.
	 */
	public function __construct( Tracker $tracker, Hub $hub ) {
		$this->tracker = $tracker;
		$this->hub     = $hub;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( $tests ): array {
		$tests                                   = (array) $tests;
		$tests['direct']['axle_license_tracker'] = array(
			'label' => __( 'Premium plugin and theme licenses', 'axle-license-tracker' ),
			'test'  => array( $this, 'run' ),
		);
		if ( $this->hub->enabled() ) {
			$tests['direct']['axle_license_tracker_hub'] = array(
				'label' => __( 'Sites in the license hub', 'axle-license-tracker' ),
				'test'  => array( $this, 'run_hub' ),
			);
		}

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array
	 */
	public function run(): array {
		$blocked = array();
		$risky   = array();
		$expired = array();
		foreach ( $this->tracker->tracked() as $row ) {
			if ( $row['ignored'] ) {
				continue;
			}
			if ( 'blocked' === $row['risk'] ) {
				$blocked[] = $row['name'];
			} elseif ( 'at-risk' === $row['risk'] ) {
				$risky[] = $row['name'];
			}
			if ( 'expired' === $row['state'] ) {
				$expired[] = $row['name'];
			}
		}

		$result = array(
			'label'       => __( 'Premium plugins and themes are getting updates', 'axle-license-tracker' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'axle-license-tracker' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Every premium plugin and theme that Axle License Tracker found has a license that is not expired and an updater that answers.', 'axle-license-tracker' ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'plugins.php?page=' . Page::SLUG ) ), esc_html__( 'Open Plugins > Licenses', 'axle-license-tracker' ) ),
			'test'        => 'axle_license_tracker',
		);

		$lines = array();
		if ( $blocked ) {
			/* translators: %s: comma-separated names. */
			$lines[] = sprintf( __( 'A newer version is out, but this site cannot download it: %s.', 'axle-license-tracker' ), implode( ', ', $blocked ) );
		}
		if ( $risky ) {
			/* translators: %s: comma-separated names. */
			$lines[] = sprintf( __( 'Not getting updates: %s.', 'axle-license-tracker' ), implode( ', ', $risky ) );
		}
		if ( $expired ) {
			/* translators: %s: comma-separated names. */
			$lines[] = sprintf( __( 'License expired: %s.', 'axle-license-tracker' ), implode( ', ', $expired ) );
		}

		if ( $lines ) {
			$result['status']         = $blocked ? 'critical' : 'recommended';
			$result['badge']['color'] = $blocked ? 'red' : 'orange';
			$result['label']          = $blocked ? __( 'A premium plugin or theme cannot download its update', 'axle-license-tracker' ) : __( 'A premium plugin or theme may not be getting updates', 'axle-license-tracker' );
			$result['description']    = '<p>' . esc_html__( 'Premium plugins and themes stop getting updates, security fixes included, when their license lapses. Security scanners that compare versions with WordPress.org skip them.', 'axle-license-tracker' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>';
		}

		return $result;
	}

	/**
	 * The hub test: remote sites that keep failing, and keys over their seat limit.
	 *
	 * @return array
	 */
	public function run_hub(): array {
		$failing = array();
		foreach ( $this->hub->sources() as $source ) {
			/**
			 * Filters how many failed syncs in a row make Site Health complain about a remote site.
			 *
			 * @param int $fails Default 3.
			 */
			if ( $source['fails'] >= max( 1, (int) apply_filters( 'axle_license_tracker_hub_health_fails', 3 ) ) ) {
				$failing[] = sprintf( '%s (%s)', $source['label'], $source['error'] );
			}
		}
		$over = array();
		foreach ( Overview::duplicates( $this->hub->rows() ) as $dup ) {
			if ( $dup['over'] ) {
				/* translators: 1: product names, 2: number of sites, 3: seats the license allows. */
				$over[] = sprintf( __( '%1$s is on %2$d sites, the plan allows %3$d.', 'axle-license-tracker' ), implode( ', ', $dup['names'] ), $dup['count'], $dup['limit'] );
			}
		}

		$result = array(
			'label'       => __( 'The license hub can reach every site', 'axle-license-tracker' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'axle-license-tracker' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Every site in the hub answered its last check, and no license is on more sites than its plan allows.', 'axle-license-tracker' ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( Page::url( array( 'tab' => 'sites' ) ) ), esc_html__( 'Open Plugins > Licenses > Sites', 'axle-license-tracker' ) ),
			'test'        => 'axle_license_tracker_hub',
		);
		$lines  = array();
		foreach ( $failing as $line ) {
			/* translators: %s: site name and the reason. */
			$lines[] = sprintf( __( 'Could not be reached: %s', 'axle-license-tracker' ), $line );
		}
		$lines = array_merge( $lines, $over );
		if ( $lines ) {
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']          = __( 'The license hub needs a look', 'axle-license-tracker' );
			$result['description']    = '<p>' . esc_html__( 'A license that lapses on a site you cannot see is easy to miss. Fix these so the hub shows the whole picture.', 'axle-license-tracker' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>';
		}

		return $result;
	}
}
