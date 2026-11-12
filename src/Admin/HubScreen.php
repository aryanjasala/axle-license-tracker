<?php
/**
 * Plugins > Licenses > Sites: every client site's licenses on one screen.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Admin;

use AryanX\Axle\LicenseTracker\Hub\Hub;
use AryanX\Axle\LicenseTracker\Hub\Overview;
use AryanX\Axle\LicenseTracker\Messages;
use AryanX\Axle\LicenseTracker\Service;
use AryanX\Axle\LicenseTracker\Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * The hub tab and its handlers. Every handler checks the capability and a
 * nonce. Nothing here ever changes a remote site.
 */
class HubScreen implements Service {

	private const PER_PAGE = 50;

	/**
	 * The hub.
	 *
	 * @var Hub
	 */
	private Hub $hub;

	/**
	 * Sets up the tab.
	 *
	 * @param Hub $hub The hub.
	 */
	public function __construct( Hub $hub ) {
		$this->hub = $hub;
	}

	/**
	 * Adds the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_axle_license_tracker_hub_toggle', array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_axle_license_tracker_hub_add', array( $this, 'handle_add' ) );
		add_action( 'admin_post_axle_license_tracker_hub_remove', array( $this, 'handle_remove' ) );
		add_action( 'admin_post_axle_license_tracker_hub_sync', array( $this, 'handle_sync' ) );
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		if ( ! $this->hub->enabled() ) {
			$this->render_off();
			return;
		}

		$sources = $this->hub->sources();
		$rows    = $this->hub->rows();
		$dups    = Overview::duplicates( $rows );

		echo '<p class="axle-intro">' . esc_html__( 'Licenses, renewals and costs of every site you look after, read from each site once a day. The hub only reads: it never changes anything on those sites.', 'axle-license-tracker' ) . '</p>';
		$this->render_stats( $rows, $sources, $dups );
		$this->render_sites( $sources );
		$this->render_licenses( $rows, $sources, $dups );
		if ( $dups ) {
			$this->render_duplicates( $dups );
		}
		$this->render_costs( $rows );
		$this->render_add();
	}

	/**
	 * What the tab shows while the hub is off.
	 */
	private function render_off(): void {
		echo '<p class="axle-intro">' . esc_html__( 'See the licenses of all the sites you look after on one screen, so a lapsed license does not surprise you when a client asks why updates stopped.', 'axle-license-tracker' ) . '</p>';
		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'The hub is off', 'axle-license-tracker' ) . '</h2>';
		echo '<div class="axle-empty alc-empty"><p>' . esc_html__( 'Turn it on, then add each site with the address and an application password of a user who has the License reporter role there. This site is shown next to them. Passwords are stored encrypted and never shown again.', 'axle-license-tracker' ) . '</p>';
		printf( '<form method="post" action="%s"><input type="hidden" name="action" value="axle_license_tracker_hub_toggle"><input type="hidden" name="enable" value="1">', Parts::post_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_url().
		Parts::nonce( 'axle_license_tracker_hub_toggle' );
		printf( '<button type="submit" class="button button-primary">%s</button></form></div></section>', esc_html__( 'Turn on the hub', 'axle-license-tracker' ) );
	}

