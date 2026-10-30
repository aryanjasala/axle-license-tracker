<?php
/**
 * Gravity Forms adapter.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms stores only an md5 of the key in `rg_gforms_key` (or takes it
 * from the GF_LICENSE_KEY constant), and the last check in
 * `gform_version_info`: `is_valid_key`, `expiration_time`, `version` (the
 * newest release) and `timestamp`. The fingerprint is made from the md5.
 */
final class GravityForms extends Base {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'gravityforms';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Gravity Forms';
	}

	/**
	 * The Gravity Forms plugin.
	 *
	 * @param array $component Component.
	 * @param array $signals   Detection signals.
	 * @return bool
	 */
	public function detect( array $component, array $signals ): bool {
		return self::is_plugin( $component, array( 'gravityforms/gravityforms.php' ) );
	}

	/**
	 * Reads the stored check.
	 *
	 * @param array $component Component.
	 * @return LicenseState
	 */
	public function read( array $component ): LicenseState {
		$hash = defined( 'GF_LICENSE_KEY' ) && is_string( constant( 'GF_LICENSE_KEY' ) ) ? md5( trim( (string) constant( 'GF_LICENSE_KEY' ) ) ) : (string) ( is_scalar( get_option( 'rg_gforms_key' ) ) ? get_option( 'rg_gforms_key' ) : '' );
		if ( '' === trim( $hash ) ) {
			return new LicenseState(
				array(
					'state' => 'missing',
					'note'  => __( 'No license key is stored.', 'axle-license-tracker' ),
				)
			);
		}

		$info  = get_option( 'gform_version_info' );
		$flag  = self::prop( $info, 'is_valid_key' );
		$state = null === $flag ? 'unknown' : ( (bool) $flag ? 'valid' : 'invalid' );

		return new LicenseState(
			array(
				'state'   => $state,
				'expires' => self::prop( $info, 'expiration_time', '' ),
				'checked' => (int) self::prop( $info, 'timestamp', 0 ),
				'key'     => $hash,
				'latest'  => (string) self::prop( $info, 'version', '' ),
				'note'    => null === $flag ? __( 'A key is stored, but Gravity Forms has not recorded a check yet.', 'axle-license-tracker' ) : '',
			)
		);
	}

	/**
	 * Gravity Forms settings, where the key is entered.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public function license_url( array $component ): string {
		return admin_url( 'admin.php?page=gf_settings' );
	}
}
