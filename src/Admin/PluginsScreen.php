<?php
/**
 * License pills on the Plugins and Themes screens.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Admin;

use AryanX\Axle\LicenseTracker\Capability;
use AryanX\Axle\LicenseTracker\Format;
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
		// The update-row hooks carry the file name, so they are added once the screen loads.
		add_action( 'load-plugins.php', array( $this, 'hook_update_messages' ) );
		add_action( 'load-themes.php', array( $this, 'hook_update_messages' ) );
	}

	/**
	 * Adds one update-row message hook per tracked plugin and theme.
	 */
	public function hook_update_messages(): void {
		foreach ( $this->tracker->tracked() as $row ) {
			if ( 'theme' === $row['kind'] ) {
				add_action( 'in_theme_update_message-' . $row['file'], array( $this, 'theme_update_message' ), 10, 2 );
			} else {
				add_action( 'in_plugin_update_message-' . $row['file'], array( $this, 'plugin_update_message' ), 10, 2 );
			}
		}
	}

	/**
	 * Why an update is blocked, as plain text for the plugin's update row.
	 *
	 * @param array        $data     Plugin data from the row.
	 * @param object|array $response The update response.
	 */
	public function plugin_update_message( $data, $response ): void {
		$data = (array) $data;
		$file = isset( $data['plugin'] ) ? (string) $data['plugin'] : '';
		$out  = $this->blocked_message( 'plugin:' . $file, $response );
		if ( '' !== $out ) {
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}
	}

	/**
	 * The same for a theme's update row (multisite network themes screen).
	 *
	 * @param \WP_Theme    $theme    Theme.
	 * @param object|array $response The update response.
	 */
	public function theme_update_message( $theme, $response ): void {
		$out = $this->blocked_message( 'theme:' . $theme->get_stylesheet(), $response );
		if ( '' !== $out ) {
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}
	}

	/**
	 * The "Blocked: ..." sentence for an update that has no download, as HTML.
	 *
	 * The reason is shown to everyone who sees the update row. The owner and
	 * the account link only to people who manage licenses.
	 *
	 * @param string       $id       Component id.
	 * @param object|array $response The update response.
	 * @return string HTML, escaped. '' when the update can be downloaded or the component is not tracked.
	 */
	public function blocked_message( string $id, $response ): string {
		$package = is_object( $response ) ? ( $response->package ?? '' ) : ( $response['package'] ?? '' );
		$row     = $this->tracker->row( $id );
		if ( ! $row || ! $row['tracked'] || $row['ignored'] || '' !== (string) $package ) {
			return '';
		}

		$date = '' !== $row['renewal'] ? $row['renewal'] : ( 'lifetime' !== $row['expires'] ? $row['expires'] : '' );
		switch ( $row['state'] ) {
			case 'expired':
				$text = '' !== $date
					/* translators: 1: plugin or theme name, 2: date. */
					? sprintf( __( 'Blocked: the %1$s license expired on %2$s.', 'axle-license-tracker' ), $row['name'], Format::date( $date ) )
					/* translators: %s: plugin or theme name. */
					: sprintf( __( 'Blocked: the %s license has expired.', 'axle-license-tracker' ), $row['name'] );
				break;
			case 'invalid':
				/* translators: %s: plugin or theme name. */
				$text = sprintf( __( 'Blocked: the %s license key is not valid.', 'axle-license-tracker' ), $row['name'] );
				break;
			case 'missing':
				/* translators: %s: plugin or theme name. */
				$text = sprintf( __( 'Blocked: no license key is set for %s.', 'axle-license-tracker' ), $row['name'] );
				break;
			default:
				/* translators: %s: plugin or theme name. */
				$text = sprintf( __( 'Blocked: the vendor offers this update for %s without a download. Check the license on the vendor\'s site.', 'axle-license-tracker' ), $row['name'] );
		}

		/**
		 * Filters the sentence under a blocked update on the Plugins and Themes screens.
		 *
		 * @param string $text Plain text.
		 * @param array  $row  Component row.
		 */
		$text = (string) apply_filters( 'axle_license_tracker_blocked_update_message', $text, $row );
		$out  = '<span class="alc-blocked-update"> <strong>' . esc_html( $text ) . '</strong>';

		if ( Capability::can_manage() ) {
			$account = '' !== $row['account_url'] ? $row['account_url'] : $row['license_url'];
			if ( '' !== $account && in_array( $row['state'], array( 'expired', 'invalid', 'missing' ), true ) ) {
				$out .= ' ' . sprintf(
					'<a href="%1$s"%2$s>%3$s</a>',
					esc_url( $account ),
					0 !== strpos( $account, admin_url() ) ? ' target="_blank" rel="noopener noreferrer"' : '',
					esc_html__( 'Renew it', 'axle-license-tracker' )
				);
			}
			if ( '' !== $row['owner'] ) {
				/* translators: %s: owner name. */
				$out .= ' ' . esc_html( sprintf( __( 'Owner: %s.', 'axle-license-tracker' ), $row['owner'] ) );
			}
		}

		return $out . '</span>';
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
			// A theme's update notice is built here as HTML; say why when there is no download.
			if ( is_array( $theme ) && ! empty( $theme['hasUpdate'] ) && ! empty( $theme['update'] ) ) {
				$response = get_site_transient( 'update_themes' );
				$entry    = is_object( $response ) && isset( $response->response[ $stylesheet ] ) ? $response->response[ $stylesheet ] : array();
				$message  = $this->blocked_message( 'theme:' . (string) $stylesheet, (object) (array) $entry );
				if ( '' !== $message ) {
					$themes[ $stylesheet ]['update'] = (string) $theme['update'] . $message;
				}
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
			'<span class="axle-ui alc-pills"><a href="%1$s" class="alc-pill-link">%2$s</a> %3$s</span>',
			esc_url( Page::url( array( 'edit' => $id ) ) ),
			Display::state( $row['state'] ),
			Display::risk( $row['risk'] )
		);
	}
}
