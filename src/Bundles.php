<?php
/**
 * Plugins that arrive bundled with a theme.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the plugins registered with TGM Plugin Activation that come from a
 * local zip inside the theme (source type "bundled"). Those plugins only get
 * updates when the theme ships a new zip, the classic silent risk.
 *
 * TGMPA keeps its list on its instance, filled on `init` by the code that
 * registers it, normally the active theme. So only the active theme's list is
 * known.
 */
class Bundles {

	/**
	 * Bundled plugin folders, keyed by folder, with the plugin's name in TGMPA.
	 *
	 * @return array<string, string>
	 */
	public function plugins(): array {
		$instance = $GLOBALS['tgmpa'] ?? null;
		if ( ! is_object( $instance ) && class_exists( 'TGM_Plugin_Activation' ) && isset( \TGM_Plugin_Activation::$instance ) ) {
			$instance = \TGM_Plugin_Activation::$instance;
		}
		if ( ! is_object( $instance ) || ! isset( $instance->plugins ) || ! is_array( $instance->plugins ) ) {
			return array();
		}

		$bundled = array();
		foreach ( $instance->plugins as $slug => $plugin ) {
			$plugin = (array) $plugin;
			$source = (string) ( $plugin['source'] ?? '' );
			$type   = (string) ( $plugin['source_type'] ?? '' );
			$local  = 'bundled' === $type || ( '' === $type && '' !== $source && false === strpos( $source, '://' ) );
			if ( $local ) {
				$bundled[ (string) ( $plugin['slug'] ?? $slug ) ] = (string) ( $plugin['name'] ?? $slug );
			}
		}

		return $bundled;
	}

	/**
	 * Name of the theme that bundles plugins: the active theme.
	 *
	 * @return string
	 */
	public function theme_name(): string {
		$theme = wp_get_theme();

		return (string) $theme->get( 'Name' );
	}
}
