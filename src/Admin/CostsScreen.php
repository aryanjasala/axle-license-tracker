<?php
/**
 * Plugins > Licenses > Costs: a year of renewals, and the calendar feed.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Admin;

use AryanX\Axle\LicenseTracker\Calendar;
use AryanX\Axle\LicenseTracker\Forecast;
use AryanX\Axle\LicenseTracker\Hub\Hub;
use AryanX\Axle\LicenseTracker\Messages;
use AryanX\Axle\LicenseTracker\Service;

defined( 'ABSPATH' ) || exit;

/**
 * The costs tab: what the next twelve months will cost per month, vendor and
 * currency, with an CSV export, and the settings of the calendar feed.
 */
class CostsScreen implements Service {

	/**
	 * The hub.
	 *
	 * @var Hub
	 */
	private Hub $hub;

	/**
	 * The calendar feed.
	 *
	 * @var Calendar
	 */
	private Calendar $calendar;

	/**
	 * Sets up the tab.
	 *
	 * @param Hub      $hub      The hub.
	 * @param Calendar $calendar The calendar feed.
	 */
	public function __construct( Hub $hub, Calendar $calendar ) {
		$this->hub      = $hub;
		$this->calendar = $calendar;
	}

	/**
	 * Adds the handlers.
	 */
	public function register(): void {
		add_action( 'admin_post_axle_license_tracker_feed', array( $this, 'handle_feed' ) );
		add_action( 'admin_post_axle_license_tracker_costs_export', array( $this, 'handle_export' ) );
	}

	/**
	 * Whether the view counts the hub's sites.
	 *
	 * @return bool
	 */
	private function all_sites(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which view to show.
		return $this->hub->enabled() && isset( $_REQUEST['scope'] ) && 'all' === $_REQUEST['scope'];
	}

	/**
	 * The forecast for the current scope.
	 *
	 * @return array
	 */
	private function forecast(): array {
		return Forecast::build( Hub::billable( $this->hub->rows( $this->all_sites() ) ), gmdate( 'Y-m-d' ), 12 );
	}

	/**
	 * Prints the tab.
	 */
	public function render(): void {
		$all      = $this->all_sites();
		$forecast = $this->forecast();

		echo '<p class="axle-intro">' . esc_html__( 'What the renewals of the next 12 months will cost, month by month, from the renewal dates and costs you have entered. Amounts stay in their own currency.', 'axle-license-tracker' ) . '</p>';

		echo '<section class="axle-card alc-costs"><h2 class="axle-card__title">' . esc_html__( 'The next 12 months', 'axle-license-tracker' ) . '</h2>';
		$this->render_toolbar( $all );

		if ( ! $forecast['totals'] ) {
			echo '<div class="axle-empty alc-empty"><p>' . esc_html__( 'No renewal costs to add up yet. A license counts once it has a renewal date, a cost and a currency.', 'axle-license-tracker' ) . '</p>';
			printf( '<a class="button" href="%s">%s</a></div>', esc_url( Page::url() ), esc_html__( 'Add costs on the Licenses tab', 'axle-license-tracker' ) );
		} else {
			$this->render_totals( $forecast );
			$this->render_months( $forecast );
		}
		echo '</section>';

		if ( $forecast['totals'] ) {
			$this->render_vendors( $forecast );
		}
		$this->render_left_out( $forecast );
		$this->render_feed();
	}

