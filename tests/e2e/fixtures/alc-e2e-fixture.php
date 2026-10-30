<?php
/**
 * Plugin Name: Axle License Tracker e2e fixture
 * Description: Test helpers for the end-to-end suite: an email catcher, application passwords over plain HTTP, and a vendor updater that offers an update with no package. Never use on a real site.
 *
 * @package AryanX\Axle\LicenseTracker
 */

// Application passwords on the plain HTTP test site.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

// Keep every email in an option instead of sending it.
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$log   = get_option( 'alc_fixture_mail', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'to'      => (array) $atts['to'],
			'subject' => (string) $atts['subject'],
			'message' => (string) $atts['message'],
		);
		update_option( 'alc_fixture_mail', $log, false );
		return true;
	},
	10,
	2
);

// A vendor updater that answers for the blocked fixture with a newer version and no package,
// as vendors do when the license has lapsed.
add_filter(
	'pre_set_site_transient_update_plugins',
	static function ( $value ) {
		$file = 'alc-blocked-fixture/alc-blocked-fixture.php';
		if ( ! is_object( $value ) || ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
			return $value;
		}
		$value->response          = isset( $value->response ) && is_array( $value->response ) ? $value->response : array();
		$value->response[ $file ] = (object) array(
			'id'          => 'updates.example.com/alc-blocked-fixture',
			'slug'        => 'alc-blocked-fixture',
			'plugin'      => $file,
			'new_version' => '2.0.0',
			'url'         => 'https://updates.example.com/alc-blocked-fixture/',
			'package'     => '',
		);
		return $value;
	}
);
