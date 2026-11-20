<?php
/**
 * The right link for "update this", for the person who will click it.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Update Rehearsal when it is active and the person can use it, a plain
 * update link for people who can update, and nothing for everyone else.
 * It never asks a vendor anything: it only builds links.
 */
final class UpdateLinks {

	/**
	 * Whether Update Rehearsal is active on this site.
	 *
	 * @return bool
	 */
	public static function rehearsal_active(): bool {
		/**
		 * Filters whether Update Rehearsal counts as active.
		 *
		 * @param bool $active Default true when its plugin file is loaded.
		 */
		return (bool) apply_filters( 'axle_license_tracker_rehearsal_active', defined( 'AryanX\\Axle\\UpdateRehearsal\\PLUGIN_FILE' ) );
	}

	/**
	 * The capability Update Rehearsal asks for, read through its own filter.
	 *
	 * @return string
	 */
	private static function rehearsal_capability(): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Update Rehearsal's own filter, applied so both plugins agree on who can use it.
		$capability = (string) apply_filters( 'update_rehearsal_capability', 'update_plugins' );

		return '' !== $capability ? $capability : 'update_plugins';
	}

	/**
	 * A link for one component and one person.
	 *
	 * @param array    $row     Component row.
	 * @param int|null $user_id The person, or null for the current user.
	 * @param bool     $nonce   Whether a plain update link may carry a nonce. An email must not.
	 * @return array{url: string, label: string, kind: string}|null kind is rehearse or update. Null when the person can do neither.
	 */
	public static function for_row( array $row, ?int $user_id = null, bool $nonce = true ): ?array {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;
		if ( $user_id <= 0 ) {
			return null;
		}
		$is_theme = 'theme' === $row['kind'];
		$file     = (string) $row['file'];

		if ( ! $is_theme && self::rehearsal_active() && is_main_site() && user_can( $user_id, self::rehearsal_capability() ) ) {
			$link = array(
				'url'   => add_query_arg(
					array(
						'page'   => 'update-rehearsal',
						'kind'   => 'update',
						'plugin' => $file,
					),
					admin_url( 'tools.php' )
				),
				'label' => __( 'Rehearse the update', 'axle-license-tracker' ),
				'kind'  => 'rehearse',
			);
		} elseif ( user_can( $user_id, $is_theme ? 'update_themes' : 'update_plugins' ) ) {
			if ( $nonce ) {
				$url = $is_theme
					? wp_nonce_url( self_admin_url( 'update.php?action=upgrade-theme&theme=' . rawurlencode( $file ) ), 'upgrade-theme_' . $file )
					: wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );
			} else {
				$url = self_admin_url( 'update-core.php' );
			}
			$link = array(
				'url'   => $url,
				'label' => __( 'Update', 'axle-license-tracker' ),
				'kind'  => 'update',
			);
		} else {
			return null;
		}

		/**
		 * Filters the update link a person gets for a component.
		 *
		 * @param array{url: string, label: string, kind: string} $link    The link.
		 * @param array                                           $row     Component row.
		 * @param int                                             $user_id The person.
		 */
		$filtered = (array) apply_filters( 'axle_license_tracker_update_link', $link, $row, $user_id );

		return ! empty( $filtered['url'] ) && ! empty( $filtered['label'] ) && ! empty( $filtered['kind'] ) ? array_map( 'strval', array_intersect_key( $filtered, $link ) ) : null;
	}

	/**
	 * A link for an email address: the link of the user with that address, if any.
	 *
	 * @param array  $row   Component row.
	 * @param string $email Recipient.
	 * @return array{url: string, label: string, kind: string}|null
	 */
	public static function for_email( array $row, string $email ): ?array {
		$user = get_user_by( 'email', $email );

		return $user ? self::for_row( $row, (int) $user->ID, false ) : null;
	}
}
