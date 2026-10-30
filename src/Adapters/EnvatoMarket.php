<?php
/**
 * Envato Market adapter.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Items bought on ThemeForest and CodeCanyon get updates through the Envato
 * Market plugin, which stores a personal token in the `envato_market` option
 * (`token`, plus per-item tokens under `items`; a network option when it is
 * network-activated). Envato purchases do not expire, only their support does,
 * so this adapter reports whether a token is there. Checked against
 * envato/wp-envato-market on GitHub.
 */
final class EnvatoMarket extends Base {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'envato';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Envato Market';
	}

	/**
	 * The Envato Market plugin, and items whose author URL is on an Envato marketplace.
	 *
	 * @param array $component Component.
	 * @param array $signals   Detection signals.
	 * @return bool
	 */
	public function detect( array $component, array $signals ): bool {
		if ( self::is_plugin( $component, array( 'envato-market/envato-market.php' ) ) ) {
			return true;
		}

		return (bool) preg_match( '~(^|[/.])(themeforest\.net|codecanyon\.net)(/|$)~i', (string) $component['author_uri'] );
	}

	/**
	 * Whether a token is stored.
	 *
	 * @param array $component Component.
	 * @return LicenseState
	 */
	public function read( array $component ): LicenseState {
		$options = self::option( 'envato_market' );
		$items   = (array) self::prop( $options, 'items', array() );
		$token   = (string) self::prop( $options, 'token', '' );
		if ( '' === trim( $token ) && ! $items ) {
			return new LicenseState(
				array(
					'state' => 'missing',
					'note'  => __( 'Envato Market has no token, so items bought on Envato get no updates.', 'axle-license-tracker' ),
				)
			);
		}

		return new LicenseState(
			array(
				'state'   => 'valid',
				'expires' => 'lifetime',
				'note'    => __( 'Envato updates do not expire; support does.', 'axle-license-tracker' ),
			)
		);
	}

	/**
	 * The Envato Market screen.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	public function license_url( array $component ): string {
		return admin_url( 'admin.php?page=envato-market' );
	}
}
