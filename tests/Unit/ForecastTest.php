<?php
/**
 * Tests for the twelve-month cost forecast and the calendar feed.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Calendar;
use AryanX\Axle\LicenseTracker\Forecast;
use AryanX\Axle\LicenseTracker\Hub\Hub;
use AryanX\Axle\LicenseTracker\Report;
use AryanX\Axle\LicenseTracker\Tracker;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Forecast, Calendar and the report's redaction.
 */
final class ForecastTest extends LicenseTestCase {

	/**
	 * A row.
	 *
	 * @param array $extra Fields to change.
	 * @return array
	 */
	private function row( array $extra = array() ): array {
		return array_merge(
			array(
				'id'         => 'plugin:a/a.php',
				'name'       => 'Alpha',
				'vendor'     => 'Acme',
				'site_label' => 'Home',
				'site'       => 'local',
				'state'      => 'valid',
				'renewal'    => '2026-12-01',
				'cycle'      => 'yearly',
				'cost'       => 10000,
				'currency'   => 'USD',
				'auto_renew' => '',
			),
			$extra
		);
	}

	public function test_months_clamp_to_the_length_of_the_month(): void {
		$this->assertSame( '2027-02-28', Forecast::add_months( 2026, 12, 31, 2 ) );
		$this->assertSame( '2028-02-29', Forecast::add_months( 2027, 12, 31, 2 ) );
		$this->assertSame( '2027-01-15', Forecast::add_months( 2026, 1, 15, 12 ) );
		$this->assertSame( '2026-12-31', Forecast::window_end( '2026-01-15', 12 ) );
		$this->assertSame( '2027-10-31', Forecast::window_end( '2026-11-13', 12 ) );
	}

	public function test_a_yearly_license_counts_once_in_the_month_it_renews(): void {
		$forecast = Forecast::build( array( $this->row() ), '2026-11-13' );

		$this->assertCount( 12, $forecast['months'] );
		$this->assertSame( array( 'USD' => 10000 ), $forecast['totals'] );
		$this->assertSame( array( 'USD' => 10000 ), $forecast['months']['2026-12']['totals'] );
		$this->assertSame( array(), $forecast['months']['2027-01']['totals'] );
		$this->assertSame( array( 'Acme' => array( 'USD' => 10000 ) ), $forecast['vendors'] );
	}

	public function test_a_monthly_license_counts_every_month_from_its_date(): void {
		$forecast = Forecast::build( array( $this->row( array( 'cycle' => 'monthly', 'cost' => 500, 'renewal' => '2026-11-30' ) ) ), '2026-11-13' );

		$this->assertSame( 12 * 500, $forecast['totals']['USD'] );
		$this->assertSame( '2027-02-28', $forecast['months']['2027-02']['items'][0]['date'] );
	}

	public function test_a_past_date_rolls_forward_only_for_a_license_that_renews_by_itself(): void {
		$past = $this->row( array( 'renewal' => '2026-03-10' ) );

		$this->assertSame( array(), Forecast::build( array( $past ), '2026-11-13' )['totals'] );
		$this->assertSame( 'past', Forecast::build( array( $past ), '2026-11-13' )['no_date'][0]['reason'] );

		$auto = Forecast::build( array( array_merge( $past, array( 'auto_renew' => 'yes' ) ) ), '2026-11-13' );
		$this->assertSame( array( 'USD' => 10000 ), $auto['totals'] );
		$this->assertSame( '2027-03-10', $auto['months']['2027-03']['items'][0]['date'] );

		$lapsed = Forecast::build( array( array_merge( $past, array( 'auto_renew' => 'yes', 'state' => 'expired' ) ) ), '2026-11-13' );
		$this->assertSame( array(), $lapsed['totals'], 'An expired license does not renew by itself.' );
	}

	public function test_what_cannot_be_added_up_is_listed_apart(): void {
		$forecast = Forecast::build(
			array(
				$this->row( array( 'name' => 'No cost', 'cost' => null, 'currency' => '' ) ),
				$this->row( array( 'name' => 'No date', 'renewal' => '' ) ),
				$this->row( array( 'name' => 'Lifetime', 'cycle' => 'lifetime' ) ),
				$this->row( array( 'name' => 'One-off', 'cycle' => 'one-off', 'renewal' => '' ) ),
				$this->row( array( 'name' => 'Free lifetime', 'cycle' => 'lifetime', 'cost' => null, 'currency' => '' ) ),
				$this->row( array( 'name' => 'Far off', 'renewal' => '2029-01-01' ) ),
			),
			'2026-11-13'
		);

		$this->assertSame( array(), $forecast['totals'] );
		$this->assertSame( array( 'No cost' ), array_column( $forecast['unknown_cost'], 'name' ) );
		$this->assertSame( array( 'No date' ), array_column( $forecast['no_date'], 'name' ) );
		$this->assertSame( array( 'Lifetime', 'One-off' ), array_column( $forecast['one_off'], 'name' ) );
	}

