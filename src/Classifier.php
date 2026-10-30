<?php
/**
 * Layer 1: is a component premium, and who updates it?
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Sorts each component into directory, premium, bundled, custom or unknown,
 * from the cheapest signal to the most expensive:
 *
 * 1. a theme's TGMPA list ships it as a local zip: bundled
 * 2. WordPress.org answers for it in the update transient: directory
 * 3. a vendor adapter recognises it: premium
 * 4. an Update URI header that is not WordPress.org: premium, with that host
 * 5. a non-WordPress.org entry in the update transient: premium (a vendor updater answers)
 * 6. an updater or licensing SDK in its code: premium
 * 7. a known vendor by folder or author URL: premium
 * 8. none of these after WordPress checked for updates: custom code, else unknown
 */
class Classifier {

	public const TYPES = array( 'directory', 'premium', 'bundled', 'custom', 'unknown' );

	/**
	 * Update transient reader.
	 *
	 * @var Updates
	 */
	private Updates $updates;

	/**
	 * Fingerprint scan.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Known vendors.
	 *
	 * @var Vendors
	 */
	private Vendors $vendors;

	/**
	 * Adapters.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Sets up the classifier.
	 *
	 * @param Updates  $updates  Update transient reader.
	 * @param Scanner  $scanner  Fingerprint scan.
	 * @param Vendors  $vendors  Known vendors.
	 * @param Registry $registry Adapters.
	 */
	public function __construct( Updates $updates, Scanner $scanner, Vendors $vendors, Registry $registry ) {
		$this->updates  = $updates;
		$this->scanner  = $scanner;
		$this->vendors  = $vendors;
		$this->registry = $registry;
	}

	/**
	 * Classifies one component.
	 *
	 * @param array                 $component Component.
	 * @param array<string, string> $bundled   Bundled plugin folders and names, from Bundles.
	 * @param string                $bundler   Name of the theme that bundles them.
	 * @return array{type: string, vendor: string, sdks: string[], sdk: string, source: string, adapter: string, bundled_by: string, updater: bool, entry: array|null, scanned: bool}
	 */
	public function classify( array $component, array $bundled = array(), string $bundler = '' ): array {
		$sdks    = $this->scanner->cached( $component );
		$scanned = null !== $sdks;
		$sdks    = array_values(
			array_filter(
				(array) $sdks,
				function ( string $id ): bool {
					foreach ( $this->scanner->signatures() as $signature ) {
						if ( $signature['id'] === $id ) {
							return ! $signature['bundles'];
						}
					}

					return true;
				}
			)
		);
		$entry   = $this->updates->entry( $component );
		$result  = array(
			'type'       => 'unknown',
			'vendor'     => '',
			'sdks'       => $sdks,
			'sdk'        => implode( ', ', array_map( array( $this->scanner, 'label' ), $sdks ) ),
			'source'     => '',
			'adapter'    => '',
			'bundled_by' => '',
			'updater'    => false,
			'entry'      => $entry,
			'scanned'    => $scanned,
		);

		$host = Updates::external_host( (string) $component['update_uri'] );
		if ( $entry && ! $entry['directory'] ) {
			$result['updater'] = true;
			$result['source']  = (string) wp_parse_url( '' !== $entry['package'] ? $entry['package'] : $entry['url'], PHP_URL_HOST );
		}
		if ( '' !== $host ) {
			$result['updater'] = true;
			$result['source']  = $host;
		}
		if ( $sdks ) {
			$result['updater'] = true;
		}

		$adapter = null;
		if ( 'plugin' === $component['kind'] && isset( $bundled[ $component['slug'] ] ) ) {
			$result['type']       = 'bundled';
			$result['bundled_by'] = '' !== $bundler ? $bundler : __( 'the active theme', 'axle-license-tracker' );
		} elseif ( $entry && $entry['directory'] && '' === $host ) {
			$result['type'] = 'directory';
		} else {
			$adapter = $this->registry->for_component( $component, array( 'sdks' => $sdks ) );
			$vendor  = $this->vendors->match( $component );
			if ( $adapter || $result['updater'] || $vendor ) {
				$result['type'] = 'premium';
			} elseif ( $this->updates->checked( $component['kind'] ) && $scanned ) {
				$result['type'] = 'custom';
			}
			if ( $adapter ) {
				$result['adapter'] = $adapter->id();
				$result['vendor']  = $adapter->label();
			}
			if ( $vendor ) {
				$result['vendor'] = $vendor['name'];
			}
			if ( '' === $result['vendor'] && '' !== $result['source'] ) {
				$result['vendor'] = $result['source'];
			}
		}

		// A bundled plugin can still have its own license.
		if ( 'bundled' === $result['type'] ) {
			$adapter = $this->registry->for_component( $component, array( 'sdks' => $sdks ) );
			if ( $adapter ) {
				$result['adapter'] = $adapter->id();
				$result['vendor']  = $adapter->label();
			}
		}

		/**
		 * Filters a component's type: directory, premium, bundled, custom or unknown.
		 *
		 * @param string $type      Type.
		 * @param array  $component Component.
		 * @param array  $result    Everything detection found.
		 */
		$type           = apply_filters( 'axle_license_tracker_component_type', $result['type'], $component, $result );
		$result['type'] = in_array( $type, self::TYPES, true ) ? $type : $result['type'];

		return $result;
	}
}
