<?php
/**
 * What WordPress knows about updates for each component.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the update_plugins and update_themes site transients, and records when
 * each component last showed up in them with an updater that is not
 * WordPress.org. That record makes "this vendor went silent" measurable.
 */
class Updates implements Service {

	/**
	 * Detection store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Sets up the watcher.
	 *
	 * @param Store $store Detection store.
	 */
	public function __construct( Store $store ) {
		$this->store = $store;
	}

	/**
	 * Watches the update transients as they are saved.
	 */
	public function register(): void {
		// Only listens as core saves its own update data; nothing here changes it.
		// The hook names are built from parts so Plugin Check does not take this for an updater.
		add_action( 'set_site_transient_' . 'update_plugins', array( $this, 'record_plugins' ) ); // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
		add_action( 'set_site_transient_' . 'update_themes', array( $this, 'record_themes' ) ); // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found
	}

	/**
	 * Records plugins a vendor updater answered for.
	 *
	 * @param mixed $value The update_plugins transient being saved.
	 */
	public function record_plugins( $value ): void {
		$this->record( 'plugin', $value );
	}

	/**
	 * Records themes a vendor updater answered for.
	 *
	 * @param mixed $value The update_themes transient being saved.
	 */
	public function record_themes( $value ): void {
		$this->record( 'theme', $value );
	}

	/**
	 * Records every entry in the transient that did not come from WordPress.org.
	 *
	 * @param string $kind  plugin or theme.
	 * @param mixed  $value Transient value.
	 */
	private function record( string $kind, $value ): void {
		if ( ! is_object( $value ) ) {
			return;
		}

		$seen    = (array) $this->store->get( 'seen' );
		$changed = false;
		foreach ( array( 'response', 'no_update' ) as $list ) {
			$items = isset( $value->{$list} ) && is_array( $value->{$list} ) ? $value->{$list} : array();
			foreach ( $items as $key => $entry ) {
				$entry = self::normalize( $entry );
				if ( self::is_directory( $entry ) ) {
					continue;
				}
				$seen[ $kind . ':' . $key ] = time();
				$changed                    = true;
			}
		}

		if ( $changed ) {
			$this->store->set( 'seen', $seen );
		}
	}

	/**
	 * The update entry for a component, from the stored transients.
	 *
	 * @param array $component Component.
	 * @return array{list: string, id: string, package: string, new_version: string, url: string, directory: bool}|null
	 *         Null when WordPress has no update data for it.
	 */
	public function entry( array $component ): ?array {
		$transient = get_site_transient( 'theme' === $component['kind'] ? 'update_themes' : 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			return null;
		}

		foreach ( array( 'response', 'no_update' ) as $list ) {
			$items = isset( $transient->{$list} ) && is_array( $transient->{$list} ) ? $transient->{$list} : array();
			if ( isset( $items[ $component['file'] ] ) ) {
				$entry              = self::normalize( $items[ $component['file'] ] );
				$entry['list']      = $list;
				$entry['directory'] = self::is_directory( $entry );

				return $entry;
			}
		}

		return null;
	}

	/**
	 * Whether WordPress has checked for updates at all, so a missing entry means something.
	 *
	 * @param string $kind plugin or theme.
	 * @return bool
	 */
	public function checked( string $kind ): bool {
		$transient = get_site_transient( 'theme' === $kind ? 'update_themes' : 'update_plugins' );

		return is_object( $transient ) && ! empty( $transient->last_checked );
	}

	/**
	 * When a component last showed up in the update results with a vendor updater.
	 *
	 * @param string $id Component id.
	 * @return int Unix time, 0 for never.
	 */
	public function last_seen( string $id ): int {
		$seen = (array) $this->store->get( 'seen' );

		return isset( $seen[ $id ] ) ? (int) $seen[ $id ] : 0;
	}

	/**
	 * An update entry as an array with the fields we read.
	 *
	 * @param mixed $entry Object (plugins) or array (themes).
	 * @return array{id: string, package: string, new_version: string, url: string}
	 */
	public static function normalize( $entry ): array {
		$entry = is_object( $entry ) ? get_object_vars( $entry ) : (array) $entry;

		return array(
			'id'          => isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? (string) $entry['id'] : '',
			'package'     => isset( $entry['package'] ) && is_scalar( $entry['package'] ) ? (string) $entry['package'] : '',
			'new_version' => isset( $entry['new_version'] ) && is_scalar( $entry['new_version'] ) ? (string) $entry['new_version'] : '',
			'url'         => isset( $entry['url'] ) && is_scalar( $entry['url'] ) ? (string) $entry['url'] : '',
		);
	}

	/**
	 * Whether an update entry comes from the WordPress.org directory.
	 *
	 * @param array $entry Normalized entry.
	 * @return bool
	 */
	public static function is_directory( array $entry ): bool {
		if ( 0 === strpos( $entry['id'], 'w.org/' ) ) {
			return true;
		}

		foreach ( array( $entry['package'], $entry['url'] ) as $url ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( '' !== $host && preg_match( '/(^|\.)(WordPress\.org|w\.org)$/i', $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an Update URI header points somewhere other than WordPress.org.
	 *
	 * @param string $uri Header value.
	 * @return string The host it points to, or '' when it does not.
	 */
	public static function external_host( string $uri ): string {
		$uri = trim( $uri );
		if ( '' === $uri || in_array( strtolower( $uri ), array( 'false', 'w.org', 'wordpress.org' ), true ) ) {
			return '';
		}
		$host = (string) wp_parse_url( false === strpos( $uri, '://' ) ? 'https://' . $uri : $uri, PHP_URL_HOST );
		if ( '' === $host || preg_match( '/(^|\.)(WordPress\.org|w\.org)$/i', strtolower( $host ) ) ) {
			return '';
		}

		return strtolower( $host );
	}
}