	public function test_currencies_are_never_mixed(): void {
		$forecast = Forecast::build(
			array(
				$this->row(),
				$this->row( array( 'name' => 'Euro', 'currency' => 'EUR', 'cost' => 4900 ) ),
			),
			'2026-11-13'
		);

		$this->assertSame(
			array(
				'EUR' => 4900,
				'USD' => 10000,
			),
			$forecast['months']['2026-12']['totals']
		);
	}

	public function test_the_forecast_can_be_filtered_and_exported(): void {
		Filters\expectApplied( 'axle_license_tracker_forecast' )->once();
		$forecast = Forecast::build( array( $this->row( array( 'name' => '=HYPERLINK("x")' ) ), $this->row( array( 'name' => 'No cost', 'cost' => null, 'currency' => '' ) ) ), '2026-11-13' );
		$csv      = Forecast::csv( $forecast );

		$this->assertStringStartsWith( '"kind","date","site","product","vendor","cycle","currency","amount"', $csv );
		$this->assertStringContainsString( '"charge","2026-12-01","Home","\'=HYPERLINK(""x"")","Acme","yearly","USD","100.00"', $csv );
		$this->assertStringContainsString( '"unknown-cost"', $csv );
	}

	/**
	 * A calendar over fixed rows.
	 *
	 * @param array[] $rows Tracker rows.
	 * @return Calendar
	 */
	private function calendar( array $rows ): Calendar {
		$tracker = Mockery::mock( Tracker::class );
		$tracker->shouldReceive( 'tracked' )->andReturn( $rows );
		$hub = Mockery::mock( Hub::class );
		$hub->shouldReceive( 'enabled' )->andReturn( false );

		return new Calendar( $tracker, $hub );
	}

	/**
	 * A full tracker row, as the Tracker builds it.
	 *
	 * @param array $extra Fields to change.
	 * @return array
	 */
	private function tracker_row( array $extra = array() ): array {
		return array_merge(
			array(
				'id'              => 'plugin:a/a.php',
				'kind'            => 'plugin',
				'name'            => 'Alpha, the "best"; plugin',
				'version'         => '1.0',
				'active'          => true,
				'type'            => 'premium',
				'vendor'          => 'Acme',
				'sdk'             => '',
				'source'          => 'updates.acme.test',
				'state'           => 'valid',
				'state_note'      => '',
				'as_of'           => time(),
				'stale'           => false,
				'expires'         => '',
				'latest'          => '',
				'risk'            => 'ok',
				'risk_signals'    => array(),
				'renewal'         => '2026-12-01',
				'days_left'       => 18,
				'cycle'           => 'yearly',
				'cost'            => 19900,
				'currency'        => 'USD',
				'auto_renew'      => '',
				'seats'           => 0,
				'seats_used'      => 0,
				'plan'            => '',
				'purchase_source' => '',
				'owner'           => 'Bean There',
				'owner_email'     => 'owner@example.com',
				'account_email'   => 'billing@example.com',
				'fingerprint'     => '9f86d081884c-AB12',
				'ignored'         => false,
				'sources'         => array(),
			),
			$extra
		);
	}

