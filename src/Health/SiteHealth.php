<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Health;

use AryanX\Axle\LicenseTracker\Admin\Page;
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
	 * Sets up the check.
	 *
	 * @param Tracker $tracker Rows.
	 */
	public function __construct( Tracker $tracker ) {
		$this->tracker = $tracker;
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
}