	/**
	 * The numbers at the top.
	 *
	 * @param array[] $rows    Combined rows.
	 * @param array[] $sources Remote sites.
	 * @param array[] $dups    Duplicate keys.
	 */
	private function render_stats( array $rows, array $sources, array $dups ): void {
		$unreachable = count(
			array_filter(
				$sources,
				static function ( array $source ): bool {
					return $source['fails'] > 0;
				}
			)
		);
		$soon        = count( Overview::expiring( $rows, 30 ) );
		$costs       = Overview::costs( $rows );
		$over        = count(
			array_filter(
				$dups,
				static function ( array $dup ): bool {
					return $dup['over'];
				}
			)
		);
		$stats       = array(
			array( 'sites', (string) ( count( $sources ) + 1 ), __( 'sites, this one included', 'axle-license-tracker' ), '' ),
			array( 'unreachable', (string) $unreachable, __( 'could not be reached', 'axle-license-tracker' ), $unreachable ? 'error' : '' ),
			array( 'soon', (string) $soon, __( 'expired or renewing in 30 days', 'axle-license-tracker' ), $soon ? 'warning' : '' ),
			array( 'shared', (string) count( $dups ), $over ? __( 'keys on several sites, some over their limit', 'axle-license-tracker' ) : __( 'keys on several sites', 'axle-license-tracker' ), $over ? 'error' : '' ),
		);

		echo '<div class="axle-stats" role="group" aria-label="' . esc_attr__( 'Summary of all sites', 'axle-license-tracker' ) . '">';
		foreach ( $stats as $stat ) {
			printf(
				'<div class="axle-stat%4$s alc-tile-%1$s"><span class="axle-stat__value">%2$s</span><span class="axle-stat__label">%3$s</span></div>',
				esc_attr( $stat[0] ),
				esc_html( $stat[1] ),
				esc_html( $stat[2] ),
				'' !== $stat[3] ? ' axle-stat--' . esc_attr( $stat[3] ) : ''
			);
		}
		printf(
			'<div class="axle-stat alc-tile-cost"><span class="axle-stat__value">%1$s</span><span class="axle-stat__label">%2$s</span></div>',
			Parts::money_lines( $costs['currencies'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in money_lines().
			esc_html__( 'a year, per currency', 'axle-license-tracker' )
		);
		echo '</div>';
	}

	/**
	 * The remote sites and whether each could be reached.
	 *
	 * @param array[] $sources Remote sites.
	 */
	private function render_sites( array $sources ): void {
		echo '<section class="axle-card alc-sites"><h2 class="axle-card__title">' . esc_html__( 'Sites', 'axle-license-tracker' ) . '</h2>';
		if ( ! $sources ) {
			echo '<div class="axle-empty alc-empty"><p>' . esc_html__( 'No other sites yet. Add the first one below: its address, and the user name and application password of a user with the License reporter role on that site.', 'axle-license-tracker' ) . '</p></div></section>';
			return;
		}

		echo '<div class="axle-toolbar"><span class="axle-toolbar__end">';
		printf( '<form method="post" action="%s" class="alc-busy-form" data-alc-busy="%s"><input type="hidden" name="action" value="axle_license_tracker_hub_sync"><input type="hidden" name="all" value="1">', Parts::post_url(), esc_attr__( 'Fetching the sites. This can take a minute.', 'axle-license-tracker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_url().
		Parts::nonce( 'axle_license_tracker_hub_sync' );
		printf( '<button type="submit" class="button">%s</button><span class="spinner alc-spinner"></span></form>', esc_html__( 'Sync all sites now', 'axle-license-tracker' ) );
		echo '</span></div>';

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Remote sites', 'axle-license-tracker' ) . '"><table class="widefat striped alc-sites-table"><caption class="screen-reader-text">' . esc_html__( 'Sites in the hub and whether they could be reached', 'axle-license-tracker' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Site', 'axle-license-tracker' ), __( 'Status', 'axle-license-tracker' ), __( 'Licenses', 'axle-license-tracker' ), __( 'Last checked', 'axle-license-tracker' ), __( 'Next check', 'axle-license-tracker' ), __( 'Actions', 'axle-license-tracker' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $sources as $id => $source ) {
			if ( $source['fails'] > 0 ) {
				$badge = '<span class="axle-badge axle-badge--error">' . esc_html__( 'Could not be reached', 'axle-license-tracker' ) . '</span>';
			} elseif ( $source['ok'] > 0 ) {
				$badge = '<span class="axle-badge axle-badge--good">' . esc_html__( 'Reached', 'axle-license-tracker' ) . '</span>';
			} else {
				$badge = '<span class="axle-badge axle-badge--info">' . esc_html__( 'Waiting for the first check', 'axle-license-tracker' ) . '</span>';
			}

			printf( '<tr data-site="%s"><td><strong>%s</strong><br><span class="alc-muted">%s</span></td><td>%s', esc_attr( $id ), esc_html( $source['label'] ), esc_html( $source['url'] ), $badge ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			if ( '' !== $source['error'] ) {
				echo '<p class="alc-error">' . esc_html( $source['error'] ) . '</p>';
			}
			echo '</td><td>' . esc_html( (string) count( $source['components'] ) ) . '</td>';
			echo '<td>' . AxleUi::relative_time( (int) $source['tried'], __( 'Not yet', 'axle-license-tracker' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- relative_time() escapes.
			$next = (int) ( $this->hub->sites()->get( $id )['next'] ?? 0 );
			echo '<td>' . AxleUi::relative_time( $next, __( 'On the next run', 'axle-license-tracker' ) ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- relative_time() escapes.
			printf( '<form method="post" action="%s" class="alc-inline-form alc-busy-form" data-alc-busy="%s"><input type="hidden" name="action" value="axle_license_tracker_hub_sync"><input type="hidden" name="site" value="%s">', Parts::post_url(), esc_attr__( 'Fetching the site.', 'axle-license-tracker' ), esc_attr( $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_url().
			Parts::nonce( 'axle_license_tracker_hub_sync' );
			/* translators: %s: site name. */
			printf( '<button type="submit" class="button-link">%1$s<span class="screen-reader-text"> %2$s</span></button><span class="spinner alc-spinner"></span></form> | ', esc_html__( 'Check now', 'axle-license-tracker' ), esc_html( sprintf( __( 'for %s', 'axle-license-tracker' ), $source['label'] ) ) );
			Parts::confirm(
				'alc-remove-' . $id,
				__( 'Remove', 'axle-license-tracker' ),
				/* translators: %s: site name. */
				sprintf( __( 'Remove %s from the hub?', 'axle-license-tracker' ), $source['label'] ),
				__( 'Its saved password and every license it reported are deleted from this site. The site itself is not touched. This cannot be undone; you can add the site again later.', 'axle-license-tracker' ),
				'axle_license_tracker_hub_remove',
				array( 'site' => $id ),
				/* translators: %s: site name. */
				sprintf( __( 'Remove %s', 'axle-license-tracker' ), $source['label'] )
			);
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * The filters, and the table of licenses across sites.
	 *
	 * @param array[] $rows    Combined rows.
	 * @param array[] $sources Remote sites.
	 * @param array[] $dups    Duplicate keys.
	 */
	private function render_licenses( array $rows, array $sources, array $dups ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters for a read-only view.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$site   = isset( $_GET['site'] ) ? sanitize_text_field( wp_unslash( $_GET['site'] ) ) : '';
		$state  = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$soon   = isset( $_GET['soon'] ) && '1' === $_GET['soon'];
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$by_fp = array();
		foreach ( $dups as $dup ) {
			$by_fp[ $dup['fingerprint'] ] = $dup;
		}

		$list = $soon ? Overview::expiring( $rows, 30 ) : $rows;
		$list = array_values(
			array_filter(
				$list,
				static function ( array $row ) use ( $search, $site, $state ): bool {
					if ( '' !== $site && $row['site'] !== $site ) {
						return false;
					}
					if ( '' !== $state && $row['state'] !== $state ) {
						return false;
					}
					return '' === $search || false !== stripos( $row['name'] . ' ' . $row['vendor'] . ' ' . $row['site_label'], $search );
				}
			)
		);
		if ( ! $soon ) {
			usort(
				$list,
				static function ( array $a, array $b ): int {
					return strcasecmp( $a['site_label'] . $a['name'], $b['site_label'] . $b['name'] );
				}
			);
		}

		echo '<section class="axle-card alc-hub-licenses"><h2 class="axle-card__title">' . esc_html__( 'Licenses on all sites', 'axle-license-tracker' ) . '</h2>';
		echo '<form method="get" class="axle-toolbar alc-hub-filters"><input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '"><input type="hidden" name="tab" value="sites">';
		printf( '<label for="alc-hub-search" class="screen-reader-text">%s</label><input type="search" id="alc-hub-search" name="s" value="%s" placeholder="%s">', esc_html__( 'Search licenses', 'axle-license-tracker' ), esc_attr( $search ), esc_attr__( 'Search product, vendor or site', 'axle-license-tracker' ) );
		printf( '<label for="alc-hub-site" class="screen-reader-text">%s</label><select id="alc-hub-site" name="site"><option value="">%s</option><option value="%s"%s>%s</option>', esc_html__( 'Site', 'axle-license-tracker' ), esc_html__( 'All sites', 'axle-license-tracker' ), esc_attr( Overview::LOCAL ), selected( $site, Overview::LOCAL, false ), esc_html__( 'This site', 'axle-license-tracker' ) );
		foreach ( $sources as $id => $source ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $site, $id, false ), esc_html( $source['label'] ) );
		}
		echo '</select>';
		printf( '<label for="alc-hub-state" class="screen-reader-text">%s</label><select id="alc-hub-state" name="state"><option value="">%s</option>', esc_html__( 'License state', 'axle-license-tracker' ), esc_html__( 'All states', 'axle-license-tracker' ) );
		foreach ( Tracker::STATES as $value ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $state, $value, false ), esc_html( Messages::get( 'state_' . $value ) ) );
		}
		echo '</select>';
		printf( '<label for="alc-hub-soon"><input type="checkbox" id="alc-hub-soon" name="soon" value="1"%s> %s</label>', checked( $soon, true, false ), esc_html__( 'Expired or renewing in 30 days', 'axle-license-tracker' ) );
		printf( '<span class="axle-toolbar__end"><button type="submit" class="button">%s</button></span></form>', esc_html__( 'Filter', 'axle-license-tracker' ) );

		if ( ! $list ) {
			echo '<div class="axle-empty alc-empty"><p>' . esc_html( $rows ? __( 'Nothing matches these filters. Clear them to see every license.', 'axle-license-tracker' ) : __( 'No licenses yet. Sites report their premium plugins and themes after the first check.', 'axle-license-tracker' ) ) . '</p></div></section>';
			return;
		}

		$total = count( $list );
		$pages = (int) ceil( $total / self::PER_PAGE );
		$paged = min( $paged, $pages );
		$slice = array_slice( $list, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Licenses on all sites', 'axle-license-tracker' ) . '"><table class="widefat striped alc-hub-table"><caption class="screen-reader-text">' . esc_html__( 'Licenses across all sites', 'axle-license-tracker' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Site', 'axle-license-tracker' ), __( 'Plugin or theme', 'axle-license-tracker' ), __( 'Vendor', 'axle-license-tracker' ), __( 'License', 'axle-license-tracker' ), __( 'Renews', 'axle-license-tracker' ), __( 'Cost', 'axle-license-tracker' ), __( 'Same key elsewhere', 'axle-license-tracker' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $slice as $row ) {
			echo '<tr data-site="' . esc_attr( $row['site'] ) . '"><td>' . esc_html( $row['site_label'] ) . '</td>';
			echo '<td><strong>' . esc_html( $row['name'] ) . '</strong> <span class="alc-muted">' . esc_html( $row['version'] ) . '</span></td>';
			echo '<td>' . esc_html( $row['vendor'] ) . '</td>';
			echo '<td>' . Display::state( $row['state'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display escapes.
			echo '<td>' . Parts::date( $row['renewal'], '' !== $row['cycle'] && 'lifetime' === $row['cycle'] ? __( 'Lifetime', 'axle-license-tracker' ) : __( 'No date', 'axle-license-tracker' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date().
			echo '<td>' . Parts::cost_text( $row['cost'], $row['currency'], $row['cycle'] ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cost_text().
			$dup = $by_fp[ $row['fingerprint'] ] ?? null;
			if ( $dup && $dup['over'] ) {
				/* translators: 1: number of sites, 2: sites the plan allows. */
				echo '<span class="axle-badge axle-badge--error">' . esc_html( sprintf( __( 'On %1$d sites, plan allows %2$d', 'axle-license-tracker' ), $dup['count'], $dup['limit'] ) ) . '</span>';
			} elseif ( $dup ) {
				/* translators: %d: number of sites. */
				echo '<span class="axle-badge axle-badge--info">' . esc_html( sprintf( __( 'On %d sites', 'axle-license-tracker' ), $dup['count'] ) ) . '</span>';
			} else {
				echo '<span class="axle-muted">' . esc_html__( 'No', 'axle-license-tracker' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';

		if ( $pages > 1 ) {
			$links = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => __( 'Previous', 'axle-license-tracker' ),
					'next_text' => __( 'Next', 'axle-license-tracker' ),
				)
			);
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) $links ) . '</div></div>';
		}
		echo '</section>';
	}

	/**
	 * Keys used on more than one site.
	 *
	 * @param array[] $dups Duplicate keys.
	 */
	private function render_duplicates( array $dups ): void {
		echo '<section class="axle-card alc-hub-dups"><h2 class="axle-card__title">' . esc_html__( 'The same license on several sites', 'axle-license-tracker' ) . '</h2>';
		echo '<p>' . esc_html__( 'A key is matched by its fingerprint, never by the key itself. When a plugin reports how many sites its plan allows, a key above that number is marked in red.', 'axle-license-tracker' ) . '</p>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Keys used on several sites', 'axle-license-tracker' ) . '"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Keys used on more than one site', 'axle-license-tracker' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Key', 'axle-license-tracker' ), __( 'Products', 'axle-license-tracker' ), __( 'Sites', 'axle-license-tracker' ), __( 'Plan allows', 'axle-license-tracker' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $dups as $dup ) {
			echo '<tr><td><code>' . esc_html( Display::fingerprint( $dup['fingerprint'] ) ) . '</code></td><td>' . esc_html( implode( ', ', $dup['names'] ) ) . '</td>';
			/* translators: 1: number of sites, 2: site names. */
			echo '<td>' . esc_html( sprintf( __( '%1$d: %2$s', 'axle-license-tracker' ), $dup['count'], implode( ', ', $dup['sites'] ) ) ) . '</td><td>';
			if ( $dup['limit'] ) {
				echo '<span class="axle-badge axle-badge--' . ( $dup['over'] ? 'error' : 'good' ) . '">' . esc_html( $dup['over'] ? sprintf( /* translators: %d: sites the plan allows. */ __( 'Over: %d sites', 'axle-license-tracker' ), $dup['limit'] ) : sprintf( /* translators: %d: sites the plan allows. */ __( 'Within: %d sites', 'axle-license-tracker' ), $dup['limit'] ) ) . '</span>';
			} else {
				echo '<span class="axle-muted">' . esc_html__( 'Not reported', 'axle-license-tracker' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Yearly cost per vendor and currency.
	 *
	 * @param array[] $rows Combined rows.
	 */
	private function render_costs( array $rows ): void {
		$costs = Overview::costs( $rows );
		echo '<section class="axle-card alc-hub-costs"><h2 class="axle-card__title">' . esc_html__( 'What the licenses cost', 'axle-license-tracker' ) . '</h2>';
		if ( ! $costs['vendors'] ) {
			echo '<div class="axle-empty alc-empty"><p>' . esc_html__( 'No costs yet. Add a cost and a currency to a license on the site it belongs to and it shows up here.', 'axle-license-tracker' ) . '</p></div></section>';
			return;
		}

		echo '<p>' . esc_html__( 'Per year and per month, in each currency. Currencies are never converted into one another. One-off and lifetime licenses are left out.', 'axle-license-tracker' ) . '</p>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Cost per vendor', 'axle-license-tracker' ) . '"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Yearly and monthly cost per vendor', 'axle-license-tracker' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Vendor', 'axle-license-tracker' ), __( 'Per year', 'axle-license-tracker' ), __( 'Per month', 'axle-license-tracker' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $costs['vendors'] as $vendor => $by_currency ) {
			echo '<tr><td>' . esc_html( (string) $vendor ) . '</td><td>' . Parts::money_lines( $by_currency ) . '</td><td>' . Parts::money_lines( self::monthly( $by_currency ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in money_lines().
		}
		echo '<tr><th scope="row">' . esc_html__( 'All vendors', 'axle-license-tracker' ) . '</th><td>' . Parts::money_lines( $costs['currencies'] ) . '</td><td>' . Parts::money_lines( self::monthly( $costs['currencies'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in money_lines().
		echo '</tbody></table></div></section>';
	}

	/**
	 * Yearly amounts as a monthly share, rounded down to the minor unit.
	 *
	 * @param array<string, int> $yearly Minor units per currency.
	 * @return array<string, int>
	 */
	public static function monthly( array $yearly ): array {
		return array_map(
			static function ( $minor ): int {
				return intdiv( (int) $minor, 12 );
			},
			$yearly
		);
	}

	/**
	 * The form that adds a site.
	 */
	private function render_add(): void {
		$form = get_transient( 'axle_license_tracker_hubform_' . get_current_user_id() );
		delete_transient( 'axle_license_tracker_hubform_' . get_current_user_id() );
		$form = is_array( $form ) ? $form : array();

		echo '<section class="axle-card alc-hub-add"><h2 class="axle-card__title" id="alc-add-site">' . esc_html__( 'Add a site', 'axle-license-tracker' ) . '</h2>';
		echo '<p>' . esc_html__( 'On the other site, install Axle License Tracker, create a user with the License reporter role, then open that user\'s profile and create an application password. The role can read the license report and nothing else.', 'axle-license-tracker' ) . '</p>';
		printf( '<form method="post" action="%s" class="alc-hub-form alc-busy-form" data-alc-busy="%s" aria-labelledby="alc-add-site"><input type="hidden" name="action" value="axle_license_tracker_hub_add">', Parts::post_url(), esc_attr__( 'Adding the site and fetching its licenses.', 'axle-license-tracker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_url().
		Parts::nonce( 'axle_license_tracker_hub_add' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$fields = array(
			array( 'url', __( 'Site address', 'axle-license-tracker' ), 'url', __( 'The full address, starting with https://.', 'axle-license-tracker' ), 'regular-text', 'url' ),
			array( 'user', __( 'User name', 'axle-license-tracker' ), 'text', __( 'The user with the License reporter role.', 'axle-license-tracker' ), 'regular-text', 'username' ),
			array( 'password', __( 'Application password', 'axle-license-tracker' ), 'password', __( 'Stored encrypted. It is not shown again, not even to you.', 'axle-license-tracker' ), 'regular-text', 'new-password' ),
			array( 'label', __( 'Name (optional)', 'axle-license-tracker' ), 'text', __( 'How the site is called in lists. Defaults to its address.', 'axle-license-tracker' ), 'regular-text', 'off' ),
		);
		foreach ( $fields as $field ) {
			printf(
				'<tr><th scope="row"><label for="alc-site-%1$s">%2$s</label></th><td><input type="%3$s" id="alc-site-%1$s" name="%1$s" value="%4$s" class="%5$s" autocomplete="%6$s" aria-describedby="alc-site-%1$s-help"%7$s><p class="description" id="alc-site-%1$s-help">%8$s</p></td></tr>',
				esc_attr( $field[0] ),
				esc_html( $field[1] ),
				esc_attr( $field[2] ),
				'password' === $field[0] ? '' : esc_attr( (string) ( $form[ $field[0] ] ?? '' ) ),
				esc_attr( $field[4] ),
				esc_attr( $field[5] ),
				'label' === $field[0] ? '' : ' required',
				esc_html( $field[3] )
			);
		}
		echo '</tbody></table>';
		printf( '<p><button type="submit" class="button button-primary">%s</button><span class="spinner alc-spinner"></span></p></form></section>', esc_html__( 'Add site and check it', 'axle-license-tracker' ) );
	}

	/**
	 * Turns the hub on or off.
	 */
	public function handle_toggle(): void {
		Parts::verify( 'axle_license_tracker_hub_toggle' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Checked in verify().
		$enable = ! empty( $_REQUEST['enable'] );
		$this->hub->set_enabled( $enable );

		if ( $enable ) {
			Parts::redirect( array( 'tab' => 'sites' ), 'success', Messages::get( 'hub_on' ) );
		}
		$undo = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'axle_license_tracker_hub_toggle',
					'enable' => 1,
				),
				admin_url( 'admin-post.php' ) 
			),
			'axle_license_tracker_hub_toggle' 
		);
		Parts::redirect( array( 'tab' => 'sites' ), 'success', Messages::get( 'hub_off' ), $undo );
	}

	/**
	 * Adds a site and fetches it once.
	 */
	public function handle_add(): void {
		Parts::verify( 'axle_license_tracker_hub_add' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$user     = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$label    = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? trim( wp_unslash( $_POST['password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password: any character is allowed, and it is only encrypted and sent.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$site = $this->hub->sites()->add( $url, $user, $password, $label );
		if ( is_wp_error( $site ) ) {
			set_transient(
				'axle_license_tracker_hubform_' . get_current_user_id(),
				array(
					'url'   => $url,
					'user'  => $user,
					'label' => $label,
				),
				5 * MINUTE_IN_SECONDS
			);
			Parts::redirect( array( 'tab' => 'sites' ), 'error', $site->get_error_message() );
		}

		$result = $this->hub->sync( $site['id'] );
		if ( is_wp_error( $result ) ) {
			/* translators: 1: site name, 2: why it failed. */
			Parts::redirect( array( 'tab' => 'sites' ), 'warning', sprintf( __( '%1$s was added, but the first check failed: %2$s', 'axle-license-tracker' ), $site['label'], $result->get_error_message() ) );
		}
		/* translators: 1: site name, 2: number of licenses. */
		Parts::redirect( array( 'tab' => 'sites' ), 'success', sprintf( _n( '%1$s was added and reported %2$d license.', '%1$s was added and reported %2$d licenses.', $result['count'], 'axle-license-tracker' ), $site['label'], $result['count'] ) );
	}

	/**
	 * Removes a site.
	 */
	public function handle_remove(): void {
		Parts::verify( 'axle_license_tracker_hub_remove' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$id   = isset( $_POST['site'] ) ? sanitize_text_field( wp_unslash( $_POST['site'] ) ) : '';
		$site = $this->hub->sites()->get( $id );
		if ( ! $site ) {
			Parts::redirect( array( 'tab' => 'sites' ), 'error', Messages::get( 'site_not_found' ) );
		}

		$this->hub->sites()->remove( $id );
		/* translators: %s: site name. */
		Parts::redirect( array( 'tab' => 'sites' ), 'success', sprintf( __( '%s was removed, with its password and its licenses.', 'axle-license-tracker' ), $site['label'] ) );
	}

	/**
	 * Fetches one site, or all of them, now.
	 */
	public function handle_sync(): void {
		Parts::verify( 'axle_license_tracker_hub_sync' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$id  = isset( $_POST['site'] ) ? sanitize_text_field( wp_unslash( $_POST['site'] ) ) : '';
		$all = ! empty( $_POST['all'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $id ) {
			$site = $this->hub->sites()->get( $id );
			if ( ! $site ) {
				Parts::redirect( array( 'tab' => 'sites' ), 'error', Messages::get( 'site_not_found' ) );
			}
			$result = $this->hub->sync( $id );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: site name, 2: why it failed. */
				Parts::redirect( array( 'tab' => 'sites' ), 'error', sprintf( __( '%1$s could not be checked: %2$s', 'axle-license-tracker' ), $site['label'], $result->get_error_message() ) );
			}
			/* translators: 1: site name, 2: number of licenses. */
			Parts::redirect( array( 'tab' => 'sites' ), 'success', sprintf( _n( '%1$s was checked: %2$d license.', '%1$s was checked: %2$d licenses.', $result['count'], 'axle-license-tracker' ), $site['label'], $result['count'] ) );
		}

		$results = $this->hub->sync_due( $all );
		$failed  = count( array_filter( $results, 'is_wp_error' ) );
		$waiting = max( 0, count( $this->hub->sites()->all() ) - count( $results ) );
		/* translators: 1: number of sites checked, 2: number that failed. */
		$text = sprintf( _n( '%1$d site was checked, %2$d could not be reached.', '%1$d sites were checked, %2$d could not be reached.', count( $results ), 'axle-license-tracker' ), count( $results ), $failed );
		if ( $waiting > 0 ) {
			/* translators: %d: number of sites. */
			$text .= ' ' . sprintf( _n( '%d more site is checked in the background.', '%d more sites are checked in the background.', $waiting, 'axle-license-tracker' ), $waiting );
		}
		Parts::redirect( array( 'tab' => 'sites' ), $failed ? 'warning' : 'success', $text );
	}
}
