<?php
/**
 * The adapter registry.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

use AryanX\Axle\LicenseTracker\Adapters\AcfPro;
use AryanX\Axle\LicenseTracker\Adapters\Adapter;
use AryanX\Axle\LicenseTracker\Adapters\EddSoftwareLicensing;
use AryanX\Axle\LicenseTracker\Adapters\ElementorPro;
use AryanX\Axle\LicenseTracker\Adapters\EnvatoMarket;
use AryanX\Axle\LicenseTracker\Adapters\Freemius;
use AryanX\Axle\LicenseTracker\Adapters\GravityForms;
use AryanX\Axle\LicenseTracker\Adapters\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * The built-in adapters plus any added through axle_license_tracker_adapters.
 * Vendor-specific adapters come first, the generic SDK ones last.
 */
class Registry {

	/**
	 * Loaded adapters.
	 *
	 * @var Adapter[]|null
	 */
	private ?array $adapters = null;

	/**
	 * All adapters, keyed by id.
	 *
	 * @return array<string, Adapter>
	 */
	public function all(): array {
		if ( null !== $this->adapters ) {
			return $this->adapters;
		}

		$built_in = array( new ElementorPro(), new AcfPro(), new GravityForms(), new WooCommerce(), new EnvatoMarket(), new Freemius(), new EddSoftwareLicensing() );

		/**
		 * Filters the license adapters. Add an object that implements
		 * AryanX\Axle\LicenseTracker\Adapters\Adapter to read another vendor.
		 * The first adapter whose detect() returns true reads the component.
		 *
		 * @param array $adapters Adapter objects, most specific first.
		 */
		$list = apply_filters( 'axle_license_tracker_adapters', $built_in );

		$adapters = array();
		foreach ( (array) $list as $adapter ) {
			if ( $adapter instanceof Adapter && '' !== $adapter->id() && ! isset( $adapters[ $adapter->id() ] ) ) {
				$adapters[ $adapter->id() ] = $adapter;
			}
		}

		$this->adapters = $adapters;

		return $adapters;
	}

	/**
	 * The adapter that handles a component.
	 *
	 * @param array $component Component.
	 * @param array $signals   Detection signals.
	 * @return Adapter|null
	 */
	public function for_component( array $component, array $signals ): ?Adapter {
		foreach ( $this->all() as $adapter ) {
			if ( $adapter->detect( $component, $signals ) ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * One adapter by id.
	 *
	 * @param string $id Adapter id.
	 * @return Adapter|null
	 */
	public function get( string $id ): ?Adapter {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}
}
