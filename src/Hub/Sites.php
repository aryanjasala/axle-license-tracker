<?php
/**
 * The remote sites a hub watches.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the sites (the address, a user name and the encrypted application
 * password) and what each one last reported. The password never leaves this
 * class except through password(), which only the fetch uses.
 */
class Sites {

	public const OPTION = 'axle_license_tracker_hub_sites';
	public const DATA   = 'axle_license_tracker_hub_data_';

	/**
	 * Most sites a hub keeps.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Filters how many remote sites a hub can hold.
		 *
		 * @param int $limit Default 100, from 1 to 500.
		 */
		return max( 1, min( 500, (int) apply_filters( 'axle_license_tracker_hub_max_sites', 100 ) ) );
	}

	/**
	 * The id of a site: a short hash of its address.
	 *
	 * @param string $url Cleaned address.
	 * @return string
	 */
	public static function id_for( string $url ): string {
		return 's' . substr( sha1( strtolower( $url ) ), 0, 9 );
	}

	/**
	 * Every site record, keyed by id. Includes the sealed password.
	 *
	 * @return array<string, mixed>
	 */
	private function records(): array {
		$records = get_option( self::OPTION, array() );

		return is_array( $records ) ? $records : array();
	}

	/**
	 * Every site, without its password, oldest first.
	 *
	 * @return array<string, array>
	 */
	public function all(): array {
		$sites = array();
		foreach ( $this->records() as $id => $record ) {
			if ( is_array( $record ) ) {
				$sites[ (string) $id ] = self::public_view( $record );
			}
		}

		return $sites;
	}

	/**
	 * One site, without its password.
	 *
	 * @param string $id Site id.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
		$records = $this->records();

		return isset( $records[ $id ] ) && is_array( $records[ $id ] ) ? self::public_view( $records[ $id ] ) : null;
	}

	/**
	 * A record without its password.
	 *
	 * @param array $record Stored record.
	 * @return array
	 */
	public static function public_view( array $record ): array {
		$view = array(
			'id'    => (string) ( $record['id'] ?? '' ),
			'url'   => (string) ( $record['url'] ?? '' ),
			'label' => (string) ( $record['label'] ?? '' ),
			'user'  => (string) ( $record['user'] ?? '' ),
			'added' => (int) ( $record['added'] ?? 0 ),
			'next'  => (int) ( $record['next'] ?? 0 ),
			'fails' => (int) ( $record['fails'] ?? 0 ),
			'tried' => (int) ( $record['tried'] ?? 0 ),
			'ok'    => (int) ( $record['ok'] ?? 0 ),
			'error' => (string) ( $record['error'] ?? '' ),
			'count' => (int) ( $record['count'] ?? 0 ),
		);

		return $view;
	}

	/**
	 * Adds a site.
	 *
	 * @param string $url      Address.
	 * @param string $user     User name on that site.
	 * @param string $password Application password.
	 * @param string $label    Name to show, default the host.
	 * @return array|\WP_Error The new record, or why it was refused.
	 */
	public function add( string $url, string $user, string $password, string $label = '' ) {
		$url = Guard::check( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$user     = trim( sanitize_text_field( $user ) );
		$password = trim( $password );
		if ( '' === $user || '' === $password || strlen( $user ) > 100 || strlen( $password ) > 200 ) {
			return new \WP_Error( 'axle_license_tracker_credentials', __( 'Enter the user name and the application password of a user with the License reporter role on that site.', 'axle-license-tracker' ) );
		}
		if ( ! Crypt::available() ) {
			return new \WP_Error( 'axle_license_tracker_crypt', __( 'This server cannot encrypt, so it cannot keep a password safely. Ask your host to enable the PHP openssl extension.', 'axle-license-tracker' ) );
		}

		$records = $this->records();
		$id      = self::id_for( $url );
		if ( isset( $records[ $id ] ) ) {
			return new \WP_Error( 'axle_license_tracker_duplicate', __( 'That site is already in the hub. Remove it first to change its login.', 'axle-license-tracker' ) );
		}
		if ( count( $records ) >= self::limit() ) {
			return new \WP_Error( 'axle_license_tracker_limit', __( 'The hub is full. Remove a site before adding another.', 'axle-license-tracker' ) );
		}

		$label = trim( sanitize_text_field( $label ) );
		if ( '' === $label ) {
			$label = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		$records[ $id ] = array(
			'id'     => $id,
			'url'    => $url,
			'label'  => substr( $label, 0, 100 ),
			'user'   => $user,
			'secret' => Crypt::seal( $password ),
			'added'  => time(),
			'next'   => 0,
			'fails'  => 0,
			'tried'  => 0,
			'ok'     => 0,
			'error'  => '',
			'count'  => 0,
		);
		update_option( self::OPTION, $records, false );

		/**
		 * Fires after a remote site was added to the hub.
		 *
		 * @param array $site The site, without its password.
		 */
		do_action( 'axle_license_tracker_hub_site_added', self::public_view( $records[ $id ] ) );

		return self::public_view( $records[ $id ] );
	}

	/**
	 * Removes a site, its password and everything it reported.
	 *
	 * @param string $id Site id.
	 * @return bool Whether it existed.
	 */
	public function remove( string $id ): bool {
		$records = $this->records();
		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		$site = self::public_view( (array) $records[ $id ] );
		unset( $records[ $id ] );
		update_option( self::OPTION, $records, false );
		delete_option( self::DATA . $id );

		/**
		 * Fires after a remote site, its password and its data were removed.
		 *
		 * @param string $id   Site id.
		 * @param array  $site The site as it was, without its password.
		 */
		do_action( 'axle_license_tracker_hub_site_removed', $id, $site );

		return true;
	}

	/**
	 * Changes the bookkeeping of a site (never the password or address).
	 *
	 * @param string $id      Site id.
	 * @param array  $changes next, fails, tried, ok, error, count.
	 */
	public function update( string $id, array $changes ): void {
		$records = $this->records();
		if ( ! isset( $records[ $id ] ) ) {
			return;
		}

		$allowed        = array_intersect_key( $changes, array_flip( array( 'next', 'fails', 'tried', 'ok', 'error', 'count' ) ) );
		$records[ $id ] = array_merge( (array) $records[ $id ], $allowed );
		update_option( self::OPTION, $records, false );
	}

	/**
	 * The password of a site, for the request only.
	 *
	 * @param string $id Site id.
	 * @return string|null Null when it cannot be read, for instance after the salts changed.
	 */
	public function password( string $id ): ?string {
		$records = $this->records();

		return isset( $records[ $id ]['secret'] ) ? Crypt::open( (string) $records[ $id ]['secret'] ) : null;
	}

	/**
	 * What a site last reported.
	 *
	 * @param string $id Site id.
	 * @return array name, plugin, generated, fetched and components, or an empty array.
	 */
	public function data( string $id ): array {
		$data = get_option( self::DATA . $id, array() );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Keeps what a site reported.
	 *
	 * @param string $id   Site id.
	 * @param array  $data The cleaned report.
	 */
	public function save_data( string $id, array $data ): void {
		update_option( self::DATA . $id, $data, false );
	}
}
