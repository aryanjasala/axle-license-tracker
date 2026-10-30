<?php
/**
 * The known vendor registry.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Vendors with their own license scheme, from data/vendors.json, matched by
 * folder name or author URL.
 */
class Vendors {

	/**
	 * Loaded vendors.
	 *
	 * @var array[]|null
	 */
	private ?array $vendors = null;

	/**
	 * All vendors, filtered.
	 *
	 * @return array[] Each: id, name, slugs, authors, license_url.
	 */
	public function all(): array {
		if ( null !== $this->vendors ) {
			return $this->vendors;
		}

		$file = dirname( PLUGIN_FILE ) . '/data/vendors.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local file that ships with the plugin.
		$list = is_array( $data ) && isset( $data['vendors'] ) ? (array) $data['vendors'] : array();

		/**
		 * Filters the known vendor registry.
		 *
		 * @param array $vendors Each an array: id, name, slugs (folder names), authors (parts of the author URL), license_url.
		 */
		$list = apply_filters( 'axle_license_tracker_vendors', $list );

		$clean = array();
		foreach ( (array) $list as $vendor ) {
			if ( ! is_array( $vendor ) || empty( $vendor['id'] ) || empty( $vendor['name'] ) ) {
				continue;
			}
			$clean[] = array(
				'id'          => sanitize_key( (string) $vendor['id'] ),
				'name'        => (string) $vendor['name'],
				'slugs'       => array_map( 'strval', (array) ( $vendor['slugs'] ?? array() ) ),
				'authors'     => array_map( 'strtolower', array_map( 'strval', (array) ( $vendor['authors'] ?? array() ) ) ),
				'license_url' => (string) ( $vendor['license_url'] ?? '' ),
			);
		}

		$this->vendors = $clean;

		return $clean;
	}

	/**
	 * The vendor a component belongs to.
	 *
	 * @param array $component Component.
	 * @return array|null
	 */
	public function match( array $component ): ?array {
		$author = strtolower( (string) $component['author_uri'] );
		foreach ( $this->all() as $vendor ) {
			if ( in_array( (string) $component['slug'], $vendor['slugs'], true ) ) {
				return $vendor;
			}
			foreach ( $vendor['authors'] as $needle ) {
				if ( '' !== $needle && '' !== $author && false !== strpos( $author, $needle ) ) {
					return $vendor;
				}
			}
		}

		return null;
	}

	/**
	 * One vendor by id.
	 *
	 * @param string $id Vendor id.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
		foreach ( $this->all() as $vendor ) {
			if ( $vendor['id'] === $id ) {
				return $vendor;
			}
		}

		return null;
	}
}
