<?php
/**
 * The Licenses dashboard widget.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Admin;

use AryanX\Axle\LicenseTracker\Capability;
use AryanX\Axle\LicenseTracker\Service;
use AryanX\Axle\LicenseTracker\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the next 5 renewals and anything expired or blocked.
 */
class DashboardWidget implements Service {

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Sets up the widget.
	 *
	 * @param Tracker $tracker Rows.
	 */
	public function __construct( Tracker $tracker ) {
		$this->tracker = $tracker;
	}

	/**
	 * Adds the widget.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add' ) );
	}

	/**
	 * Registers the widget for users who manage licenses.
	 */
	public function add(): void {
		if ( Capability::can_manage() ) {
			wp_add_dashboard_widget( 'axle_license_tracker', __( 'Licenses', 'axle-license-tracker' ), array( $this, 'render' ) );
		}
	}

	/**
	 * Prints the widget.
	 */
	public function render(): void {
		$rows     = array_filter(
			$this->tracker->tracked(),
			static function ( array $row ): bool {
				return ! $row['ignored'];
			}
		);
		$problems = array_filter(
			$rows,
			static function ( array $row ): bool {
				return 'expired' === $row['state'] || 'blocked' === $row['risk'];
			}
		);
		$upcoming = array_filter(
			$rows,
			static function ( array $row ): bool {
				return null !== $row['days_left'] && $row['days_left'] >= 0;
			}
		);
		usort(
			$upcoming,
			static function ( array $a, array $b ): int {
				return $a['days_left'] <=> $b['days_left'];
			}
		);
		$upcoming = array_slice( $upcoming, 0, 5 );

		echo '<div class="alc-widget">';
		if ( $problems ) {
			echo '<h3>' . esc_html__( 'Needs a look', 'axle-license-tracker' ) . '</h3><ul>';
			foreach ( $problems as $row ) {
				printf( '<li><a href="%1$s">%2$s</a> %3$s %4$s</li>', esc_url( Page::url( array( 'edit' => $row['id'] ) ) ), esc_html( $row['name'] ), Display::state( $row['state'] ), Display::risk( $row['risk'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.
			}
			echo '</ul>';
		}

		echo '<h3>' . esc_html__( 'Next renewals', 'axle-license-tracker' ) . '</h3>';
		if ( $upcoming ) {
			echo '<ul>';
			foreach ( $upcoming as $row ) {
				printf(
					'<li><a href="%1$s">%2$s</a> <span class="alc-muted">%3$s, %4$s</span>%5$s</li>',
					esc_url( Page::url( array( 'edit' => $row['id'] ) ) ),
					esc_html( $row['name'] ),
					esc_html( $row['renewal'] ),
					/* translators: %d: number of days. */
					esc_html( sprintf( _n( 'in %d day', 'in %d days', (int) $row['days_left'], 'axle-license-tracker' ), (int) $row['days_left'] ) ),
					null !== $row['cost'] ? ' ' . Display::cost( $row['cost'], $row['currency'] ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.
				);
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No renewal dates yet. Add them under Plugins > Licenses.', 'axle-license-tracker' ) . '</p>';
		}
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Page::url() ), esc_html__( 'All licenses', 'axle-license-tracker' ) );
		echo '</div>';
	}
}
