<?php
/**
 * Advanced Custom Fields PRO adapter.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * ACF PRO stores the key in `acf_pro_license` as base64 of a serialized array
 * with `key` and `url` (or takes it from the ACF_PRO_LICENSE constant), and
 * the last check in `acf_pro_license_status`: `status` (active, expired,
 * cancelled, ...), `expiry` (Unix time), `lifetime`, `refunded` and
 * `next_check`. The key is read with a pattern, never unserialized.
 */
final class AcfPro extends Base {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'acf-pro';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'ACF PRO';
	}

	/**
	 * The ACF PRO plugin.
	 *
	 * @param array $component Component.
	 * @param array $signals   Detection signals.
	 * @return bool
	 */
	public function detect( array $component, array $signals ): bool {
		return self::is_plugin( $component, array( 'advanced-custom-fields-pro/acf.php' ) );
	}

	/**
	 * Reads the stored check.
	 *
	 * @param array $component Component.
	 * @return LicenseState
	 */
	public function read( array $component ): LicenseState {
		$key = self::key();
		if ( '' === $key ) {
			return new LicenseState(
				array(
					'state' => 'missing',
					'note'  => __( 'No license key is stored.', 'axle-license-tracker' ),
				)
			);
		}

		$status   = get_option( 'acf_pro_license_status' );
		$word     = strtolower( (string) self::prop( $status, 'status', '' ) );
		$lifetime = ! empty( self::prop( $status, 'lifetime' ) );
		$state    = 'unknown';
		$note     = '';
		if ( ! empty( self::prop( $status, 'refunded' ) ) ) {
			$state = 'invalid';
			$note  = __( 'The license was refunded.', 'axle-license-tracker' );
		} elseif ( 'active' === $word ) {
			$state = 'valid';
		} elseif ( 'expired' === $word ) {
			$state = 'expired';
		} elseif ( '' !== $word ) {
			$state = 'invalid';
			$note  = $word;
		}

		$expiry = self::prop( $status, 'expiry' );

		return new LicenseState(
			array(
				'state'   => $state,
				'expires' => $lifetime ? 'lifetime' : ( is_numeric( $expiry ) ? (int) $expiry : '' ),
				'key'     => $key,
				'note'    => $note,
			)
		);
	}

	/**
	 * ACF's updates screen.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public function license_url( array $component ): string {
		return admin_url( 'edit.php?post_type=acf-field-group&page=acf-settings-updates' );
	}

	/**
	 * The key, from the constant or the encoded option.
	 *
	 * @return string
	 */
	public static function key(): string {
		if ( defined( 'ACF_PRO_LICENSE' ) && is_string( constant( 'ACF_PRO_LICENSE' ) ) ) {
			return trim( (string) constant( 'ACF_PRO_LICENSE' ) );
		}

		$raw = get_option( 'acf_pro_license' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}
		$decoded = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- ACF stores the option base64-encoded.
		if ( false === $decoded ) {
			return '';
		}

		return preg_match( '/s:3:"key";s:\d+:"([^"]*)"/', $decoded, $match ) ? trim( $match[1] ) : '';
	}
}
