<?php
/**
 * The contract for vendor adapters.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one vendor's license state from what the vendor already stores on the
 * site: options and transients. An adapter never calls vendor code and never
 * makes a network request, so reading can never use up a seat.
 *
 * Register your own through the axle_license_tracker_adapters filter.
 */
interface Adapter {

	/**
	 * Unique id, such as `freemius`.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Vendor or SDK name people see.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether this adapter handles a component.
	 *
	 * @param array $component Component: id, kind, file, slug, name, version, author_uri, update_uri, woo, path, active.
	 * @param array $signals   What detection found: `sdks` (signature ids).
	 * @return bool
	 */
	public function detect( array $component, array $signals ): bool;

	/**
	 * Reads the license state of a component this adapter detected.
	 *
	 * @param array $component Component.
	 * @return LicenseState
	 */
	public function read( array $component ): LicenseState;

	/**
	 * Where the license is managed: a wp-admin screen or the vendor's account page.
	 *
	 * @param array $component Component.
	 * @return string URL, or '' when unknown.
	 */
	public function license_url( array $component ): string;
}
