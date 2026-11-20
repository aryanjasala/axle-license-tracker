<?php
/**
 * Updates around a renewal.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Two moments where a license and an update meet.
 *
 * Before a renewal date, an update that can be downloaded now may not be
 * downloadable after it. After a license goes from expired to valid, the site
 * may be several versions behind. Both are worked out from the update
 * transient and the stored license state. Nothing here calls a vendor.
 */
class Renewal implements Service {

	public const RECORD  = '_alc_renewed';
	public const DISMISS = 'axle_license_tracker_dismiss_renewed';

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Update transient reader.
	 *
	 * @var Updates
	 */
	private Updates $updates;

	/**
	 * Per-component records.
	 *
	 * @var Licenses
	 */
	private Licenses $licenses;

	/**
	 * Sets up the watcher.
	 *
	 * @param Tracker  $tracker  Rows.
	 * @param Updates  $updates  Update transient reader.
	 * @param Licenses $licenses Per-component records.
	 */
	public function __construct( Tracker $tracker, Updates $updates, Licenses $licenses ) {
		$this->tracker  = $tracker;
		$this->updates  = $updates;
		$this->licenses = $licenses;
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'axle_license_tracker_state_changed', array( $this, 'state_changed' ), 10, 3 );
		add_action( 'axle_license_tracker_detected', array( $this, 'detected' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_' . self::DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * The newer version a component could move to, and whether it can be downloaded now.
	 *
	 * Downloadable means the update transient holds an update with a package
	 * and the stored license state is not one that stops the download. The
	 * vendor is not asked.
	 *
	 * @param array $row Component row.
	 * @return array{from: string, to: string, downloadable: bool}|null Null when no newer version is known.
	 */
	public function newer( array $row ): ?array {
		$entry = $this->updates->entry( $row );
		$to    = '';
		if ( $entry && 'response' === $entry['list'] && '' !== $entry['new_version'] ) {
			$to = $entry['new_version'];
		} elseif ( '' !== (string) $row['latest'] ) {
			$to = (string) $row['latest'];
		}
		if ( '' === $to || '' === (string) $row['version'] || version_compare( $to, (string) $row['version'], '<=' ) ) {
			return null;
		}

		$downloadable = $entry && 'response' === $entry['list'] && '' !== $entry['package'] && ! in_array( $row['state'], array( 'expired', 'invalid', 'missing' ), true );

		/**
		 * Filters whether the newer version of a component can be downloaded right now.
		 *
		 * @param bool   $downloadable Default: the update has a package and the license is not expired, invalid or missing.
		 * @param array  $row          Component row.
		 * @param string $to           The newer version.
		 */
		$downloadable = (bool) apply_filters( 'axle_license_tracker_update_downloadable', $downloadable, $row, $to );

		return array(
			'from'         => (string) $row['version'],
			'to'           => $to,
			'downloadable' => $downloadable,
		);
	}

	/**
	 * The "take it before the date" line for a renewal reminder.
	 *
	 * @param array $row Component row.
	 * @return string '' when there is nothing to say.
	 */
	public function last_chance_line( array $row ): string {
		if ( '' === $row['renewal'] || null === $row['days_left'] || $row['days_left'] < 0 || 'lifetime' === $row['cycle'] ) {
			return '';
		}
		$newer = $this->newer( $row );
		if ( ! $newer || ! $newer['downloadable'] ) {
			return '';
		}

		return sprintf(
			/* translators: 1: plugin or theme name, 2: new version, 3: renewal date. */
			__( '%1$s %2$s is available now and will not be after %3$s: rehearse it, then update.', 'axle-license-tracker' ),
			$row['name'],
			$newer['to'],
			Format::date( $row['renewal'] )
		);
	}

	/**
	 * Starts a "renewed" record when a license goes from expired to valid.
	 *
	 * @param array  $row      Component row.
	 * @param string $old      Previous state.
	 * @param string $to_state New state.
	 */
	public function state_changed( array $row, string $old, string $to_state ): void {
		if ( 'expired' !== $old || ! in_array( $to_state, array( 'valid', 'expiring' ), true ) || ! empty( $row['ignored'] ) ) {
			return;
		}
		$this->licenses->set_meta(
			(string) $row['id'],
			self::RECORD,
			array(
				'at'        => time(),
				'sent'      => '',
				'dismissed' => false,
			)
		);
	}

	/**
	 * After a detection run: fires the renewed action once per target version, and drops finished records.
	 *
	 * @param array<string, array> $rows Rows.
	 */
	public function detected( $rows ): void {
		foreach ( (array) $rows as $id => $row ) {
			if ( empty( $row['tracked'] ) ) {
				continue;
			}
			$record = $this->record( (string) $id );
			if ( ! $record ) {
				continue;
			}
			$newer = $this->newer( $row );
			if ( ! $newer ) {
				// The gap closed, or there never was one. Keep the record a week in case the update check is late.
				if ( time() - $record['at'] > WEEK_IN_SECONDS || '' !== $record['sent'] ) {
					$this->licenses->set_meta( (string) $id, self::RECORD, null );
				}
				continue;
			}
			if ( $record['sent'] === $newer['to'] ) {
				continue;
			}
			$record['sent']      = $newer['to'];
			$record['dismissed'] = false;
			$this->licenses->set_meta( (string) $id, self::RECORD, $record );

			/**
			 * Fires when a license was renewed and the component is behind a newer version.
			 *
			 * @param array  $row  Component row.
			 * @param string $from Installed version.
			 * @param string $to   Newest version known.
			 */
			do_action( 'axle_license_tracker_renewed', $row, $newer['from'], $newer['to'] );
		}
	}

	/**
	 * The stored record of a renewal, or null.
	 *
	 * @param string $id Component id.
	 * @return array{at: int, sent: string, dismissed: bool}|null
	 */
	private function record( string $id ): ?array {
		$record = $this->licenses->meta( $id, self::RECORD, null );
		if ( ! is_array( $record ) ) {
			return null;
		}

		return array(
			'at'        => (int) ( $record['at'] ?? 0 ),
			'sent'      => (string) ( $record['sent'] ?? '' ),
			'dismissed' => ! empty( $record['dismissed'] ),
		);
	}

	/**
	 * Renewed components that are still behind and not dismissed.
	 *
	 * @return array<int, array{row: array, from: string, to: string}>
	 */
	public function waiting(): array {
		$waiting = array();
		foreach ( $this->tracker->tracked() as $id => $row ) {
			if ( $row['ignored'] || ! in_array( $row['state'], array( 'valid', 'expiring' ), true ) ) {
				continue;
			}
			$record = $this->record( (string) $id );
			$newer  = $record && ! $record['dismissed'] ? $this->newer( $row ) : null;
			if ( $newer ) {
				$waiting[] = array(
					'row'  => $row,
					'from' => $newer['from'],
					'to'   => $newer['to'],
				);
			}
		}

		return $waiting;
	}

	/**
	 * The notices on Plugins and Themes.
	 */
	public function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'themes', 'plugins-network', 'themes-network' ), true ) || ! Capability::can_manage() ) {
			return;
		}

		foreach ( $this->waiting() as $item ) {
			$row     = $item['row'];
			$link    = UpdateLinks::for_row( $row );
			$dismiss = wp_nonce_url(
				add_query_arg(
					array(
						'action'    => self::DISMISS,
						'component' => $row['id'],
					),
					admin_url( 'admin-post.php' )
				),
				self::DISMISS
			);
			printf(
				'<div class="notice notice-warning alc-renewed-notice" data-component="%1$s"><p><strong>%2$s</strong> %3$s%4$s <a href="%5$s">%6$s</a></p></div>',
				esc_attr( $row['id'] ),
				esc_html( $row['name'] . ':' ),
				esc_html(
					sprintf(
						/* translators: 1: installed version, 2: newest version. */
						__( 'Renewed. Rehearse the jump from %1$s to %2$s first.', 'axle-license-tracker' ),
						$item['from'],
						$item['to']
					)
				),
				$link ? ' <a class="button button-small alc-renewed-link" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>' : '',
				esc_url( $dismiss ),
				esc_html__( 'Dismiss', 'axle-license-tracker' )
			);
		}
	}

	/**
	 * Dismisses one notice.
	 */
	public function handle_dismiss(): void {
		check_admin_referer( self::DISMISS );
		if ( ! Capability::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'axle-license-tracker' ), '', array( 'response' => 403 ) );
		}
		$id     = isset( $_GET['component'] ) ? sanitize_text_field( wp_unslash( $_GET['component'] ) ) : '';
		$record = '' !== $id ? $this->record( $id ) : null;
		if ( $record ) {
			$record['dismissed'] = true;
			$this->licenses->set_meta( $id, self::RECORD, $record );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}
}
