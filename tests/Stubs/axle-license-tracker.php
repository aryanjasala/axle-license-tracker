<?php
/**
 * Constants the main plugin file defines, which the unit tests do not load,
 * and the classes the template stubs do not cover.
 *
 * @package AryanX\Axle\LicenseTracker
 */

// phpcs:ignoreFile

defined( 'AryanX\\Axle\\LicenseTracker\\PLUGIN_FILE' ) || define( 'AryanX\\Axle\\LicenseTracker\\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/axle-license-tracker.php' );
defined( 'AryanX\\Axle\\LicenseTracker\\VERSION' ) || define( 'AryanX\\Axle\\LicenseTracker\\VERSION', '1.0.0' );

foreach ( array(
	'JSON_PRETTY_PRINT'      => 128,
	'JSON_UNESCAPED_SLASHES' => 64,
) as $alc_name => $alc_value ) {
	defined( $alc_name ) || define( $alc_name, $alc_value );
}
