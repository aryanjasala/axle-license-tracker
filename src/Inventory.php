<?php
/**
 * Installed plugins and themes.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every installed plugin and theme as a component: one array with the
 * id, name, version, headers and folder the detectors need.
 */
class Inventory implements Service {

	/**
	 * Components built in this request.
	 *
	 * @var array<string, array>|null
	 */
	private ?array $cache = null;

	/**
	 * Adds the Woo header to the plugin headers WordPress reads.
	 */
	public function register(): void {
		add_filter( 'extra_plugin_headers', array( $this, 'headers' ) );
	}

	/**
	 * Adds the `Woo` header that WooCommerce.com extensions carry.
	 *
	 * @param array $headers Extra headers.
	 * @return array
	 */
	public function headers( $headers ): array {
		$headers   = (array) $headers;
		$headers[] = 'Woo';

		return array_values( array_unique( $headers ) );
	}

	/**
	 * Every installed plugin and theme, keyed by component id.
	 *
	 * @return array<string, array>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$components = array();
		foreach ( get_plugins() as $file => $data ) {
			$component                      = self::plugin( (string) $file, (array) $data );
			$components[ $component['id'] ] = $component;
		}

		$active = get_stylesheet();
		$parent = get_template();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$component                      = self::theme(
				(string) $stylesheet,
				array(
					'Name'      => (string) $theme->get( 'Name' ),
					'Version'   => (string) $theme->get( 'Version' ),
					'Author'    => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
					'AuthorURI' => (string) $theme->get( 'AuthorURI' ),
					'ThemeURI'  => (string) $theme->get( 'ThemeURI' ),
					'UpdateURI' => (string) $theme->get( 'UpdateURI' ),
				),
				(string) $theme->get_stylesheet_directory(),
				in_array( $stylesheet, array( $active, $parent ), true )
			);
			$components[ $component['id'] ] = $component;
		}

		$this->cache = $components;

		return $components;
	}

	/**
	 * One component.
	 *
	 * @param string $id Component id, such as plugin:dir/file.php or theme:stylesheet.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Forgets the list, after plugins or themes changed.
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * A plugin as a component.
	 *
	 * @param string $file Plugin file relative to the plugins folder.
	 * @param array  $data Plugin headers.
	 * @return array
	 */
	public static function plugin( string $file, array $data ): array {
		$dir  = dirname( $file );
		$base = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';

		return array(
			'id'         => 'plugin:' . $file,
			'kind'       => 'plugin',
			'file'       => $file,
			'slug'       => '.' === $dir ? basename( $file, '.php' ) : $dir,
			'name'       => (string) ( $data['Name'] ?? $file ),
			'version'    => (string) ( $data['Version'] ?? '' ),
			'author'     => wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ),
			'author_uri' => (string) ( $data['AuthorURI'] ?? '' ),
			'uri'        => (string) ( $data['PluginURI'] ?? '' ),
			'update_uri' => (string) ( $data['UpdateURI'] ?? '' ),
			'woo'        => (string) ( $data['Woo'] ?? '' ),
			'path'       => '.' === $dir ? $base . '/' . $file : $base . '/' . $dir,
			'active'     => function_exists( 'is_plugin_active' ) && is_plugin_active( $file ),
		);
	}

	/**
	 * A theme as a component.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @param array  $data       Theme headers.
	 * @param string $path       Theme folder.
	 * @param bool   $active     Whether it is the active theme or its parent.
	 * @return array
	 */
	public static function theme( string $stylesheet, array $data, string $path, bool $active ): array {
		return array(
			'id'         => 'theme:' . $stylesheet,
			'kind'       => 'theme',
			'file'       => $stylesheet,
			'slug'       => $stylesheet,
			'name'       => (string) ( $data['Name'] ?? $stylesheet ),
			'version'    => (string) ( $data['Version'] ?? '' ),
			'author'     => (string) ( $data['Author'] ?? '' ),
			'author_uri' => (string) ( $data['AuthorURI'] ?? '' ),
			'uri'        => (string) ( $data['ThemeURI'] ?? '' ),
			'update_uri' => (string) ( $data['UpdateURI'] ?? '' ),
			'woo'        => '',
			'path'       => $path,
			'active'     => $active,
		);
	}
}
