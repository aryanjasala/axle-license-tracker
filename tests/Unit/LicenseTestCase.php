<?php
/**
 * Base class with the WordPress stand-ins most license tests need.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Messages;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/axle-license-tracker.php';

/**
 * Stubs options, transients, post meta and the sanitizers with plain PHP.
 */
abstract class LicenseTestCase extends TestCase {

	/**
	 * Site transients by name.
	 *
	 * @var array<string, mixed>
	 */
	protected $site_transients = array();

	/**
	 * Transients by name.
	 *
	 * @var array<string, mixed>
	 */
	protected $transients = array();

	/**
	 * Post meta: post id => key => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected $meta = array();

	/**
	 * Posts created: id => fields.
	 *
	 * @var array<int, array>
	 */
	protected $posts = array();

	protected function setUp(): void {
		parent::setUp();
		Messages::reset();
		$this->site_transients = array();
		$this->transients      = array();
		$this->meta            = array();
		$this->posts           = array();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_email' )->alias( 'trim' );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'sanitize_title' )->alias(
			static function ( $title ) {
				return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( $url ) {
				return false !== filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( null );
		Functions\when( 'current_time' )->alias(
			static function ( $type ) {
				return 'timestamp' === $type ? time() : gmdate( $type );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_site_transient' )->alias(
			function ( $name ) {
				return $this->site_transients[ $name ] ?? false;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'metadata_exists' )->alias(
			function ( $type, $id, $key ) {
				return isset( $this->meta[ $id ] ) && array_key_exists( $key, $this->meta[ $id ] );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key = '', $single = false ) {
				return $this->meta[ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			function ( $fields ) {
				$id                 = 100 + count( $this->posts );
				$this->posts[ $id ] = $fields;
				foreach ( (array) ( $fields['meta_input'] ?? array() ) as $key => $value ) {
					$this->meta[ $id ][ $key ] = $value;
				}
				return $id;
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				return ( $args['paged'] ?? 1 ) > 1 ? array() : array_keys( $this->posts );
			}
		);
	}

	/**
	 * A plugin component.
	 *
	 * @param string $file   Plugin file.
	 * @param array  $extra  Fields to change.
	 * @return array
	 */
	protected function plugin( string $file, array $extra = array() ): array {
		$dir = dirname( $file );

		return array_merge(
			array(
				'id'         => 'plugin:' . $file,
				'kind'       => 'plugin',
				'file'       => $file,
				'slug'       => '.' === $dir ? basename( $file, '.php' ) : $dir,
				'name'       => 'Test ' . $file,
				'version'    => '1.0.0',
				'author'     => '',
				'author_uri' => '',
				'uri'        => '',
				'update_uri' => '',
				'woo'        => '',
				'path'       => '/tmp/none',
				'active'     => true,
			),
			$extra
		);
	}
}
