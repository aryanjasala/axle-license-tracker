<?php
/**
 * Plugins > Licenses.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Admin;

use AryanX\Axle\LicenseTracker\Capability;
use AryanX\Axle\LicenseTracker\Details;
use AryanX\Axle\LicenseTracker\Export;
use AryanX\Axle\LicenseTracker\Messages;
use AryanX\Axle\LicenseTracker\Service;
use AryanX\Axle\LicenseTracker\Settings;
use AryanX\Axle\LicenseTracker\Tracker;

use const AryanX\Axle\LicenseTracker\PLUGIN_FILE;
use const AryanX\Axle\LicenseTracker\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The license screen: summary tiles, the table with filters and row actions,
 * the edit details panel and the settings tab. Every change goes through
 * admin-post.php with a nonce and the capability check.
 */
class Page implements Service {

	public const SLUG = 'axle-license-tracker';

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Manual details.
	 *
	 * @var Details
	 */
	private Details $details;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Exports.
	 *
	 * @var Export
	 */
	private Export $export;

	/**
	 * Sets up the screen.
	 *
	 * @param Tracker  $tracker  Rows.
	 * @param Details  $details  Manual details.
	 * @param Settings $settings Settings.
	 * @param Export   $export   Exports.
	 */
	public function __construct( Tracker $tracker, Details $details, Settings $settings, Export $export ) {
		$this->tracker  = $tracker;
		$this->details  = $details;
		$this->settings = $settings;
		$this->export   = $export;
	}

	/**
	 * Adds the menu, the handlers and the assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_axle_license_tracker_recheck', array( $this, 'handle_recheck' ) );
		add_action( 'admin_post_axle_license_tracker_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_axle_license_tracker_ignore', array( $this, 'handle_ignore' ) );
		add_action( 'admin_post_axle_license_tracker_settings', array( $this, 'handle_settings' ) );
		add_action( 'admin_post_axle_license_tracker_export', array( $this, 'handle_export' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'plugins.php' ) );
	}

	/**
	 * Adds Plugins > Licenses.
	 */
	public function menu(): void {
		add_plugins_page(
			__( 'Licenses', 'axle-license-tracker' ),
			__( 'Licenses', 'axle-license-tracker' ),
			Capability::manage(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Adds a Licenses link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		if ( Capability::can_manage() ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Licenses', 'axle-license-tracker' ) ) );
		}

		return $links;
	}

	/**
	 * Loads the screen's CSS and JavaScript, and the pill styles on Plugins, Themes and the Dashboard.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ): void {
		$hook = (string) $hook;
		if ( ! in_array( $hook, array( 'plugins_page_' . self::SLUG, 'plugins.php', 'themes.php', 'index.php' ), true ) ) {
			return;
		}

		$url = plugins_url( 'assets/', PLUGIN_FILE );
		wp_enqueue_style( 'axle-license-tracker', $url . 'admin.css', array(), VERSION );
		if ( 'plugins_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script( 'axle-license-tracker', $url . 'admin.js', array( 'wp-hooks' ), VERSION, true );
		$saved = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which notice to show.
		if ( isset( $_GET['alc_notice'], $_GET['edit'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['alc_notice'] ) ) ) {
			$component = sanitize_text_field( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$row       = $this->tracker->row( $component );
			if ( $row ) {
				$saved = array(
					'component' => $component,
					'fields'    => \AryanX\Axle\LicenseTracker\Report::component( $row ),
				);
			}
		}
		wp_localize_script(
			'axle-license-tracker',
			'axleLicenseTracker',
			array(
				'saved'  => $saved,
				'locale' => str_replace( '_', '-', get_user_locale() ),
			)
		);
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		if ( ! Capability::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see licenses.', 'axle-license-tracker' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading which view to show.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$edit = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap alc-wrap">';
		if ( '' !== $edit ) {
			$this->render_edit( $edit );
		} elseif ( 'settings' === $tab ) {
			$this->render_header( 'settings' );
			$this->render_settings();
		} else {
			// Records and the stored state follow what is installed now.
			$this->tracker->sync();
			$this->render_header( 'licenses' );
			$this->render_list();
		}
		echo '</div>';
	}

	/**
	 * Title, notice and tabs.
	 *
	 * @param string $current licenses or settings.
	 */
	private function render_header( string $current ): void {
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Licenses', 'axle-license-tracker' ) . '</h1>';
		if ( 'licenses' === $current ) {
			printf( '<form method="post" action="%s" class="alc-inline-form"><input type="hidden" name="action" value="axle_license_tracker_recheck">', esc_url( admin_url( 'admin-post.php' ) ) );
			wp_nonce_field( 'axle_license_tracker_recheck' );
			printf( '<button type="submit" class="page-title-action">%s</button></form>', esc_html__( 'Check again', 'axle-license-tracker' ) );
		}
		echo '<hr class="wp-header-end">';
		$this->render_notice();

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'License screens', 'axle-license-tracker' ) . '">';
		foreach ( array(
			'licenses' => array( __( 'Licenses', 'axle-license-tracker' ), self::url() ),
			'settings' => array( __( 'Reminders', 'axle-license-tracker' ), self::url( array( 'tab' => 'settings' ) ) ),
		) as $key => $tab ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( $tab[1] ),
				$key === $current ? ' nav-tab-active' : '',
				$key === $current ? ' aria-current="page"' : '',
				esc_html( $tab[0] )
			);
		}
		echo '</nav>';
	}

