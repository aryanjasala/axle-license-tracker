<?php
/**
 * The renewals calendar feed (.ics).
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker;

use AryanX\Axle\LicenseTracker\Hub\Hub;
use AryanX\Axle\LicenseTracker\Hub\Overview;

use const AryanX\Axle\LicenseTracker\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * A subscribe-able calendar of renewal dates behind a long random token in
 * the address. Off by default. An event carries the product, the vendor, the
 * cost and the site; it never carries a key, a fingerprint, an email or a
 * link into wp-admin.
 */
class Calendar implements Service {

	public const OPTION = 'axle_license_tracker_feed';
	public const QUERY  = 'axle-license-tracker-ics';

	/**
	 * Rows.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * The hub.
	 *
	 * @var Hub
	 */
	private Hub $hub;

	/**
	 * Sets up the feed.
	 *
	 * @param Tracker $tracker Rows.
	 * @param Hub     $hub     The hub.
	 */
	public function __construct( Tracker $tracker, Hub $hub ) {
		$this->tracker = $tracker;
		$this->hub     = $hub;
	}

	/**
	 * Serves the feed when its address is asked for.
	 */
	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'maybe_serve' ) );
	}

	/**
	 * The saved feed settings.
	 *
	 * @return array{token: string, hub: bool, created: int}
	 */
	public function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$token = isset( $saved['token'] ) && is_string( $saved['token'] ) && preg_match( '/^[A-Za-z0-9]{32,64}$/', $saved['token'] ) ? $saved['token'] : '';

		return array(
			'token'   => $token,
			'hub'     => ! empty( $saved['hub'] ),
			'created' => (int) ( $saved['created'] ?? 0 ),
		);
	}

	/**
	 * Whether the feed is on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return '' !== $this->settings()['token'];
	}

	/**
	 * The address to subscribe to, or an empty string while the feed is off.
	 *
	 * @return string
	 */
	public function url(): string {
		$token = $this->settings()['token'];

		return '' === $token ? '' : add_query_arg( self::QUERY, $token, home_url( '/' ) );
	}

	/**
	 * Turns the feed on with a new token, or changes whether hub renewals join it.
	 *
	 * @param bool $with_hub Include the hub's sites.
	 * @return string The address.
	 */
	public function enable( bool $with_hub = false ): string {
		$current = $this->settings();
		$token   = '' !== $current['token'] ? $current['token'] : wp_generate_password( 40, false, false );
		update_option(
			self::OPTION,
			array(
				'token'   => $token,
				'hub'     => $with_hub,
				'created' => $current['created'] ? $current['created'] : time(),
			),
			false
		);

		if ( '' === $current['token'] ) {
			/**
			 * Fires after the calendar feed was turned on.
			 */
			do_action( 'axle_license_tracker_feed_enabled' );
		}

		return $this->url();
	}

	/**
	 * Replaces the token. The old address stops working at once.
	 *
	 * @return string The new address.
	 */
	public function regenerate(): string {
		$current = $this->settings();
		update_option(
			self::OPTION,
			array(
				'token'   => wp_generate_password( 40, false, false ),
				'hub'     => $current['hub'],
				'created' => time(),
			),
			false
		);

		/**
		 * Fires after the calendar feed got a new token.
		 */
		do_action( 'axle_license_tracker_feed_regenerated' );

		return $this->url();
	}

	/**
	 * Turns the feed off and forgets the token.
	 */
	public function disable(): void {
		if ( $this->enabled() ) {
			delete_option( self::OPTION );

			/**
			 * Fires after the calendar feed was turned off.
			 */
			do_action( 'axle_license_tracker_feed_disabled' );
		}
	}

	/**
	 * Prints the calendar when the request carries the right token.
	 */
	public function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token in the address is the credential.
		if ( ! isset( $_GET[ self::QUERY ] ) || ! is_string( $_GET[ self::QUERY ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same.
		$given = sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) );
		$token = $this->settings()['token'];
		if ( '' === $token || ! hash_equals( $token, $given ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="license-renewals.ics"' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		echo $this->render( $this->events( gmdate( 'Y-m-d' ) ), gmdate( 'Ymd\THis\Z' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text/calendar file, escaped per RFC 5545 in render().
		exit;
	}

	/**
	 * The events: every renewal date from today on for the next year.
	 *
	 * @param string $today Today, Y-m-d.
	 * @return array[] Each: uid, date (Y-m-d), summary, description.
	 */
	public function events( string $today ): array {
		$rows = array();
		foreach ( $this->tracker->tracked() as $row ) {
			if ( ! $row['ignored'] ) {
				$rows[] = Report::component( $row );
			}
		}
		$rows = Overview::rows( $rows, array(), (string) get_bloginfo( 'name' ), $today );
		if ( $this->settings()['hub'] && $this->hub->enabled() ) {
			foreach ( $this->hub->sources() as $id => $source ) {
				foreach ( Overview::rows( (array) $source['components'], array(), (string) $source['label'], $today ) as $row ) {
					$row['site'] = $id;
					$rows[]      = $row;
				}
			}
		}

		$last   = Forecast::window_end( $today, 12 );
		$events = array();
		foreach ( $rows as $row ) {
			if ( null === $row['cost'] && '' === $row['renewal'] ) {
				continue;
			}
			foreach ( Forecast::dates( $row, $today, $last )['dates'] as $date ) {
				$summary = sprintf(
					/* translators: %s: plugin or theme name. */
					__( 'Renews: %s', 'axle-license-tracker' ),
					$row['name']
				);
				$lines   = array();
				$lines[] = sprintf(
					/* translators: %s: site name. */
					__( 'Site: %s', 'axle-license-tracker' ),
					$row['site_label']
				);
				if ( '' !== $row['vendor'] ) {
					/* translators: %s: vendor. */
					$lines[] = sprintf( __( 'Vendor: %s', 'axle-license-tracker' ), $row['vendor'] );
				}
				if ( null !== $row['cost'] && '' !== $row['currency'] ) {
					/* translators: %s: amount and currency. */
					$lines[] = sprintf( __( 'Cost: %s', 'axle-license-tracker' ), Details::from_minor( (int) $row['cost'], $row['currency'] ) . ' ' . $row['currency'] . ( '' !== $row['cycle'] ? ' / ' . $row['cycle'] : '' ) );
				}

				$events[] = array(
					'uid'         => sha1( $row['site'] . '|' . $row['id'] . '|' . $date ) . '@' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					'date'        => $date,
					'summary'     => $summary,
					'description' => implode( "\n", $lines ),
				);
			}
		}
		usort(
			$events,
			static function ( array $a, array $b ): int {
				return strcmp( $a['date'] . $a['uid'], $b['date'] . $b['uid'] );
			}
		);

		/**
		 * Filters the events of the calendar feed.
		 *
		 * Keep keys, fingerprints and emails out of what you add.
		 *
		 * @param array[] $events Each: uid, date (Y-m-d), summary, description.
		 */
		return (array) apply_filters( 'axle_license_tracker_feed_events', $events );
	}

	/**
	 * The events as an iCalendar file.
	 *
	 * @param array[] $events Events.
	 * @param string  $stamp  DTSTAMP value, such as 20261113T080000Z.
	 * @return string
	 */
	public function render( array $events, string $stamp ): string {
		/**
		 * Filters how many days before a renewal the calendar app reminds you. 0 for no reminder.
		 *
		 * @param int $days Default 7, from 0 to 60.
		 */
		$alarm = max( 0, min( 60, (int) apply_filters( 'axle_license_tracker_feed_alarm_days', 7 ) ) );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//AryanX//Axle License Tracker ' . VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			// Translators are not involved: calendar names are shown by the calendar app.
			'X-WR-CALNAME:' . self::escape( sprintf( 'License renewals: %s', (string) get_bloginfo( 'name' ) ) ),
			'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
			'X-PUBLISHED-TTL:PT12H',
		);
		foreach ( $events as $event ) {
			$start   = (string) strtotime( $event['date'] . ' 00:00:00 UTC' );
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . self::escape( (string) $event['uid'] );
			$lines[] = 'DTSTAMP:' . $stamp;
			$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', (int) $start );
			$lines[] = 'DTEND;VALUE=DATE:' . gmdate( 'Ymd', (int) $start + DAY_IN_SECONDS );
			$lines[] = 'SUMMARY:' . self::escape( (string) $event['summary'] );
			if ( '' !== (string) $event['description'] ) {
				$lines[] = 'DESCRIPTION:' . self::escape( (string) $event['description'] );
			}
			$lines[] = 'TRANSP:TRANSPARENT';
			if ( $alarm > 0 ) {
				$lines[] = 'BEGIN:VALARM';
				$lines[] = 'ACTION:DISPLAY';
				$lines[] = 'DESCRIPTION:' . self::escape( (string) $event['summary'] );
				$lines[] = 'TRIGGER:-P' . $alarm . 'D';
				$lines[] = 'END:VALARM';
			}
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Escapes a text value (RFC 5545).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\\,', '\\n' ), $text );
	}

	/**
	 * Folds a line at 75 octets, without cutting a multi-byte character.
	 *
	 * @param string $line One content line.
	 * @return string
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$out   = '';
		$limit = 75;
		$left  = strlen( $line );
		while ( $left > $limit ) {
			$cut = $limit;
			while ( $cut > 0 && 0x80 === ( ord( $line[ $cut ] ) & 0xC0 ) ) {
				--$cut;
			}
			$out  .= substr( $line, 0, $cut ) . "\r\n ";
			$line  = substr( $line, $cut );
			$left  = strlen( $line );
			$limit = 74;
		}

		return $out . $line;
	}
}
