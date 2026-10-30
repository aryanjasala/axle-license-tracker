<?php
/**
 * The bounded fingerprint scan.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for updater and licensing SDKs in each component's PHP files.
 *
 * It reads files, never includes or runs them. Only PHP files up to 3 folders
 * deep and 512 KB each are read. Results are cached per component version, so
 * a component is scanned again only after it changes. Scans run in batches of
 * 5 components per cron run.
 */
class Scanner implements Service {

	/**
	 * Detection store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Installed components.
	 *
	 * @var Inventory
	 */
	private Inventory $inventory;

	/**
	 * Loaded signatures.
	 *
	 * @var array[]|null
	 */
	private ?array $signatures = null;

	/**
	 * Sets up the scanner.
	 *
	 * @param Store     $store     Detection store.
	 * @param Inventory $inventory Installed components.
	 */
	public function __construct( Store $store, Inventory $inventory ) {
		$this->store     = $store;
		$this->inventory = $inventory;
	}

	/**
	 * Runs a batch on the scan event.
	 */
	public function register(): void {
		add_action( Lifecycle::SCAN, array( $this, 'cron' ) );
	}

	/**
	 * The signature list from data/signatures.json, filtered.
	 *
	 * @return array[] Each: id, label, and path or pattern; bundles for TGMPA.
	 */
	public function signatures(): array {
		if ( null !== $this->signatures ) {
			return $this->signatures;
		}

		$file = dirname( PLUGIN_FILE ) . '/data/signatures.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local file that ships with the plugin.
		$list = is_array( $data ) && isset( $data['signatures'] ) ? (array) $data['signatures'] : array();

		/**
		 * Filters the code signatures the fingerprint scan looks for.
		 *
		 * @param array $signatures Each an array: id, label, and `path` (part of a file path) or `pattern` (a regular expression for file contents).
		 */
		$list = apply_filters( 'axle_license_tracker_signatures', $list );

		$clean = array();
		foreach ( (array) $list as $signature ) {
			if ( ! is_array( $signature ) || empty( $signature['id'] ) || ( empty( $signature['path'] ) && empty( $signature['pattern'] ) ) ) {
				continue;
			}
			if ( ! empty( $signature['pattern'] ) && false === @preg_match( '~' . str_replace( '~', '\~', (string) $signature['pattern'] ) . '~', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Testing a filtered pattern; a bad one is skipped.
				continue;
			}
			$clean[] = array(
				'id'      => sanitize_key( (string) $signature['id'] ),
				'label'   => (string) ( $signature['label'] ?? $signature['id'] ),
				'path'    => (string) ( $signature['path'] ?? '' ),
				'pattern' => (string) ( $signature['pattern'] ?? '' ),
				'bundles' => ! empty( $signature['bundles'] ),
			);
		}

		$this->signatures = $clean;

		return $clean;
	}

	/**
	 * Label of a signature.
	 *
	 * @param string $id Signature id.
	 * @return string
	 */
	public function label( string $id ): string {
		foreach ( $this->signatures() as $signature ) {
			if ( $signature['id'] === $id ) {
				return $signature['label'];
			}
		}

		return $id;
	}

	/**
	 * Cache key of a component: its id and version.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public static function key( array $component ): string {
		return $component['id'] . '@' . $component['version'];
	}

	/**
	 * Cached scan result of a component.
	 *
	 * @param array $component Component.
	 * @return string[]|null Signature ids found, or null when not scanned yet.
	 */
	public function cached( array $component ): ?array {
		$scan = (array) $this->store->get( 'scan' );
		$key  = self::key( $component );

		return isset( $scan[ $key ] ) && is_array( $scan[ $key ] ) ? array_values( array_map( 'strval', $scan[ $key ] ) ) : null;
	}

	/**
	 * Components that still need a scan.
	 *
	 * @return array[]
	 */
	public function pending(): array {
		$scan = (array) $this->store->get( 'scan' );

		return array_values(
			array_filter(
				$this->inventory->all(),
				static function ( array $component ) use ( $scan ): bool {
					return ! isset( $scan[ self::key( $component ) ] );
				}
			)
		);
	}

	/**
	 * The cron event.
	 */
	public function cron(): void {
		$this->run_batch();
	}

	/**
	 * Scans the next batch and schedules another run when more are left.
	 *
	 * @return int Components scanned.
	 */
	public function run_batch(): int {
		$this->store->reload();
		$done = $this->scan_pending( self::batch_size() );
		if ( $this->pending() ) {
			$this->schedule();
		}

		return $done;
	}

	/**
	 * Scans pending components.
	 *
	 * @param int $limit   Most components to scan, 0 for all.
	 * @param int $seconds Stop after this many seconds, 0 for no limit.
	 * @return int Components scanned.
	 */
	public function scan_pending( int $limit = 0, int $seconds = 0 ): int {
		$start = time();
		$scan  = (array) $this->store->get( 'scan' );
		$done  = 0;
		foreach ( $this->pending() as $component ) {
			if ( ( $limit && $done >= $limit ) || ( $seconds && time() - $start >= $seconds ) ) {
				break;
			}
			$scan[ self::key( $component ) ] = $this->scan( $component );
			++$done;
		}

		// Drop results for versions that are no longer installed.
		$keys = array_map( array( self::class, 'key' ), $this->inventory->all() );
		$scan = array_intersect_key( $scan, array_flip( $keys ) );
		$this->store->set( 'scan', $scan );

		return $done;
	}

	/**
	 * Schedules the next batch in a minute.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( Lifecycle::SCAN ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Lifecycle::SCAN );
		}
	}

	/**
	 * Scans one component's files.
	 *
	 * @param array $component Component.
	 * @return string[] Signature ids found.
	 */
	public function scan( array $component ): array {
		$found = array();
		$left  = $this->signatures();
		foreach ( $this->files( (string) $component['path'] ) as $relative => $file ) {
			if ( ! $left ) {
				break;
			}
			$contents = null;
			foreach ( $left as $index => $signature ) {
				$hit = false;
				if ( '' !== $signature['path'] ) {
					$hit = false !== strpos( '/' . $relative, '/' . ltrim( $signature['path'], '/' ) );
				} elseif ( '' !== $file ) {
					if ( null === $contents ) {
						$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reading a local plugin file; never executed.
					}
					$hit = (bool) preg_match( '~' . str_replace( '~', '\~', $signature['pattern'] ) . '~', $contents );
				}
				if ( $hit ) {
					$found[] = $signature['id'];
					unset( $left[ $index ] );
				}
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * PHP files of a component, within the depth and size limits.
	 *
	 * @param string $path Component folder, or the file of a single-file plugin.
	 * @return array<string, string> Absolute paths keyed by path relative to the folder.
	 */
	public function files( string $path ): array {
		if ( is_file( $path ) ) {
			return array( basename( $path ) => $path );
		}
		if ( ! is_dir( $path ) ) {
			return array();
		}

		$max_depth = self::max_depth();
		$max_bytes = self::max_bytes();
		$files     = array();
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$iterator->setMaxDepth( $max_depth );
		foreach ( $iterator as $file ) {
			/**
			 * A file or folder.
			 *
			 * @var \SplFileInfo $file
			 */
			$relative = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $path ) ) ), '/' );
			if ( $file->isDir() ) {
				// A folder path can be a signature on its own (bsf-core/).
				$files[ $relative . '/' ] = '';
				continue;
			}
			if ( 'php' !== strtolower( $file->getExtension() ) || $file->getSize() > $max_bytes ) {
				continue;
			}
			$files[ $relative ] = $file->getPathname();
		}

		// Folders only match path signatures; content patterns skip them.
		return array_filter(
			$files,
			static function ( $absolute, $relative ) {
				return '' !== $absolute || '/' === substr( $relative, -1 );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * How many folders deep the scan goes. Default 3, between 0 and 10.
	 *
	 * @return int
	 */
	public static function max_depth(): int {
		/**
		 * Filters how many folders deep the fingerprint scan reads.
		 *
		 * @param int $depth Default 3.
		 */
		return max( 0, min( 10, (int) apply_filters( 'axle_license_tracker_scan_max_depth', 3 ) ) );
	}

	/**
	 * Largest file the scan reads. Default 512 KB, between 1 KB and 5 MB.
	 *
	 * @return int Bytes.
	 */
	public static function max_bytes(): int {
		/**
		 * Filters the largest PHP file the fingerprint scan reads.
		 *
		 * @param int $bytes Default 524288 (512 KB).
		 */
		return max( 1024, min( 5 * 1048576, (int) apply_filters( 'axle_license_tracker_scan_max_file_bytes', 524288 ) ) );
	}

	/**
	 * Components scanned per cron run. Default 5, between 1 and 50.
	 *
	 * @return int
	 */
	public static function batch_size(): int {
		/**
		 * Filters how many components the background scan reads per run.
		 *
		 * @param int $size Default 5.
		 */
		return max( 1, min( 50, (int) apply_filters( 'axle_license_tracker_scan_batch_size', 5 ) ) );
	}
}
