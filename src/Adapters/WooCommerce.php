<?php
/**
 * WooCommerce.com extensions adapter.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Extensions sold on WooCommerce.com carry a `Woo: <product id>:<file id>`
 * header. WooCommerce's helper stores the store's connection in the
 * `woocommerce_helper_data` option (`auth.access_token`) and caches the
 * account's subscriptions in the `_woocommerce_helper_subscriptions`
 * transient: each with `product_id`, `product_key`, `expires` (Unix time),
 * `expired`, `expiring`, `autorenew`, `lifetime`, `sites_max`,
 * `sites_active` and `has_update`. Checked against WooCommerce core
 * (includes/admin/helper/class-wc-helper.php).
 */
final class WooCommerce extends Base {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'woocommerce';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'WooCommerce.com';
	}

	/**
	 * Components with a Woo header.
	 *
	 * @param array $component Component.
	 * @param array $signals   Detection signals.
	 * @return bool
	 */
	public function detect( array $component, array $signals ): bool {
		return 0 < self::product_id( $component );
	}

	/**
	 * Reads the subscription for the extension's product.
	 *
	 * @param array $component Component.
	 * @return LicenseState
	 */
	public function read( array $component ): LicenseState {
		$data = get_option( 'woocommerce_helper_data', array() );
		if ( empty( self::prop( self::prop( $data, 'auth', array() ), 'access_token' ) ) ) {
			return new LicenseState(
				array(
					'state' => 'missing',
					'note'  => __( 'The store is not connected to WooCommerce.com, so this extension gets no updates.', 'axle-license-tracker' ),
				)
			);
		}

		$subscriptions = get_transient( '_woocommerce_helper_subscriptions' );
		if ( ! is_array( $subscriptions ) ) {
			return new LicenseState(
				array(
					'state' => 'unknown',
					'stale' => true,
					'note'  => __( 'WooCommerce has no fresh subscription data. Open WooCommerce > Extensions > My subscriptions to refresh it.', 'axle-license-tracker' ),
				)
			);
		}

		$product = self::product_id( $component );
		$match   = null;
		foreach ( $subscriptions as $subscription ) {
			if ( (int) self::prop( $subscription, 'product_id', 0 ) === $product ) {
				$match = $subscription;
				// Prefer a subscription that has not expired.
				if ( empty( self::prop( $subscription, 'expired' ) ) ) {
					break;
				}
			}
		}

		if ( null === $match ) {
			return new LicenseState(
				array(
					'state' => 'missing',
					'note'  => __( 'The connected WooCommerce.com account has no subscription for this extension.', 'axle-license-tracker' ),
				)
			);
		}

		$lifetime = ! empty( self::prop( $match, 'lifetime' ) );
		$expired  = ! $lifetime && ! empty( self::prop( $match, 'expired' ) );
		$expires  = self::prop( $match, 'expires' );

		return new LicenseState(
			array(
				'state'      => $expired ? 'expired' : 'valid',
				'expires'    => $lifetime ? 'lifetime' : ( is_numeric( $expires ) ? (int) $expires : '' ),
				'key'        => (string) self::prop( $match, 'product_key', '' ),
				'auto_renew' => (bool) self::prop( $match, 'autorenew', false ),
				'seats'      => (int) self::prop( $match, 'sites_max', 0 ),
				'seats_used' => (int) self::prop( $match, 'sites_active', 0 ),
			)
		);
	}

	/**
	 * WooCommerce's subscriptions screen.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public function license_url( array $component ): string {
		return admin_url( 'admin.php?page=wc-admin&tab=my-subscriptions&path=' . rawurlencode( '/extensions' ) );
	}

	/**
	 * The product id from the Woo header.
	 *
	 * @param array $component Component.
	 * @return int
	 */
	public static function product_id( array $component ): int {
		$header = trim( (string) ( $component['woo'] ?? '' ) );
		if ( '' === $header ) {
			return 0;
		}
		$parts = explode( ':', $header );

		return ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
	}
}
