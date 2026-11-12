<?php
/**
 * Tests for the hub: encryption, address checks, the site store, the remote
 * report, syncing and the combined view.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Hub\Crypt;
use AryanX\Axle\LicenseTracker\Hub\Guard;
use AryanX\Axle\LicenseTracker\Hub\Hub;
use AryanX\Axle\LicenseTracker\Hub\Overview;
use AryanX\Axle\LicenseTracker\Hub\Remote;
use AryanX\Axle\LicenseTracker\Hub\Sites;
use AryanX\Axle\LicenseTracker\Settings;
use AryanX\Axle\LicenseTracker\Tracker;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers everything under src/Hub.
 */
final class HubTest extends LicenseTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
	}

	public function test_a_password_is_sealed_and_only_this_site_can_open_it(): void {
		$this->assertTrue( Crypt::available() );

		$sealed = Crypt::seal( 'abcd efgh ijkl mnop' );
		$this->assertStringStartsWith( 'v1:', $sealed );
		$this->assertStringNotContainsString( 'abcd', $sealed );
		$this->assertSame( 'abcd efgh ijkl mnop', Crypt::open( $sealed ) );
		$this->assertNotSame( $sealed, Crypt::seal( 'abcd efgh ijkl mnop' ), 'A fresh nonce each time.' );

		$this->assertNull( Crypt::open( 'v1:' . base64_encode( 'short' ) ) );
		$this->assertNull( Crypt::open( 'plain' ) );

		Functions\when( 'wp_salt' )->justReturn( 'other-salt' );
		$this->assertNull( Crypt::open( $sealed ), 'Other salts, no password.' );
	}

	public function test_addresses_must_be_https_and_public(): void {
		$this->assertSame( 'https://client.example.com/', Guard::check( ' https://Client.example.com ' ) );
		$this->assertSame( 'https://example.com/blog/', Guard::check( 'https://example.com/blog?x=1#top' ) );

		foreach ( array( 'http://client.example.com', 'ftp://client.example.com', 'client.example.com', 'https://user:pass@example.com', 'https://localhost', 'https://127.0.0.1', 'https://192.168.1.10', 'https://10.0.0.5', 'https://[::1]', 'https://site.local', 'https://169.254.169.254' ) as $url ) {
			$this->assertInstanceOf( \WP_Error::class, Guard::check( $url ), $url );
		}

		Functions\when( 'wp_http_validate_url' )->justReturn( false );
		$this->assertInstanceOf( \WP_Error::class, Guard::check( 'https://resolves-to-private.example.com' ) );
	}

	public function test_a_filter_lifts_the_private_address_rules_for_local_testing(): void {
		Filters\expectApplied( 'axle_license_tracker_hub_allow_private' )->andReturn( true );

		$this->assertSame( 'http://localhost:8914/', Guard::check( 'http://localhost:8914' ) );
	}

	public function test_sites_are_added_listed_and_removed_with_their_data(): void {
		Actions\expectDone( 'axle_license_tracker_hub_site_added' )->once();
		Actions\expectDone( 'axle_license_tracker_hub_site_removed' )->once();
		$sites = new Sites();

		$site = $sites->add( 'https://client.example.com', 'reporter', 'abcd efgh', '' );
		$this->assertIsArray( $site );
		$this->assertSame( 'client.example.com', $site['label'] );
		$this->assertArrayNotHasKey( 'secret', $site );
		$this->assertArrayNotHasKey( 'secret', $sites->get( $site['id'] ) );
		$this->assertStringNotContainsString( 'abcd', (string) wp_json_encode( $sites->all() ) );
		$this->assertStringStartsWith( 'v1:', $this->options[ Sites::OPTION ][ $site['id'] ]['secret'] );
		$this->assertSame( 'abcd efgh', $sites->password( $site['id'] ) );

		$sites->save_data( $site['id'], array( 'components' => array() ) );
		$this->assertArrayHasKey( Sites::DATA . $site['id'], $this->options );

		$this->assertTrue( $sites->remove( $site['id'] ) );
		$this->assertSame( array(), $sites->all() );
		$this->assertArrayNotHasKey( Sites::DATA . $site['id'], $this->options );
		$this->assertFalse( $sites->remove( $site['id'] ) );
	}

	public function test_adding_a_site_needs_credentials_and_refuses_duplicates_and_a_full_hub(): void {
		$sites = new Sites();

		$this->assertSame( 'axle_license_tracker_credentials', $sites->add( 'https://a.example.com', '', 'pw' )->get_error_code() );
		$this->assertSame( 'axle_license_tracker_credentials', $sites->add( 'https://a.example.com', 'user', '' )->get_error_code() );
		$this->assertSame( 'axle_license_tracker_url', $sites->add( 'http://a.example.com', 'user', 'pw' )->get_error_code() );

		$this->assertIsArray( $sites->add( 'https://a.example.com', 'user', 'pw' ) );
		$this->assertSame( 'axle_license_tracker_duplicate', $sites->add( 'https://A.example.com/', 'user', 'pw' )->get_error_code() );

		Filters\expectApplied( 'axle_license_tracker_hub_max_sites' )->andReturn( 1 );
		$this->assertSame( 'axle_license_tracker_limit', $sites->add( 'https://b.example.com', 'user', 'pw' )->get_error_code() );
	}

	public function test_a_remote_report_keeps_only_known_fields_cleaned(): void {
		$clean = Remote::sanitize(
			array(
				'name'       => '<b>Client</b>',
				'plugin'     => '1.0.0',
				'components' => array(
					array(
						'id'          => 'plugin:pro/pro.php',
						'name'        => 'Pro <script>x</script>',
						'state'       => 'expired',
						'risk'        => 'nonsense',
						'renewal'     => '2026-12-01',
						'cycle'       => 'yearly',
						'cost'        => 19900,
						'currency'    => 'usd',
						'fingerprint' => '9f86d081884c-AB12',
						'owner_email' => 'secret@example.com',
						'path'        => '/var/www/html/wp-content/plugins/pro',
						'seats'       => '3',
					),
					array(
						'id'      => 'plugin:ignored/ignored.php',
						'ignored' => true,
					),
					array( 'name' => 'No id' ),
					'not an array',
				),
			)
		);

		$this->assertSame( 'Client', $clean['name'] );
		$this->assertCount( 1, $clean['components'] );
		$component = $clean['components'][0];
		$this->assertSame( 'Pro x', $component['name'] );
		$this->assertSame( '', $component['risk'] );
		$this->assertSame( 'USD', $component['currency'] );
		$this->assertSame( 19900, $component['cost'] );
		$this->assertSame( 3, $component['seats'] );
		$this->assertArrayNotHasKey( 'owner_email', $component );
		$this->assertArrayNotHasKey( 'path', $component );

		$this->assertInstanceOf( \WP_Error::class, Remote::sanitize( 'html' ) );
		$this->assertInstanceOf( \WP_Error::class, Remote::sanitize( array( 'components' => 'x' ) ) );
	}

	public function test_a_report_is_cut_to_the_component_limit(): void {
		Filters\expectApplied( 'axle_license_tracker_hub_max_components' )->andReturn( 10 );
		$components = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$components[] = array(
				'id'   => 'plugin:p' . $i . '/p.php',
				'name' => 'P' . $i,
			);
		}

		$this->assertCount( 10, Remote::sanitize( array( 'components' => $components ) )['components'] );
	}

	public function test_a_failing_site_is_retried_later_and_later_and_a_good_one_daily_at_its_own_minute(): void {
		$now = 1000000;
		$this->assertSame( $now + HOUR_IN_SECONDS, Hub::next_time( 'sa', 1, $now ) );
		$this->assertSame( $now + 2 * HOUR_IN_SECONDS, Hub::next_time( 'sa', 2, $now ) );
		$this->assertSame( $now + 4 * HOUR_IN_SECONDS, Hub::next_time( 'sa', 3, $now ) );
		$this->assertSame( $now + DAY_IN_SECONDS, Hub::next_time( 'sa', 9, $now ) );

		$a = Hub::next_time( 'site-a', 0, $now );
		$b = Hub::next_time( 'site-b', 0, $now );
		$this->assertGreaterThanOrEqual( $now + DAY_IN_SECONDS, $a );
		$this->assertLessThan( $now + DAY_IN_SECONDS + 6 * HOUR_IN_SECONDS + 1, $a );
		$this->assertNotSame( $a, $b, 'Staggered.' );
	}

	/**
	 * A hub whose remote client answers with a fixed result.
	 *
	 * @param mixed $answer A cleaned report or a WP_Error.
	 * @param Sites $sites  Sites.
	 * @return Hub
	 */
	private function hub( $answer, Sites $sites ): Hub {
		$remote = Mockery::mock( Remote::class );
		$remote->shouldReceive( 'fetch' )->andReturn( $answer );

		return new Hub( new Settings(), $sites, $remote, Mockery::mock( Tracker::class ) );
	}

	public function test_a_sync_keeps_the_report_and_fires_the_action(): void {
		$sites = new Sites();
		$site  = $sites->add( 'https://client.example.com', 'user', 'pw' );
		Actions\expectDone( 'axle_license_tracker_hub_synced' )->once();

		$hub    = $this->hub(
			array(
				'name'       => 'Client',
				'plugin'     => '1.0.0',
				'generated'  => '2026-11-13T00:00:00+00:00',
				'components' => array( array( 'id' => 'plugin:a/a.php' ) ),
			),
			$sites
		);
		$result = $hub->sync( $site['id'] );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 0, $result['fails'] );
		$this->assertSame( '', $result['error'] );
		$this->assertGreaterThan( time(), $result['next'] );
		$this->assertCount( 1, $sites->data( $site['id'] )['components'] );
	}

	public function test_a_failed_sync_keeps_the_old_data_counts_the_failure_and_says_why(): void {
		$sites = new Sites();
		$site  = $sites->add( 'https://client.example.com', 'user', 'pw' );
		$sites->save_data( $site['id'], array( 'components' => array( array( 'id' => 'plugin:a/a.php' ) ) ) );
		Actions\expectDone( 'axle_license_tracker_hub_sync_failed' )->twice();

		$hub = $this->hub( new \WP_Error( 'x', 'The site refused the login.' ), $sites );
		$hub->sync( $site['id'] );
		$result = $hub->sync( $site['id'] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$after = $sites->get( $site['id'] );
		$this->assertSame( 2, $after['fails'] );
		$this->assertSame( 'The site refused the login.', $after['error'] );
		$this->assertCount( 1, $sites->data( $site['id'] )['components'], 'The last good report stays.' );
		$this->assertSame( 'axle_license_tracker_unknown_site', $hub->sync( 'nope' )->get_error_code() );
	}

	public function test_only_due_sites_are_synced_a_few_at_a_time(): void {
		$sites = new Sites();
		$ids   = array();
		foreach ( array( 'a', 'b', 'c' ) as $letter ) {
			$ids[ $letter ] = $sites->add( 'https://' . $letter . '.example.com', 'user', 'pw' )['id'];
		}
		$sites->update( $ids['c'], array( 'next' => time() + DAY_IN_SECONDS ) );
		$hub = $this->hub( array( 'components' => array() ), $sites );

		$this->assertEqualsCanonicalizing( array( $ids['a'], $ids['b'] ), array_keys( $hub->sync_due() ) );
		$this->assertSame( array(), $hub->sync_due(), 'Nothing is due right after.' );

		Filters\expectApplied( 'axle_license_tracker_hub_batch' )->andReturn( 1 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once();
		$this->assertCount( 1, $hub->sync_due( true ), 'A forced run does one batch and leaves the rest to cron.' );
	}

	public function test_the_hub_is_off_by_default_and_a_filter_can_turn_it_on(): void {
		$hub = $this->hub( array(), new Sites() );
		$this->assertFalse( $hub->enabled() );

		Filters\expectApplied( 'axle_license_tracker_hub_enabled' )->andReturn( true );
		$this->assertTrue( $hub->enabled() );
	}

	public function test_turning_the_hub_on_schedules_the_hourly_event_and_off_clears_it(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), 'hourly', Hub::CRON );
		Actions\expectDone( 'axle_license_tracker_hub_toggled' )->twice();
		$hub = $this->hub( array(), new Sites() );

		$hub->set_enabled( true );
		$this->assertTrue( $this->options[ Settings::OPTION ]['hub'] );

		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Hub::CRON );
		$hub->set_enabled( false );
	}

	/**
	 * Rows for the combined view.
	 *
	 * @return array[]
	 */
	private function rows(): array {
		$entry = static function ( string $id, string $name, string $fingerprint, array $extra = array() ): array {
			return array_merge(
				array(
					'id'          => $id,
					'name'        => $name,
					'vendor'      => 'Acme',
					'state'       => 'valid',
					'renewal'     => '',
					'cycle'       => 'yearly',
					'cost'        => null,
					'currency'    => '',
					'fingerprint' => $fingerprint,
					'seats'       => 0,
				),
				$extra
			);
		};

		return Overview::rows(
			array( $entry( 'plugin:a/a.php', 'Alpha', 'fp-1', array( 'renewal' => '2026-11-20', 'cost' => 10000, 'currency' => 'USD', 'seats' => 2 ) ) ),
			array(
				's1' => array(
					'label'      => 'One',
					'components' => array(
						$entry( 'plugin:a/a.php', 'Alpha', 'fp-1', array( 'cost' => 10000, 'currency' => 'USD', 'seats' => 2 ) ),
						$entry( 'plugin:b/b.php', 'Beta', 'fp-2', array( 'cost' => 500, 'currency' => 'EUR', 'cycle' => 'monthly', 'state' => 'expired' ) ),
						$entry( 'plugin:b2/b2.php', 'Beta add-on', 'fp-2' ),
					),
				),
				's2' => array(
					'label'      => 'Two',
					'components' => array(
						$entry( 'plugin:a/a.php', 'Alpha', 'fp-1', array( 'seats' => 2 ) ),
						$entry( 'plugin:b/b.php', 'Beta', 'fp-2' ),
						$entry( 'plugin:once/once.php', 'Once', 'fp-3', array( 'cycle' => 'lifetime', 'cost' => 9900, 'currency' => 'USD' ) ),
					),
				),
			),
			'Home',
			'2026-11-13'
		);
	}

	public function test_the_same_key_on_more_sites_than_its_plan_allows_is_flagged(): void {
		$dups = Overview::duplicates( $this->rows() );

		$this->assertSame( array( 'fp-1', 'fp-2' ), array_keys( $dups ), 'Over the limit first.' );
		$this->assertSame( 3, $dups['fp-1']['count'] );
		$this->assertSame( 2, $dups['fp-1']['limit'] );
		$this->assertTrue( $dups['fp-1']['over'] );
		$this->assertSame( array( 'Home', 'One', 'Two' ), $dups['fp-1']['sites'] );
		$this->assertSame( 2, $dups['fp-2']['count'], 'Two products on one site count once for that site.' );
		$this->assertSame( 0, $dups['fp-2']['limit'] );
		$this->assertFalse( $dups['fp-2']['over'], 'No known limit: only the count.' );
		$this->assertArrayNotHasKey( 'fp-3', $dups );
	}

	public function test_costs_are_per_currency_and_per_vendor_and_never_converted(): void {
		$costs = Overview::costs( $this->rows() );

		$this->assertSame(
			array(
				'EUR' => 6000,
				'USD' => 20000,
			),
			$costs['currencies'],
			'Monthly counts twelve times, lifetime not at all.'
		);
		$this->assertSame(
			array(
				'EUR' => 6000,
				'USD' => 20000,
			),
			$costs['vendors']['Acme']
		);
	}

	public function test_expiring_lists_expired_and_soon_first(): void {
		$names = array_map(
			static function ( array $row ): string {
				return $row['name'];
			},
			Overview::expiring( $this->rows(), 30 )
		);

		$this->assertSame( array( 'Beta', 'Alpha' ), $names );
	}
}
