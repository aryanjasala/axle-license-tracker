<?php
/**
 * Removes Axle License Tracker's data, on every site.
 *
 * @package AryanX\Axle\LicenseTracker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Axle License Tracker data from the current site.
 */
function axle_license_tracker_uninstall_site(): void {
	global $wpdb;

	delete_option( 'axle_license_tracker_settings' );
	delete_option( 'axle_license_tracker_detection' );

	// One record per tracked component; wp_delete_post() takes its meta too.
	do {
		$axle_license_tracker_posts = get_posts(
			array(
				'post_type'      => 'alc_license',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
			)
		);
		foreach ( $axle_license_tracker_posts as $axle_license_tracker_post ) {
			wp_delete_post( (int) $axle_license_tracker_post, true );
		}
	} while ( $axle_license_tracker_posts );

	// Validation errors kept for a few minutes between a form and its page.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_axle_license_tracker_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_license_tracker_' ) . '%' ) );

	wp_clear_scheduled_hook( 'axle_license_tracker_daily' );
	wp_clear_scheduled_hook( 'axle_license_tracker_scan' );

	remove_role( 'axle_license_reporter' );
	foreach ( wp_roles()->role_objects as $axle_license_tracker_role ) {
		$axle_license_tracker_role->remove_cap( 'axle_license_tracker_read_report' );
	}
}

if ( is_multisite() ) {
	$axle_license_tracker_offset = 0;
	do {
		$axle_license_tracker_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_license_tracker_offset,
			)
		);

		foreach ( $axle_license_tracker_sites as $axle_license_tracker_site ) {
			switch_to_blog( $axle_license_tracker_site );
			axle_license_tracker_uninstall_site();
			restore_current_blog();
		}

		$axle_license_tracker_offset += 100;
	} while ( $axle_license_tracker_sites );
} else {
	axle_license_tracker_uninstall_site();
}
