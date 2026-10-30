<?php
/**
 * Elementor Pro adapter.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro keeps the key in `elementor_pro_license_key` and caches the
 * last license check in `_elementor_pro_license_v2_data` (a fallback copy in
 * `_elementor_pro_license_v2_data_fallback`): an array with `timeout` (site
 * local time, 12 hours after the check) and `value`, the JSON response with
 * `success`, `license` (valid, expired, disabled, ...), `expires` (a date or
 * `lifetime`) and `error` when the check failed.
 */
final class ElementorPro extends Base {

	private const CACHE_HOURS = 12;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'elementor-pro';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Elementor Pro';
	}

	/**
	 * The Elementor Pro plugin.
	 *
	 * @param array $component Component.
	 * @param array $signals   Detection signals.
	 * @return bool
	 */
	public function detect( array $component, array $signals ): bool {
		return self::is_plugin( $component, array( 'elementor-pro/elementor-pro.php' ) );
	}

	/**
	 * Reads the cached license check.
	 *
	 * @param array $component Component.
	 * @return LicenseState
	 */
	public function read( array $component ): LicenseState {
		$key = trim( (string) ( is_scalar( self::option( 'elementor_pro_license_key' ) ) ? self::option( 'elementor_pro_license_key' ) : '' ) );
		if ( '' === $key ) {
			return new LicenseState(
				array(
					'state' => 'missing',
					'note'  => __( 'No license key is stored.', 'axle-license-tracker' ),
				)
			);
		}

		$cache = self::cache( '_elementor_pro_license_v2_data' );
		if ( null === $cache ) {
			$cache = self::cache( '_elementor_pro_license_v2_data_fallback' );
		}
		if ( null === $cache ) {
			return new LicenseState(
				array(
					'state' => 'unknown',
					'key'   => $key,
					'note'  => __( 'A key is stored, but Elementor has not recorded a check yet.', 'axle-license-tracker' ),
				)
			);
		}

		$value   = $cache['value'];
		$state   = 'unknown';
		$note    = '';
		$license = strtolower( (string) self::prop( $value, 'license', '' ) );
		if ( ! empty( $value['success'] ) || 'valid' === $license ) {
			$state = 'valid';
		} elseif ( 'expired' === $license || 'expired' === self::prop( $value, 'error' ) ) {
			$state = 'expired';
		} elseif ( '' !== $license || ! empty( $value['error'] ) ) {
			$state = 'invalid';
			$note  = str_replace( '_', ' ', (string) ( '' !== $license ? $license : $value['error'] ) );
		}

		// Elementor writes the timeout in site-local time.
		$timeout = (int) $cache['timeout'];
		$checked = $timeout ? $timeout - self::CACHE_HOURS * HOUR_IN_SECONDS - (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) : 0;

		return new LicenseState(
			array(
				'state'   => $state,
				'expires' => (string) self::prop( $value, 'expires', '' ),
				'checked' => $checked,
				'stale'   => $timeout && $timeout < (int) current_time( 'timestamp' ), // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Elementor stores site-local time.
				'key'     => $key,
				'note'    => $note,
			)
		);
	}

	/**
	 * Elementor's license screen.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public function license_url( array $component ): string {
		return admin_url( 'admin.php?page=elementor-license' );
	}

	/**
	 * One cached check.
	 *
	 * @param string $option Option name.
	 * @return array{value: array, timeout: int}|null
	 */
	private static function cache( string $option ): ?array {
		$raw = get_option( $option );
		if ( ! is_array( $raw ) || empty( $raw['value'] ) ) {
			return null;
		}
		$value = is_array( $raw['value'] ) ? $raw['value'] : json_decode( (string) $raw['value'], true );

		return is_array( $value ) ? array(
			'value'   => $value,
			'timeout' => (int) ( $raw['timeout'] ?? 0 ),
		) : null;
	}
}
