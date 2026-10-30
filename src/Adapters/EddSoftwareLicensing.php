<?php
/**
 * Easy Digital Downloads Software Licensing adapter.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins and themes that bundle EDD's updater class. They store the key and
 * the last status the vendor returned in an option pair, by convention
 * `{prefix}_license_key` and `{prefix}_license_status`, where the prefix is
 * usually the folder name with underscores. The status is a word (`valid`,
 * `expired`, `invalid`, `disabled`, `site_inactive`, `inactive`) or the whole
 * check_license response. The updater caches the vendor's version data in an
 * `edd_sl_` option named from the slug and key, which gives the newest
 * version. Checked against EDD's sample plugin and EDD_SL_Plugin_Updater.
 */
final class EddSoftwareLicensing extends Base {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'edd-sl';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'EDD Software Licensing';
	}

	/**
	 * Components with the EDD updater class, or with the option pair.
	 *
	 * @param array $component Component.
	 * @param array $signals   Detection signals.
	 * @return bool
	 */
	public function detect( array $component, array $signals ): bool {
		return in_array( 'edd-sl', (array) ( $signals['sdks'] ?? array() ), true ) || '' !== $this->prefix( $component );
	}

	/**
	 * Reads the stored key and status.
	 *
	 * @param array $component Component.
	 * @return LicenseState
	 */
	public function read( array $component ): LicenseState {
		$prefix = $this->prefix( $component );
		$key    = '' !== $prefix ? trim( (string) self::scalar( self::option( $prefix . '_license_key' ) ) ) : '';
		if ( '' === $key ) {
			return new LicenseState(
				array(
					'state' => 'missing',
					'note'  => __( 'No license key is stored.', 'axle-license-tracker' ),
				)
			);
		}

		$raw     = self::option( $prefix . '_license_status' );
		$status  = is_scalar( $raw ) ? (string) $raw : (string) self::prop( $raw, 'license', '' );
		$expires = is_scalar( $raw ) ? self::option( $prefix . '_license_expires' ) : self::prop( $raw, 'expires', '' );

		return new LicenseState(
			array(
				'state'      => self::map( $status ),
				'expires'    => is_scalar( $expires ) ? (string) $expires : '',
				'key'        => $key,
				'latest'     => $this->latest( $component, $key ),
				'seats'      => (int) self::prop( $raw, 'license_limit', 0 ),
				'seats_used' => (int) self::prop( $raw, 'site_count', 0 ),
				'note'       => '' === $status ? __( 'A key is stored, but no status from the vendor.', 'axle-license-tracker' ) : '',
			)
		);
	}

	/**
	 * EDD plugins have no shared license screen.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public function license_url( array $component ): string {
		return '';
	}

	/**
	 * EDD status word to a license state.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function map( string $status ): string {
		$status = strtolower( trim( $status ) );
		if ( '' === $status ) {
			return 'unknown';
		}
		if ( 'valid' === $status ) {
			return 'valid';
		}
		if ( 'expired' === $status ) {
			return 'expired';
		}

		return 'invalid';
	}

	/**
	 * The option prefix of a component, or '' when no option pair exists.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public function prefix( array $component ): string {
		$slug       = (string) $component['slug'];
		$candidates = array_unique( array( str_replace( '-', '_', $slug ), $slug, str_replace( '-', '_', basename( (string) $component['file'], '.php' ) ) ) );
		foreach ( $candidates as $candidate ) {
			$value = self::option( $candidate . '_license_key' );
			if ( null !== $value && false !== $value ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Newest version from the updater's cached version data.
	 *
	 * @param array  $component Component.
	 * @param string $key       License key.
	 * @return string
	 */
	private function latest( array $component, string $key ): string {
		$slug  = 'plugin' === $component['kind'] ? basename( (string) $component['file'], '.php' ) : (string) $component['slug'];
		$cache = get_option( 'edd_sl_' . md5( serialize( $slug . $key . '' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The cache key EDD's updater builds.
		$data  = is_array( $cache ) && isset( $cache['value'] ) ? json_decode( (string) $cache['value'], true ) : null;

		return is_array( $data ) && isset( $data['new_version'] ) && is_scalar( $data['new_version'] ) ? (string) $data['new_version'] : '';
	}

	/**
	 * A scalar option value, or ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
