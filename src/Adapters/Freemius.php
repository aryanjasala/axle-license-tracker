<?php
/**
 * Freemius adapter.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Every Freemius-powered plugin and theme, from the SDK's `fs_accounts` option.
 *
 * `fs_accounts` holds `sites` (plugins) and `theme_sites`, keyed by the
 * Freemius product slug, each an FS_Site with `license_id`, `plugin_id` and
 * `is_premium`; `all_licenses`, keyed by product id, lists FS_Plugin_License
 * objects with `expiration` (null for lifetime), `secret_key`, `quota`,
 * `activated` and `is_cancelled`; `file_slug_map` maps a plugin file to its
 * Freemius slug. Checked against the Freemius WordPress SDK on GitHub.
 */
final class Freemius extends Base {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'freemius';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Freemius';
	}

	/**
	 * Freemius premium installs: the SDK in the code, or an entry in fs_accounts.
	 *
	 * @param array $component Component.
	 * @param array $signals   Detection signals.
	 * @return bool
	 */
	public function detect( array $component, array $signals ): bool {
		$site = $this->site( $component );
		if ( $site ) {
			return (bool) self::prop( $site, 'is_premium', false ) || (bool) self::prop( $site, 'license_id' );
		}

		return in_array( 'freemius', (array) ( $signals['sdks'] ?? array() ), true );
	}

	/**
	 * Reads the license attached to the install.
	 *
	 * @param array $component Component.
	 * @return LicenseState
	 */
	public function read( array $component ): LicenseState {
		$site = $this->site( $component );
		if ( ! $site ) {
			return new LicenseState(
				array(
					'state' => 'unknown',
					'note'  => __( 'Freemius has recorded no account for this install yet.', 'axle-license-tracker' ),
				)
			);
		}

		$license_id = (string) self::prop( $site, 'license_id', '' );
		if ( '' === $license_id ) {
			return new LicenseState(
				array(
					'state' => 'missing',
					'note'  => __( 'Premium install with no license attached.', 'axle-license-tracker' ),
				)
			);
		}

		$license = $this->license( (string) self::prop( $site, 'plugin_id', '' ), $license_id );
		if ( null === $license ) {
			return new LicenseState(
				array(
					'state' => 'unknown',
					'note'  => __( 'A license is attached, but its details are not stored on the site.', 'axle-license-tracker' ),
				)
			);
		}

		$expiration = self::prop( $license, 'expiration' );
		$state      = 'valid';
		$note       = '';
		if ( self::prop( $license, 'is_cancelled', false ) ) {
			$state = 'invalid';
			$note  = __( 'The license was cancelled.', 'axle-license-tracker' );
		}

		return new LicenseState(
			array(
				'state'      => $state,
				'expires'    => null === $expiration || '' === $expiration ? 'lifetime' : (string) $expiration,
				'key'        => (string) self::prop( $license, 'secret_key', '' ),
				'seats'      => (int) self::prop( $license, 'quota', 0 ),
				'seats_used' => (int) self::prop( $license, 'activated', 0 ),
				'note'       => $note,
			)
		);
	}

	/**
	 * The plugin's Freemius account screen.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public function license_url( array $component ): string {
		return admin_url( 'admin.php?page=' . rawurlencode( $this->slug( $component ) ) . '-account' );
	}

	/**
	 * The fs_accounts option.
	 *
	 * @return array
	 */
	private function accounts(): array {
		$accounts = self::option( 'fs_accounts' );

		return is_array( $accounts ) ? $accounts : array();
	}

	/**
	 * The Freemius slug of a component.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	private function slug( array $component ): string {
		$map = (array) ( $this->accounts()['file_slug_map'] ?? array() );
		if ( 'plugin' === $component['kind'] && ! empty( $map[ $component['file'] ] ) ) {
			return (string) $map[ $component['file'] ];
		}

		return (string) $component['slug'];
	}

	/**
	 * The FS_Site entry of a component.
	 *
	 * @param array $component Component.
	 * @return mixed|null
	 */
	private function site( array $component ) {
		$accounts = $this->accounts();
		$sites    = (array) ( $accounts[ 'theme' === $component['kind'] ? 'theme_sites' : 'sites' ] ?? array() );
		$slug     = $this->slug( $component );

		return $sites[ $slug ] ?? null;
	}

	/**
	 * A license from all_licenses.
	 *
	 * @param string $product_id Freemius product id.
	 * @param string $license_id License id.
	 * @return mixed|null
	 */
	private function license( string $product_id, string $license_id ) {
		$all = (array) ( $this->accounts()['all_licenses'] ?? array() );
		foreach ( (array) ( $all[ $product_id ] ?? array() ) as $license ) {
			if ( (string) self::prop( $license, 'id', '' ) === $license_id ) {
				return $license;
			}
		}

		return null;
	}
}