	public function test_the_feed_is_off_until_enabled_and_each_change_fires_an_action(): void {
		Functions\when( 'wp_generate_password' )->alias(
			static function () {
				static $n = 0;
				return str_repeat( (string) ++$n, 40 );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $key, $value, $url ) {
				return $url . '?' . $key . '=' . $value;
			}
		);
		$calendar = $this->calendar( array() );
		Actions\expectDone( 'axle_license_tracker_feed_enabled' )->once();
		Actions\expectDone( 'axle_license_tracker_feed_regenerated' )->once();
		Actions\expectDone( 'axle_license_tracker_feed_disabled' )->once();

		$this->assertFalse( $calendar->enabled() );
		$this->assertSame( '', $calendar->url() );

		$url = $calendar->enable();
		$this->assertTrue( $calendar->enabled() );
		$this->assertSame( 'https://example.com/?axle-license-tracker-ics=' . str_repeat( '1', 40 ), $url );
		$this->assertSame( $url, $calendar->enable( true ), 'Enabling again keeps the token.' );
		$this->assertTrue( $calendar->settings()['hub'] );

		$this->assertNotSame( $url, $calendar->regenerate() );
		$this->assertTrue( $calendar->settings()['hub'], 'A new link keeps the setting.' );

		$calendar->disable();
		$this->assertFalse( $calendar->enabled() );
		$this->assertSame( '', $calendar->url() );
	}

	public function test_an_event_has_the_product_vendor_cost_and_site_and_never_a_key_or_an_email(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		$calendar = $this->calendar( array( $this->tracker_row(), $this->tracker_row( array( 'id' => 'plugin:gone/gone.php', 'ignored' => true ) ), $this->tracker_row( array( 'id' => 'plugin:b/b.php', 'renewal' => '', 'cost' => null ) ) ) );

		$events = $calendar->events( '2026-11-13' );
		$this->assertCount( 1, $events );
		$this->assertSame( '2026-12-01', $events[0]['date'] );
		$this->assertStringContainsString( 'Alpha', $events[0]['summary'] );
		$this->assertStringContainsString( 'Site: Example', $events[0]['description'] );
		$this->assertStringContainsString( 'Vendor: Acme', $events[0]['description'] );
		$this->assertStringContainsString( 'Cost: 199.00 USD / yearly', $events[0]['description'] );

		$ics = $calendar->render( $events, '20261113T080000Z' );
		foreach ( array( '9f86d081884c', 'AB12', 'owner@example.com', 'billing@example.com', 'Bean There' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $ics );
		}
		$this->assertStringContainsString( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "DTSTART;VALUE=DATE:20261201\r\n", $ics );
		$this->assertStringContainsString( "DTEND;VALUE=DATE:20261202\r\n", $ics );
		$this->assertStringContainsString( "TRIGGER:-P7D\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
	}

	public function test_text_is_escaped_and_long_lines_are_folded_without_cutting_a_character(): void {
		$this->assertSame( 'a\\, b\; c\\\\ d\\ne', Calendar::escape( "a, b; c\\ d\ne" ) );

		$line   = 'SUMMARY:' . str_repeat( 'é', 60 );
		$folded = Calendar::fold( $line );
		foreach ( explode( "\r\n", $folded ) as $physical ) {
			$this->assertLessThanOrEqual( 75, strlen( $physical ) );
			$this->assertTrue( (bool) preg_match( '//u', $physical ), 'Valid UTF-8 on every physical line.' );
		}
		$this->assertSame( $line, str_replace( "\r\n ", '', $folded ) );
	}

	public function test_the_alarm_can_be_turned_off_and_events_filtered(): void {
		Filters\expectApplied( 'axle_license_tracker_feed_alarm_days' )->andReturn( 0 );
		$ics = $this->calendar( array() )->render( array( array( 'uid' => 'u@x', 'date' => '2026-12-01', 'summary' => 'S', 'description' => '' ) ), '20261113T080000Z' );

		$this->assertStringNotContainsString( 'VALARM', $ics );
		$this->assertStringNotContainsString( 'DESCRIPTION', $ics );
	}

	public function test_the_report_leaves_out_emails_and_paths_for_a_reporter_but_not_the_admin_export(): void {
		$row = $this->tracker_row(
			array(
				'owner'      => 'Pat <pat@agency.example>',
				'vendor'     => 'Acme',
				'state_note' => 'Read from /var/www/html/wp-content/plugins/acme/license.php by admin@example.com',
			)
		);

		$entry = Report::component( $row );
		$this->assertSame( 'Pat <>', $entry['owner'] );
		$this->assertStringNotContainsString( '@', $entry['state_note'] );
		$this->assertStringNotContainsString( '/var/www', $entry['state_note'] );
		$this->assertArrayNotHasKey( 'owner_email', $entry );
		$this->assertArrayNotHasKey( 'account_email', $entry );

		$full = Report::component( $row, true );
		$this->assertSame( 'owner@example.com', $full['owner_email'] );
		$this->assertSame( 'Pat <pat@agency.example>', $full['owner'] );
	}
}
