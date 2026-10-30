<?php
/**
 * License pills on the Plugins and Themes screens.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Admin;

use AryanX\Axle\LicenseTracker\Capability;
use AryanX\Axle\LicenseTracker\Service;
use AryanX\Axle\LicenseTracker\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a License column to Plugins with the license pill and update risk
 * badge of each premium plugin, and the same pills to a premium theme's
 * details on Appearance > Themes.
 */
class PluginsScreen implements Service {

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Sets up the column.
	 *
	 * @param Tracker $tracker Rows.
	 */
	public function __construct( Tracker $tracker ) {
		$this->tracker = $tracker;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'manage_plugins_columns', array( $this, 'columns' ) );
		add_action( 'manage_plugins_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'wp_prepare_themes_for_js', array( $this, 'themes' ) );
	}

	/**
	 * Adds the License column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ): array {
		$columns = (array) $columns;
		if ( Capability::can_manage() ) {
			$columns['axle_license_tracker'] = __( 'License', 'axle-license-tracker' );
		}

		return $columns;
	}

	/**
	 * Prints the pills of a plugin.
	 *
	 * @param string $column Column.
	 * @param string $file   Plugin file.
	 */
	public function column( $column, $file ): void {
		if ( 'axle_license_tracker' !== $column ) {
			return;
		}
		echo $this->pills( 'plugin:' . (string) $file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.
	}

	/**
	 * Adds the pills to premium themes' descriptions.
	 *
	 * @param array $themes Themes prepared for themes.php.
	 * @return array
	 */
	public function themes( $themes ): array {
		$themes = (array) $themes;
		if ( ! Capability::can_manage() ) {
			return $themes;
		}
		foreach ( $themes as $stylesheet => $theme ) {
			$pills = $this->pills( 'theme:' . (string) $stylesheet );
			if ( '' !== $pills && is_array( $theme ) ) {
				$themes[ $stylesheet ]['description'] = $pills . ' ' . ( $theme['description'] ?? '' );
			}
		}

		return $themes;
	}

	/**
	 * Pills of one tracked component, linking to its details.
	 *
	 * @param string $id Component id.
	 * @return string HTML, '' when it is not tracked.
	 */
	private function pills( string $id ): string {
		$row = $this->tracker->row( $id );
		if ( ! $row || ! $row['tracked'] ) {
			return '';
		}

		return sprintf(
			'<span class="alc-pills"><a href="%1$s" class="alc-pill-link">%2$s</a> %3$s</span>',
			esc_url( Page::url( array( 'edit' => $id ) ) ),
			Display::state( $row['state'] ),
			Display::risk( $row['risk'] )
		);
	}
}