	/**
	 * Scope switch and export.
	 *
	 * @param bool $all Whether the hub's sites are counted.
	 */
	private function render_toolbar( bool $all ): void {
		echo '<div class="axle-toolbar">';
		if ( $this->hub->enabled() ) {
			printf( '<form method="get" class="alc-scope-form"><input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="costs">', esc_attr( Page::SLUG ) );
			printf( '<label for="alc-scope">%s</label> <select id="alc-scope" name="scope"><option value="">%s</option><option value="all"%s>%s</option></select> ', esc_html__( 'Count', 'axle-license-tracker' ), esc_html__( 'This site only', 'axle-license-tracker' ), selected( $all, true, false ), esc_html__( 'All sites in the hub', 'axle-license-tracker' ) );
			printf( '<button type="submit" class="button">%s</button></form>', esc_html__( 'Show', 'axle-license-tracker' ) );
		}
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'axle_license_tracker_costs_export',
					'scope'  => $all ? 'all' : '',
				),
				admin_url( 'admin-post.php' ) 
			),
			'axle_license_tracker_costs_export' 
		);
		printf( '<span class="axle-toolbar__end"><a class="button" href="%s">%s</a></span></div>', esc_url( $url ), esc_html__( 'Download CSV', 'axle-license-tracker' ) );
	}

	/**
	 * The total per currency.
	 *
	 * @param array $forecast Forecast.
	 */
	private function render_totals( array $forecast ): void {
		echo '<div class="axle-stats" role="group" aria-label="' . esc_attr__( 'Total for the next 12 months', 'axle-license-tracker' ) . '">';
		foreach ( $forecast['totals'] as $currency => $minor ) {
			printf(
				'<div class="axle-stat alc-tile-total"><span class="axle-stat__value">%1$s</span><span class="axle-stat__label">%2$s</span></div>',
				esc_html( Display::money( (int) $minor, (string) $currency ) ),
				/* translators: %s: currency code. */
				esc_html( sprintf( __( 'in the next 12 months (%s)', 'axle-license-tracker' ), $currency ) )
			);
		}
		echo '</div>';
	}

	/**
	 * One row per month.
	 *
	 * @param array $forecast Forecast.
	 */
	private function render_months( array $forecast ): void {
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Cost per month', 'axle-license-tracker' ) . '"><table class="widefat striped alc-forecast"><caption class="screen-reader-text">' . esc_html__( 'Renewal cost per month', 'axle-license-tracker' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Month', 'axle-license-tracker' ), __( 'Cost', 'axle-license-tracker' ), __( 'What renews', 'axle-license-tracker' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $forecast['months'] as $key => $month ) {
			$label = wp_date( 'F Y', (int) strtotime( $key . '-01 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
			echo '<tr data-month="' . esc_attr( $key ) . '"><th scope="row">' . esc_html( (string) $label ) . '</th><td>' . Parts::money_lines( $month['totals'] ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in money_lines().
			if ( ! $month['items'] ) {
				echo '<span class="axle-muted">' . esc_html__( 'Nothing renews', 'axle-license-tracker' ) . '</span>';
			} else {
				echo '<ul class="alc-plain-list">';
				foreach ( $month['items'] as $item ) {
					printf(
						'<li>%1$s <span class="alc-muted">%2$s%3$s, %4$s</span></li>',
						esc_html( $item['name'] ),
						'' !== $item['site'] ? esc_html( $item['site'] ) . ', ' : '',
						esc_html( Display::money( (int) $item['cost'], $item['currency'] ) ),
						Parts::date( $item['date'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date().
					);
				}
				echo '</ul>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Total per vendor.
	 *
	 * @param array $forecast Forecast.
	 */
	private function render_vendors( array $forecast ): void {
		echo '<section class="axle-card alc-forecast-vendors"><h2 class="axle-card__title">' . esc_html__( 'By vendor', 'axle-license-tracker' ) . '</h2>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Cost per vendor for the next 12 months', 'axle-license-tracker' ) . '"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Renewal cost per vendor for the next 12 months', 'axle-license-tracker' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Vendor', 'axle-license-tracker' ) . '</th><th scope="col">' . esc_html__( 'Cost', 'axle-license-tracker' ) . '</th></tr></thead><tbody>';
		foreach ( $forecast['vendors'] as $vendor => $totals ) {
			echo '<tr><td>' . esc_html( (string) $vendor ) . '</td><td>' . Parts::money_lines( $totals ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in money_lines().
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * The items that are not in the totals, and why.
	 *
	 * @param array $forecast Forecast.
	 */
	private function render_left_out( array $forecast ): void {
		$groups = array(
			'no_date'      => array( __( 'Needs a renewal date', 'axle-license-tracker' ), __( 'These have a cost but no usable upcoming renewal date, so they are not in the totals. Their date is missing or already past and the license does not renew on its own.', 'axle-license-tracker' ) ),
			'unknown_cost' => array( __( 'Cost not known', 'axle-license-tracker' ), __( 'These renew, but no cost and currency are entered, so they are not in the totals.', 'axle-license-tracker' ) ),
			'one_off'      => array( __( 'One-off and lifetime', 'axle-license-tracker' ), __( 'Paid once, so they do not repeat and are not in the totals.', 'axle-license-tracker' ) ),
		);
		$any    = false;
		foreach ( $groups as $key => $group ) {
			$any = $any || ! empty( $forecast[ $key ] );
		}
		if ( ! $any ) {
			return;
		}

		echo '<section class="axle-card alc-left-out"><h2 class="axle-card__title">' . esc_html__( 'Not in the totals', 'axle-license-tracker' ) . '</h2>';
		foreach ( $groups as $key => $group ) {
			if ( empty( $forecast[ $key ] ) ) {
				continue;
			}
			echo '<h3>' . esc_html( $group[0] ) . '</h3><p>' . esc_html( $group[1] ) . '</p><ul class="alc-plain-list" data-group="' . esc_attr( $key ) . '">';
			foreach ( array_slice( $forecast[ $key ], 0, 100 ) as $item ) {
				printf(
					'<li>%1$s <span class="alc-muted">%2$s%3$s</span></li>',
					esc_html( $item['name'] ),
					'' !== $item['site'] ? esc_html( $item['site'] ) . ( null !== $item['cost'] ? ', ' : '' ) : '',
					null !== $item['cost'] && '' !== $item['currency'] ? esc_html( Display::money( (int) $item['cost'], $item['currency'] ) ) : ''
				);
			}
			echo '</ul>';
		}
		echo '</section>';
	}

	/**
	 * The calendar feed card.
	 */
	private function render_feed(): void {
		$feed = $this->calendar->settings();
		echo '<section class="axle-card alc-feed"><h2 class="axle-card__title" id="alc-feed-title">' . esc_html__( 'Renewals in your calendar', 'axle-license-tracker' ) . '</h2>';

		if ( ! $this->calendar->enabled() ) {
			echo '<div class="axle-empty alc-empty"><p>' . esc_html__( 'The calendar feed is off. Turn it on to get a private link that Google Calendar, Apple Calendar or Outlook can subscribe to. Each renewal becomes an all-day event with the product, vendor and cost. Keys and fingerprints are never in it.', 'axle-license-tracker' ) . '</p>';
			printf( '<form method="post" action="%s" aria-labelledby="alc-feed-title"><input type="hidden" name="action" value="axle_license_tracker_feed"><input type="hidden" name="op" value="enable">', Parts::post_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_url().
			Parts::nonce( 'axle_license_tracker_feed' );
			if ( $this->hub->enabled() ) {
				printf( '<p><label for="alc-feed-hub-new"><input type="checkbox" id="alc-feed-hub-new" name="hub" value="1"> %s</label></p>', esc_html__( 'Include the renewals of the sites in the hub', 'axle-license-tracker' ) );
			}
			printf( '<button type="submit" class="button button-primary">%s</button></form></div></section>', esc_html__( 'Turn on the calendar feed', 'axle-license-tracker' ) );
			return;
		}

		echo '<p>' . esc_html__( 'Subscribe your calendar to this link. Anyone who has it can read your renewal dates and costs, so share it like a password.', 'axle-license-tracker' ) . '</p>';
		printf( '<p><label for="alc-feed-url" class="screen-reader-text">%1$s</label><input type="text" readonly id="alc-feed-url" class="large-text code alc-feed-url" value="%2$s"> <button type="button" class="button alc-copy" data-alc-copy="alc-feed-url" hidden>%3$s</button></p>', esc_html__( 'Calendar feed link', 'axle-license-tracker' ), esc_attr( $this->calendar->url() ), esc_html__( 'Copy link', 'axle-license-tracker' ) );
		/* translators: %s: how long ago, as a time element. */
		echo '<p class="alc-muted">' . sprintf( esc_html__( 'Link made %s.', 'axle-license-tracker' ), AxleUi::relative_time( (int) $feed['created'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- relative_time() escapes.

		if ( $this->hub->enabled() ) {
			printf( '<form method="post" action="%s" class="alc-feed-hub" aria-labelledby="alc-feed-title"><input type="hidden" name="action" value="axle_license_tracker_feed"><input type="hidden" name="op" value="save">', Parts::post_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in post_url().
			Parts::nonce( 'axle_license_tracker_feed' );
			printf( '<p><label for="alc-feed-hub"><input type="checkbox" id="alc-feed-hub" name="hub" value="1"%s> %s</label> <button type="submit" class="button">%s</button></p></form>', checked( $feed['hub'], true, false ), esc_html__( 'Include the renewals of the sites in the hub', 'axle-license-tracker' ), esc_html__( 'Save', 'axle-license-tracker' ) );
		}

		echo '<p class="alc-feed-actions">';
		Parts::confirm(
			'alc-confirm-regenerate',
			__( 'Make a new link', 'axle-license-tracker' ),
			__( 'Make a new link?', 'axle-license-tracker' ),
			__( 'The current link stops working at once, so every calendar subscribed to it stops updating until you subscribe it again with the new link. This cannot be undone.', 'axle-license-tracker' ),
			'axle_license_tracker_feed',
			array( 'op' => 'regenerate' ),
			__( 'Make a new link', 'axle-license-tracker' )
		);
		echo ' ';
		Parts::confirm(
			'alc-confirm-disable',
			__( 'Turn off the feed', 'axle-license-tracker' ),
			__( 'Turn off the calendar feed?', 'axle-license-tracker' ),
			__( 'The link stops working and is forgotten. Calendars subscribed to it stop updating. Turning the feed on again makes a different link.', 'axle-license-tracker' ),
			'axle_license_tracker_feed',
			array( 'op' => 'disable' ),
			__( 'Turn off the feed', 'axle-license-tracker' )
		);
		echo '</p></section>';
	}

	/**
	 * Feed actions: turn on, save, new link, turn off.
	 */
	public function handle_feed(): void {
		Parts::verify( 'axle_license_tracker_feed' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in verify().
		$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$hub = ! empty( $_POST['hub'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$args = array( 'tab' => 'costs' );

		if ( 'enable' === $op ) {
			$this->calendar->enable( $hub );
			Parts::redirect( $args, 'success', Messages::get( 'feed_on' ) );
		}
		if ( 'save' === $op && $this->calendar->enabled() ) {
			$this->calendar->enable( $hub );
			Parts::redirect( $args, 'success', Messages::get( 'feed_saved' ) );
		}
		if ( 'regenerate' === $op && $this->calendar->enabled() ) {
			$this->calendar->regenerate();
			Parts::redirect( $args, 'success', Messages::get( 'feed_regenerated' ) );
		}
		if ( 'disable' === $op ) {
			$this->calendar->disable();
			Parts::redirect( $args, 'success', Messages::get( 'feed_off' ) );
		}

		Parts::redirect( $args, 'error', Messages::get( 'feed_unknown' ) );
	}

	/**
	 * The CSV download.
	 */
	public function handle_export(): void {
		Parts::verify( 'axle_license_tracker_costs_export' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="license-costs-' . sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo Forecast::csv( $this->forecast() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A file download, not HTML.
		exit;
	}
}