	/**
	 * The notice after an action.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a fixed message.
		$notice = isset( $_GET['alc_notice'] ) ? sanitize_key( wp_unslash( $_GET['alc_notice'] ) ) : '';
		if ( ! in_array( $notice, array( 'saved', 'rechecked', 'ignored', 'unignored', 'settings', 'invalid', 'not_found' ), true ) ) {
			return;
		}
		$type = in_array( $notice, array( 'invalid', 'not_found' ), true ) ? 'error' : 'success';
		printf( '<div class="notice notice-%1$s is-dismissible alc-notice" role="status"><p>%2$s</p></div>', esc_attr( $type ), esc_html( Messages::get( $notice ) ) );
	}

	/**
	 * The tiles, filters and table.
	 */
	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters for a read-only view.
		$view    = isset( $_GET['view'] ) && 'all' === $_GET['view'] ? 'all' : 'premium';
		$filters = array();
		foreach ( array( 'state', 'risk', 'vendor', 'owner' ) as $key ) {
			$filters[ $key ] = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$all     = $this->tracker->rows();
		$tracked = $this->tracker->tracked();
		$totals  = Tracker::totals( $tracked );
		$window  = max( $this->tracker->reminder_days() );

		$this->render_tiles( $totals, $window );

		$rows = 'all' === $view ? $all : $tracked;
		$rows = array_filter(
			$rows,
			static function ( array $row ) use ( $filters ): bool {
				return ( '' === $filters['state'] || $row['state'] === $filters['state'] )
					&& ( '' === $filters['risk'] || $row['risk'] === $filters['risk'] )
					&& ( '' === $filters['vendor'] || $row['vendor'] === $filters['vendor'] )
					&& ( '' === $filters['owner'] || $row['owner'] === $filters['owner'] );
			}
		);
		// Ignored rows go last.
		uasort(
			$rows,
			static function ( array $a, array $b ): int {
				$ignored = (int) $a['ignored'] <=> (int) $b['ignored'];
				return 0 !== $ignored ? $ignored : strcasecmp( $a['name'], $b['name'] );
			}
		);

		echo '<ul class="subsubsub">';
		printf(
			'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a> |</li> <li><a href="%5$s"%6$s>%7$s <span class="count">(%8$d)</span></a></li>',
			esc_url( self::url() ),
			'premium' === $view ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Premium', 'axle-license-tracker' ),
			count( $tracked ),
			esc_url( self::url( array( 'view' => 'all' ) ) ),
			'all' === $view ? ' class="current" aria-current="page"' : '',
			esc_html__( 'All plugins and themes', 'axle-license-tracker' ),
			count( $all )
		);
		echo '</ul>';

		$this->render_filters( 'all' === $view ? $all : $tracked, $filters, $view );

		if ( ! $rows ) {
			echo '<p class="alc-empty">' . esc_html( $tracked || array_filter( $filters ) ? __( 'Nothing matches these filters.', 'axle-license-tracker' ) : Messages::get( 'no_premium' ) ) . '</p>';
			$this->render_exports();
			return;
		}

		echo '<table class="widefat striped alc-table"><caption class="screen-reader-text">' . esc_html__( 'Plugins and themes with their license state and update risk', 'axle-license-tracker' ) . '</caption><thead><tr>';
		foreach ( array(
			__( 'Plugin or theme', 'axle-license-tracker' ),
			__( 'Type', 'axle-license-tracker' ),
			__( 'Vendor', 'axle-license-tracker' ),
			__( 'License', 'axle-license-tracker' ),
			__( 'Updates', 'axle-license-tracker' ),
			__( 'Renews', 'axle-license-tracker' ),
			__( 'Cost', 'axle-license-tracker' ),
			__( 'Owner', 'axle-license-tracker' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$this->render_row( $row );
		}
		echo '</tbody></table>';

		$this->render_exports();
	}

	/**
	 * Summary tiles.
	 *
	 * @param array $totals Totals.
	 * @param int   $window Reminder window in days.
	 */
	private function render_tiles( array $totals, int $window ): void {
		$costs = array();
		foreach ( $totals['costs'] as $currency => $minor ) {
			$costs[] = Display::cost( (int) $minor, (string) $currency );
		}

		$tiles = array(
			array( 'premium', (string) $totals['premium'], __( 'Premium plugins and themes', 'axle-license-tracker' ) ),
			array( 'expired', (string) $totals['expired'], __( 'Expired', 'axle-license-tracker' ) ),
			/* translators: %d: number of days. */
			array( 'expiring', (string) $totals['expiring'], sprintf( _n( 'Renew within %d day', 'Renew within %d days', $window, 'axle-license-tracker' ), $window ) ),
			array( 'at-risk', (string) $totals['at_risk'], __( 'Not getting updates', 'axle-license-tracker' ) ),
			array( 'missing', (string) $totals['missing'], __( 'No license key', 'axle-license-tracker' ) ),
		);

		echo '<ul class="alc-tiles" aria-label="' . esc_attr__( 'Summary', 'axle-license-tracker' ) . '">';
		foreach ( $tiles as $tile ) {
			printf( '<li class="alc-tile alc-tile-%1$s"><span class="alc-tile-number">%2$s</span> <span class="alc-tile-label">%3$s</span></li>', esc_attr( $tile[0] ), esc_html( $tile[1] ), esc_html( $tile[2] ) );
		}
		printf(
			'<li class="alc-tile alc-tile-cost"><span class="alc-tile-number">%1$s</span> <span class="alc-tile-label">%2$s</span></li>',
			$costs ? implode( '<br>', $costs ) : '0', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display::cost() escapes.
			esc_html__( 'Yearly cost, per currency', 'axle-license-tracker' )
		);
		echo '</ul>';
	}

	/**
	 * The filter form.
	 *
	 * @param array<string, array> $rows    Rows the filters choose from.
	 * @param array                $filters Current filters.
	 * @param string               $view    premium or all.
	 */
	private function render_filters( array $rows, array $filters, string $view ): void {
		$options = array(
			'state'  => array(
				'label'  => __( 'License state', 'axle-license-tracker' ),
				'all'    => __( 'All states', 'axle-license-tracker' ),
				'values' => array(),
			),
			'risk'   => array(
				'label'  => __( 'Update risk', 'axle-license-tracker' ),
				'all'    => __( 'All risks', 'axle-license-tracker' ),
				'values' => array(),
			),
			'vendor' => array(
				'label'  => __( 'Vendor', 'axle-license-tracker' ),
				'all'    => __( 'All vendors', 'axle-license-tracker' ),
				'values' => array(),
			),
			'owner'  => array(
				'label'  => __( 'Owner', 'axle-license-tracker' ),
				'all'    => __( 'All owners', 'axle-license-tracker' ),
				'values' => array(),
			),
		);
		foreach ( Tracker::STATES as $state ) {
			$options['state']['values'][ $state ] = Messages::get( 'state_' . $state );
		}
		foreach ( array( 'ok', 'watch', 'at-risk', 'blocked' ) as $risk ) {
			$options['risk']['values'][ $risk ] = Messages::get( 'risk_' . $risk );
		}
		foreach ( $rows as $row ) {
			if ( '' !== $row['vendor'] ) {
				$options['vendor']['values'][ $row['vendor'] ] = $row['vendor'];
			}
			if ( '' !== $row['owner'] ) {
				$options['owner']['values'][ $row['owner'] ] = $row['owner'];
			}
		}

		echo '<form method="get" class="alc-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		if ( 'all' === $view ) {
			echo '<input type="hidden" name="view" value="all">';
		}
		foreach ( $options as $key => $option ) {
			asort( $option['values'] );
			printf( '<label for="alc-filter-%1$s" class="screen-reader-text">%2$s</label><select id="alc-filter-%1$s" name="%1$s"><option value="">%3$s</option>', esc_attr( $key ), esc_html( $option['label'] ), esc_html( $option['all'] ) );
			foreach ( $option['values'] as $value => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( $filters[ $key ], (string) $value, false ), esc_html( (string) $label ) );
			}
			echo '</select> ';
		}
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'axle-license-tracker' ) . '</button></form>';
	}

	/**
	 * One table row.
	 *
	 * @param array $row Row.
	 */
	private function render_row( array $row ): void {
		$recheck = wp_nonce_url( add_query_arg( 'action', 'axle_license_tracker_recheck', admin_url( 'admin-post.php' ) ), 'axle_license_tracker_recheck' );
		$actions = array(
			'edit'    => array( __( 'Edit details', 'axle-license-tracker' ), self::url( array( 'edit' => $row['id'] ) ) ),
			'recheck' => array( __( 'Check again', 'axle-license-tracker' ), $recheck ),
			'ignore'  => array( $row['ignored'] ? __( 'Stop ignoring', 'axle-license-tracker' ) : __( 'Ignore', 'axle-license-tracker' ), self::url( array( 'edit' => $row['id'] ) ) . '#alc-ignore' ),
		);
		if ( '' !== $row['license_url'] ) {
			$actions['vendor'] = array( __( 'Open license page', 'axle-license-tracker' ), $row['license_url'] );
		}

		printf(
			'<tr class="alc-row%1$s" data-component="%2$s" data-state="%3$s" data-risk="%4$s" data-vendor="%5$s">',
			$row['ignored'] ? ' alc-ignored' : '',
			esc_attr( $row['id'] ),
			esc_attr( $row['state'] ),
			esc_attr( $row['risk'] ),
			esc_attr( $row['vendor'] )
		);

		// Component.
		echo '<td class="alc-col-name"><strong>' . esc_html( $row['name'] ) . '</strong> <span class="alc-muted">' . esc_html( $row['version'] ) . '</span>';
		if ( $row['ignored'] ) {
			echo ' <span class="alc-tag">' . esc_html__( 'Ignored', 'axle-license-tracker' ) . '</span>';
		}
		echo '<div class="row-actions alc-row-actions">';
		$links = array();
		foreach ( $actions as $key => $action ) {
			$links[] = sprintf(
				'<span class="%1$s"><a href="%2$s" data-action="%1$s"%4$s>%3$s<span class="screen-reader-text"> %5$s</span></a></span>',
				esc_attr( $key ),
				esc_url( $action[1] ),
				esc_html( $action[0] ),
				'vendor' === $key && 0 !== strpos( $action[1], admin_url() ) ? ' target="_blank" rel="noopener noreferrer"' : '',
				/* translators: %s: plugin or theme name. */
				esc_html( sprintf( __( 'for %s', 'axle-license-tracker' ), $row['name'] ) )
			);
		}
		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo '</div></td>';

		// Type.
		$type = esc_html( Messages::get( 'type_' . $row['type'] ) ) . ' <span class="alc-muted">' . esc_html( 'theme' === $row['kind'] ? __( 'theme', 'axle-license-tracker' ) : __( 'plugin', 'axle-license-tracker' ) ) . '</span>';
		if ( 'bundled' === $row['type'] ) {
			/* translators: %s: theme name. */
			$type .= '<br><span class="alc-muted">' . esc_html( sprintf( __( 'with %s', 'axle-license-tracker' ), $row['bundled_by'] ) ) . '</span>';
		}
		echo '<td>' . $type . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		// Vendor.
		echo '<td>' . esc_html( $row['vendor'] ) . Display::source( $row, 'vendor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.
		if ( '' !== $row['sdk'] && $row['sdk'] !== $row['vendor'] ) {
			echo '<br><span class="alc-muted">' . esc_html( $row['sdk'] ) . '</span>';
		}
		if ( $row['purchase_flag'] ) {
			echo '<br><span class="alc-warn">' . esc_html( Messages::get( 'gpl_club' ) ) . '</span>';
		}
		echo '</td>';

		// License.
		echo '<td>' . Display::state( $row['state'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.
		if ( 'not-applicable' !== $row['state'] ) {
			echo '<br><span class="alc-muted alc-age">' . esc_html( Display::age( $row ) ) . '</span>';
		}
		if ( '' !== $row['expires'] ) {
			/* translators: %s: date or "lifetime". */
			echo '<br><span class="alc-muted">' . esc_html( sprintf( __( 'Expires: %s', 'axle-license-tracker' ), 'lifetime' === $row['expires'] ? __( 'never (lifetime)', 'axle-license-tracker' ) : $row['expires'] ) ) . '</span>';
		}
		if ( '' !== $row['state_note'] ) {
			echo '<br><span class="alc-muted">' . esc_html( $row['state_note'] ) . '</span>';
		}
		if ( '' !== $row['fingerprint'] ) {
			/* translators: %s: key fingerprint. */
			echo '<br><span class="alc-muted alc-fp">' . esc_html( sprintf( __( 'Key: %s', 'axle-license-tracker' ), Display::fingerprint( $row['fingerprint'] ) ) ) . '</span>';
		}
		echo '</td>';

		// Updates.
		echo '<td>' . Display::risk( $row['risk'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.
		foreach ( $row['risk_signals'] as $signal ) {
			echo '<br><span class="alc-muted">' . esc_html( $signal ) . '</span>';
		}
		echo '</td>';

		// Renews.
		echo '<td>';
		if ( '' !== $row['renewal'] ) {
			echo esc_html( $row['renewal'] ) . Display::source( $row, 'renewal' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.
			if ( null !== $row['days_left'] && $row['days_left'] >= 0 ) {
				/* translators: %d: number of days. */
				echo '<br><span class="alc-muted">' . esc_html( sprintf( _n( 'in %d day', 'in %d days', (int) $row['days_left'], 'axle-license-tracker' ), (int) $row['days_left'] ) ) . '</span>';
			}
		} elseif ( 'lifetime' === $row['cycle'] ) {
			echo esc_html__( 'Lifetime', 'axle-license-tracker' );
		}
		if ( '' !== $row['auto_renew'] ) {
			echo '<br><span class="alc-muted">' . esc_html( 'yes' === $row['auto_renew'] ? __( 'Auto-renews', 'axle-license-tracker' ) : __( 'Manual renewal', 'axle-license-tracker' ) ) . '</span>';
		}
		echo '</td>';

		// Cost.
		$cycles = self::cycles();
		echo '<td>' . Display::cost( $row['cost'], $row['currency'], '' !== $row['cycle'] ? '/ ' . ( $cycles[ $row['cycle'] ] ?? $row['cycle'] ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.

		// Owner.
		echo '<td>' . esc_html( $row['owner'] );
		if ( $row['seats'] && $row['seats_used'] ) {
			/* translators: 1: seats in use, 2: seats bought. */
			echo '<br><span class="alc-muted">' . esc_html( sprintf( __( 'Seats: %1$d of %2$d used', 'axle-license-tracker' ), $row['seats_used'], $row['seats'] ) ) . '</span>';
		} elseif ( $row['seats'] ) {
			/* translators: %d: seats bought. */
			echo '<br><span class="alc-muted">' . esc_html( sprintf( _n( '%d seat', '%d seats', $row['seats'], 'axle-license-tracker' ), $row['seats'] ) ) . '</span>';
		}
		echo '</td></tr>';
	}

	/**
	 * Export links.
	 */
	private function render_exports(): void {
		$base = add_query_arg( 'action', 'axle_license_tracker_export', admin_url( 'admin-post.php' ) );
		printf(
			'<p class="alc-exports">%1$s <a href="%2$s">CSV</a> | <a href="%3$s">JSON</a> <span class="alc-muted">%4$s</span></p>',
			esc_html__( 'Export:', 'axle-license-tracker' ),
			esc_url( wp_nonce_url( add_query_arg( 'format', 'csv', $base ), 'axle_license_tracker_export' ) ),
			esc_url( wp_nonce_url( add_query_arg( 'format', 'json', $base ), 'axle_license_tracker_export' ) ),
			esc_html__( 'License keys are never included, only fingerprints.', 'axle-license-tracker' )
		);
	}

	/**
	 * Billing cycle labels.
	 *
	 * @return array<string, string>
	 */
	public static function cycles(): array {
		return array(
			'yearly'   => __( 'year', 'axle-license-tracker' ),
			'monthly'  => __( 'month', 'axle-license-tracker' ),
			'lifetime' => __( 'lifetime', 'axle-license-tracker' ),
			'one-off'  => __( 'one-off', 'axle-license-tracker' ),
		);
	}

	/**
	 * The edit details panel.
	 *
	 * @param string $id Component id.
	 */
	private function render_edit( string $id ): void {
		$row = $this->tracker->row( $id );
		echo '<h1>' . esc_html__( 'Edit license details', 'axle-license-tracker' ) . '</h1>';
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to Licenses', 'axle-license-tracker' ) . '</a></p>';
		if ( ! $row ) {
			echo '<div class="notice notice-error"><p>' . esc_html( Messages::get( 'not_found' ) ) . '</p></div>';
			return;
		}
		$this->render_notice();

		$user    = get_current_user_id();
		$pending = get_transient( 'axle_license_tracker_form_' . $user );
		delete_transient( 'axle_license_tracker_form_' . $user );
		$errors = is_array( $pending ) && ( $pending['component'] ?? '' ) === $id ? (array) $pending['errors'] : array();
		$input  = is_array( $pending ) && ( $pending['component'] ?? '' ) === $id ? (array) $pending['input'] : array();

		echo '<h2>' . esc_html( $row['name'] ) . ' <span class="alc-muted">' . esc_html( $row['version'] ) . '</span></h2>';

		// What detection found.
		echo '<dl class="alc-detected">';
		$facts = array(
			__( 'Type', 'axle-license-tracker' )    => Messages::get( 'type_' . $row['type'] ),
			__( 'Vendor', 'axle-license-tracker' )  => '' !== $row['vendor'] ? $row['vendor'] : __( 'Not detected', 'axle-license-tracker' ),
			__( 'Updater', 'axle-license-tracker' ) => '' !== $row['sdk'] ? $row['sdk'] : ( '' !== $row['source'] ? $row['source'] : __( 'None found', 'axle-license-tracker' ) ),
			__( 'License', 'axle-license-tracker' ) => Messages::get( 'state_' . $row['state'] ) . ( 'not-applicable' !== $row['state'] ? ', ' . Display::age( $row ) : '' ),
			__( 'Expires', 'axle-license-tracker' ) => '' !== $row['expires'] ? $row['expires'] : __( 'Not stored by the vendor', 'axle-license-tracker' ),
			__( 'Updates', 'axle-license-tracker' ) => '' !== $row['risk'] ? Messages::get( 'risk_' . $row['risk'] ) . ( $row['risk_signals'] ? ': ' . implode( ' ', $row['risk_signals'] ) : '' ) : __( 'Not tracked', 'axle-license-tracker' ),
		);
		if ( '' !== $row['fingerprint'] ) {
			$facts[ __( 'Key fingerprint', 'axle-license-tracker' ) ] = Display::fingerprint( $row['fingerprint'] );
		}
		foreach ( $facts as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl>';

		echo '<p>' . esc_html__( 'Details you enter here win over what was detected. Leave a field empty to use the detected value.', 'axle-license-tracker' ) . '</p>';

		$value = static function ( string $field ) use ( $row, $input ) {
			if ( array_key_exists( $field, $input ) ) {
				return (string) $input[ $field ];
			}
			// Fields detection can fill show only what was entered by hand.
			if ( in_array( $field, array( 'vendor', 'renewal', 'auto_renew', 'seats' ), true ) && 'manual' !== ( $row['sources'][ $field ] ?? '' ) ) {
				return '';
			}
			if ( 'cost' === $field ) {
				return null === $row['cost'] ? '' : Details::from_minor( (int) $row['cost'], $row['currency'] );
			}
			return is_scalar( $row[ $field ] ?? '' ) ? (string) ( $row[ $field ] ?? '' ) : '';
		};

		printf( '<form method="post" action="%s" class="alc-edit" novalidate>', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="axle_license_tracker_save"><input type="hidden" name="component" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'axle_license_tracker_save' );
		echo '<table class="form-table" role="presentation"><tbody>';

		$labels  = array(
			'vendor'          => __( 'Vendor', 'axle-license-tracker' ),
			'plan'            => __( 'Plan', 'axle-license-tracker' ),
			'purchase_source' => __( 'Bought from', 'axle-license-tracker' ),
			'owner'           => __( 'Owner', 'axle-license-tracker' ),
			'owner_email'     => __( 'Owner email', 'axle-license-tracker' ),
			'renewal'         => __( 'Renewal date', 'axle-license-tracker' ),
			'cycle'           => __( 'Billing cycle', 'axle-license-tracker' ),
			'cost'            => __( 'Cost per cycle', 'axle-license-tracker' ),
			'currency'        => __( 'Currency', 'axle-license-tracker' ),
			'auto_renew'      => __( 'Auto-renew', 'axle-license-tracker' ),
			'seats'           => __( 'Seats bought', 'axle-license-tracker' ),
			'account_url'     => __( 'License managed at', 'axle-license-tracker' ),
			'account_email'   => __( 'Account email', 'axle-license-tracker' ),
			'invoice_url'     => __( 'Invoice link', 'axle-license-tracker' ),
			'notes'           => __( 'Notes', 'axle-license-tracker' ),
		);
		$help    = array(
			'owner'       => __( 'The agency or the client, whoever pays for it.', 'axle-license-tracker' ),
			'owner_email' => __( 'Also gets the renewal reminders.', 'axle-license-tracker' ),
			'renewal'     => __( 'The date the card is charged or the license runs out.', 'axle-license-tracker' ),
			'cost'        => __( 'Such as 199.00.', 'axle-license-tracker' ),
			'currency'    => __( 'Three letters, such as USD or EUR. Totals are kept per currency, never converted.', 'axle-license-tracker' ),
		);
		$detects = array(
			'vendor'     => 'detected' === ( $row['sources']['vendor'] ?? '' ) ? $row['vendor'] : '',
			'renewal'    => 'detected' === ( $row['sources']['renewal'] ?? '' ) ? $row['renewal'] : '',
			'auto_renew' => 'detected' === ( $row['sources']['auto_renew'] ?? '' ) ? $row['auto_renew'] : '',
			'seats'      => 'detected' === ( $row['sources']['seats'] ?? '' ) ? (string) $row['seats'] : '',
		);

		foreach ( Details::fields() as $field => $kind ) {
			$name     = 'details[' . $field . ']';
			$field_id = 'alc-' . str_replace( '_', '-', $field );
			$describe = array();
			if ( isset( $help[ $field ] ) ) {
				$describe[] = $field_id . '-help';
			}
			if ( '' !== ( $detects[ $field ] ?? '' ) ) {
				$describe[] = $field_id . '-detected';
			}
			if ( isset( $errors[ $field ] ) ) {
				$describe[] = $field_id . '-error';
			}
			$aria = ( $describe ? ' aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"' : '' ) . ( isset( $errors[ $field ] ) ? ' aria-invalid="true"' : '' );

			echo '<tr><th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $labels[ $field ] ) . '</label></th><td>';
			$current = $value( $field );
			if ( in_array( $kind, array( 'source', 'cycle', 'yesno' ), true ) ) {
				$choices = array( '' => __( 'Not set', 'axle-license-tracker' ) );
				if ( 'source' === $kind ) {
					foreach ( Details::purchase_sources() as $key => $source ) {
						$choices[ $key ] = $source['label'];
					}
				} elseif ( 'cycle' === $kind ) {
					$choices += array(
						'yearly'   => __( 'Yearly', 'axle-license-tracker' ),
						'monthly'  => __( 'Monthly', 'axle-license-tracker' ),
						'lifetime' => __( 'Lifetime', 'axle-license-tracker' ),
						'one-off'  => __( 'One-off', 'axle-license-tracker' ),
					);
				} else {
					$choices += array(
						'yes' => __( 'Yes', 'axle-license-tracker' ),
						'no'  => __( 'No', 'axle-license-tracker' ),
					);
				}
				echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '"' . $aria . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				foreach ( $choices as $key => $label ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $key ), selected( $current, (string) $key, false ), esc_html( $label ) );
				}
				echo '</select>';
			} elseif ( 'textarea' === $kind ) {
				echo '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" rows="3" class="large-text"' . $aria . '>' . esc_textarea( $current ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			} else {
				$types = array(
					'email'    => 'email',
					'url'      => 'url',
					'date'     => 'date',
					'int'      => 'number',
					'money'    => 'text',
					'currency' => 'text',
				);
				$extra = 'int' === $kind ? ' min="0" step="1"' : ( 'money' === $kind ? ' inputmode="decimal"' : ( 'currency' === $kind ? ' maxlength="3" size="4"' : '' ) );
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="%5$s"%6$s%7$s>',
					esc_attr( $types[ $kind ] ?? 'text' ),
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( $current ),
					in_array( $kind, array( 'int', 'currency' ), true ) ? 'small-text' : ( in_array( $kind, array( 'money', 'date' ), true ) ? 'alc-short' : 'regular-text' ),
					$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed strings.
					$aria // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
			}
			if ( isset( $errors[ $field ] ) ) {
				echo '<p class="alc-error" id="' . esc_attr( $field_id . '-error' ) . '">' . esc_html( $errors[ $field ] ) . '</p>';
			}
			if ( '' !== ( $detects[ $field ] ?? '' ) ) {
				/* translators: %s: detected value. */
				echo '<p class="description" id="' . esc_attr( $field_id . '-detected' ) . '">' . esc_html( sprintf( __( 'Detected: %s', 'axle-license-tracker' ), $detects[ $field ] ) ) . '</p>';
			}
			if ( isset( $help[ $field ] ) ) {
				echo '<p class="description" id="' . esc_attr( $field_id . '-help' ) . '">' . esc_html( $help[ $field ] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save details', 'axle-license-tracker' ) );
		echo '</form>';

		// Ignore.
		echo '<h2 id="alc-ignore">' . esc_html__( 'Ignore', 'axle-license-tracker' ) . '</h2>';
		printf( '<form method="post" action="%s" class="alc-ignore-form">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="axle_license_tracker_ignore"><input type="hidden" name="component" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'axle_license_tracker_ignore' );
		if ( $row['ignored'] ) {
			echo '<p>' . esc_html__( 'This component is ignored: no reminders, not in the totals.', 'axle-license-tracker' ) . '</p>';
			if ( '' !== $row['ignore_note'] ) {
				/* translators: %s: note. */
				echo '<p>' . esc_html( sprintf( __( 'Note: %s', 'axle-license-tracker' ), $row['ignore_note'] ) ) . '</p>';
			}
			echo '<input type="hidden" name="ignore" value="0">';
			submit_button( __( 'Stop ignoring', 'axle-license-tracker' ), 'secondary', 'alc-ignore-submit', false );
		} else {
			echo '<p>' . esc_html__( 'Ignore a component that needs no license, such as one you no longer pay for on purpose. It stays in the list but gets no reminders and does not count in the totals.', 'axle-license-tracker' ) . '</p>';
			echo '<p><label for="alc-ignore-note">' . esc_html__( 'Why (optional)', 'axle-license-tracker' ) . '</label><br><textarea id="alc-ignore-note" name="note" rows="2" class="large-text"></textarea></p>';
			echo '<input type="hidden" name="ignore" value="1">';
			submit_button( __( 'Ignore this component', 'axle-license-tracker' ), 'secondary', 'alc-ignore-submit', false );
		}
		echo '</form>';
	}

	/**
	 * The reminders tab.
	 */
	private function render_settings(): void {
		$settings = $this->settings->all();
		printf( '<form method="post" action="%s" class="alc-settings">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="axle_license_tracker_settings">';
		wp_nonce_field( 'axle_license_tracker_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="alc-reminder-days">%1$s</label></th><td><input type="text" id="alc-reminder-days" name="reminder_days" value="%2$s" class="regular-text" aria-describedby="alc-reminder-days-help"><p class="description" id="alc-reminder-days-help">%3$s</p></td></tr>',
			esc_html__( 'Remind before renewal', 'axle-license-tracker' ),
			esc_attr( implode( ', ', $settings['reminder_days'] ) ),
			esc_html__( 'Days before a renewal date when a reminder goes out, separated by commas. Each one is sent once.', 'axle-license-tracker' )
		);
		printf(
			'<tr><th scope="row"><label for="alc-recipients">%1$s</label></th><td><input type="text" id="alc-recipients" name="recipients" value="%2$s" class="regular-text" aria-describedby="alc-recipients-help"><p class="description" id="alc-recipients-help">%3$s</p></td></tr>',
			esc_html__( 'Also send to', 'axle-license-tracker' ),
			esc_attr( implode( ', ', $settings['recipients'] ) ),
			/* translators: %s: admin email address. */
			esc_html( sprintf( __( 'Email addresses separated by commas. The site admin (%s) and each license\'s owner email always get them.', 'axle-license-tracker' ), (string) get_option( 'admin_email' ) ) )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Emails', 'axle-license-tracker' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Emails', 'axle-license-tracker' ) . '</legend>';
		printf( '<label for="alc-alerts"><input type="checkbox" id="alc-alerts" name="alerts" value="1"%1$s> %2$s</label><br>', checked( $settings['alerts'], true, false ), esc_html__( 'Email when a license expires or a plugin stops getting updates', 'axle-license-tracker' ) );
		printf( '<label for="alc-digest"><input type="checkbox" id="alc-digest" name="digest" value="1"%1$s> %2$s</label>', checked( $settings['digest'], true, false ), esc_html__( 'Send a monthly digest of every license', 'axle-license-tracker' ) );
		echo '</fieldset></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'axle-license-tracker' ) );
		echo '</form>';
	}

	/**
	 * Checks the capability and nonce of a request, or stops.
	 *
	 * @param string $action Nonce action.
	 */
	private function verify( string $action ): void {
		if ( ! Capability::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change licenses.', 'axle-license-tracker' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects back to the screen with a notice.
	 *
	 * @param string $notice Notice key.
	 * @param array  $args   Extra query arguments.
	 */
	private function back( string $notice, array $args = array() ): void {
		wp_safe_redirect( self::url( array_merge( $args, array( 'alc_notice' => $notice ) ) ) );
		exit;
	}

	/**
	 * The component id from the request.
	 *
	 * @return string
	 */
	private static function component(): string {
		// phpcs:ignore WordPress.Security.NonceVerification -- Checked in verify() before this runs.
		return isset( $_REQUEST['component'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['component'] ) ) : '';
	}

	/**
	 * Handles Check again.
	 */
	public function handle_recheck(): void {
		$this->verify( 'axle_license_tracker_recheck' );
		$this->tracker->recheck();
		$this->back( 'rechecked' );
	}

	/**
	 * Handles Save details.
	 */
	public function handle_save(): void {
		$this->verify( 'axle_license_tracker_save' );
		$id  = self::component();
		$row = $this->tracker->row( $id );
		if ( ! $row ) {
			$this->back( 'not_found' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in verify(); each field is validated in Details::validate().
		$input = isset( $_POST['details'] ) && is_array( $_POST['details'] ) ? map_deep( wp_unslash( $_POST['details'] ), 'sanitize_textarea_field' ) : array();
		$input = array_intersect_key( $input, Details::fields() );

		$errors = $this->details->save( $row, $input, get_current_user_id() );
		if ( $errors ) {
			set_transient(
				'axle_license_tracker_form_' . get_current_user_id(),
				array(
					'component' => $id,
					'errors'    => $errors,
					'input'     => $input,
				),
				5 * MINUTE_IN_SECONDS
			);
			$this->back( 'invalid', array( 'edit' => $id ) );
			return;
		}

		$this->tracker->flush();
		$this->tracker->sync();
		$this->back( 'saved', array( 'edit' => $id ) );
	}

	/**
	 * Handles Ignore and Stop ignoring.
	 */
	public function handle_ignore(): void {
		$this->verify( 'axle_license_tracker_ignore' );
		$id  = self::component();
		$row = $this->tracker->row( $id );
		if ( ! $row ) {
			$this->back( 'not_found' );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$ignore = ! empty( $_POST['ignore'] );
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->tracker->ignore( $id, $ignore, $note, get_current_user_id() );
		$this->back( $ignore ? 'ignored' : 'unignored' );
	}

	/**
	 * Handles the settings form.
	 */
	public function handle_settings(): void {
		$this->verify( 'axle_license_tracker_settings' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$this->settings->update(
			array(
				'reminder_days' => isset( $_POST['reminder_days'] ) ? sanitize_text_field( wp_unslash( $_POST['reminder_days'] ) ) : '',
				'recipients'    => isset( $_POST['recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['recipients'] ) ) : '',
				'alerts'        => ! empty( $_POST['alerts'] ),
				'digest'        => ! empty( $_POST['digest'] ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->back( 'settings', array( 'tab' => 'settings' ) );
	}

	/**
	 * Handles an export download.
	 */
	public function handle_export(): void {
		$this->verify( 'axle_license_tracker_export' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in verify().
		$format = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv';

		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . Export::filename( $format ) . '"' );
		echo $this->export->render( $format ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file download, not HTML.
		exit;
	}
}
