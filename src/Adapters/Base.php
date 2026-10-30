<?php
/**
 * Shared helpers for the built-in adapters.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Reading helpers: vendors store arrays, objects and serialized objects whose
 * class is not loaded (they come back as __PHP_Incomplete_Class).
 */
abstract class Base implements Adapter {

	/**
	 * One property of an array, object or incomplete object.
	 *
	 * @param mixed  $value   Container.
	 * @param string $key     Property.
	 * @param mixed  $fallback Value when missing.
	 * @return mixed
	 */
	public static function prop( $value, string $key, $fallback = null ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
			return $value[ $key ];
		}

		return $fallback;
	}

	/**
	 * Whether a component's plugin file is one of these.
	 *
	 * @param array    $component Component.
	 * @param string[] $files     Plugin files, such as elementor-pro/elementor-pro.php.
	 * @return bool
	 */
	protected static function is_plugin( array $component, array $files ): bool {
		return 'plugin' === $component['kind'] && in_array( $component['file'], $files, true );
	}

	/**
	 * An option, falling back to the network option on multisite.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	protected static function option( string $name ) {
		$value = get_option( $name, null );
		if ( ( null === $value || false === $value || '' === $value ) && is_multisite() ) {
			$value = get_site_option( $name, null );
		}

		return $value;
	}
}
