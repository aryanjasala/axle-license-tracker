<?php
/**
 * A small stand-in for TGM Plugin Activation with the same public shape: the
 * tgmpa() function, the TGM_Plugin_Activation instance in $GLOBALS['tgmpa'],
 * its plugins list with source and source_type, and the tgmpa_register action
 * fired on init. Written for the tests; not TGMPA's code.
 *
 * @package AryanX\Axle\LicenseTracker
 */

if ( ! class_exists( 'TGM_Plugin_Activation' ) ) {
	/**
	 * Keeps the registered plugins.
	 */
	class TGM_Plugin_Activation {
		/**
		 * The instance.
		 *
		 * @var TGM_Plugin_Activation|null
		 */
		public static $instance;

		/**
		 * Registered plugins, keyed by slug.
		 *
		 * @var array
		 */
		public $plugins = array();

		/**
		 * The instance.
		 *
		 * @return TGM_Plugin_Activation
		 */
		public static function get_instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Registers one plugin. A source that is not a URL is a zip in the theme: bundled.
		 *
		 * @param array $plugin Plugin.
		 */
		public function register( $plugin ) {
			$plugin['source_type']             = preg_match( '|^https?://|', (string) $plugin['source'] ) ? 'external' : 'bundled';
			$this->plugins[ $plugin['slug'] ] = $plugin;
		}
	}

	/**
	 * Registers plugins, like TGMPA's tgmpa().
	 *
	 * @param array $plugins Plugins.
	 */
	function tgmpa( $plugins ) {
		foreach ( $plugins as $plugin ) {
			TGM_Plugin_Activation::get_instance()->register( $plugin );
		}
	}

	$GLOBALS['tgmpa'] = TGM_Plugin_Activation::get_instance();
	add_action(
		'init',
		static function () {
			do_action( 'tgmpa_register' );
		}
	);
}

add_action(
	'tgmpa_register',
	static function () {
		tgmpa(
			array(
				array(
					'name'     => 'ALC Bundled Fixture',
					'slug'     => 'alc-bundled-fixture',
					'source'   => get_template_directory() . '/plugins/alc-bundled-fixture.zip',
					'required' => true,
				),
			)
		);
	}
);
