<?php
/**
 * Tests for rows, syncing, reminders, the report and the exports.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Abilities;
use AryanX\Axle\LicenseTracker\Bundles;
use AryanX\Axle\LicenseTracker\Classifier;
use AryanX\Axle\LicenseTracker\Details;
use AryanX\Axle\LicenseTracker\Export;
use AryanX\Axle\LicenseTracker\Health\SiteHealth;
use AryanX\Axle\LicenseTracker\Inventory;
use AryanX\Axle\LicenseTracker\Licenses;
use AryanX\Axle\LicenseTracker\Privacy;
use AryanX\Axle\LicenseTracker\Registry;
use AryanX\Axle\LicenseTracker\Reminders;
use AryanX\Axle\LicenseTracker\Report;
use AryanX\Axle\LicenseTracker\Scanner;
use AryanX\Axle\LicenseTracker\Settings;
use AryanX\Axle\LicenseTracker\Store;
use AryanX\Axle\LicenseTracker\Tracker;
use AryanX\Axle\LicenseTracker\Updates;
use AryanX\Axle\LicenseTracker\Vendors;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Tracker, Reminders, Report, Export, Privacy, SiteHealth and the ability helpers.
 */
final class TrackerTest extends LicenseTestCase {

	/**
	 * Installed components for the tracker.
	 *
	 * @var array<string, array>
	 */
	private $components = array();

	/**
	 * Per-component records.
	 *
	 * @var Licenses
	 */
	private $licenses;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Detection store.
	 *
	 * @var Store
	 */
	private $store;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_get_theme' )->justReturn( null );

		// An EDD plugin with a valid key, an EDD plugin with an expired one, and a directory plugin.
		foreach ( array( 'good/good.php', 'lapsed/lapsed.php', 'hello.php' ) as $file ) {
			$component                            = $this->plugin( $file );
			$this->components[ $component['id'] ] = $component;
		}
		$this->options['good_license_key']      = 'GOOD-KEY-1234567890';
		$this->options['good_license_status']   = 'valid';
		$this->options['lapsed_license_key']    = 'LAPSED-KEY-0987654321';
		$this->options['lapsed_license_status'] = 'expired';
		$this->site_transients['update_plugins'] = (object) array(
			'last_checked' => time(),
			'no_update'    => array( 'hello.php' => (object) array( 'id' => 'w.org/plugins/hello-dolly' ) ),
		);

		$this->licenses = new Licenses();
		$this->settings = new Settings();
		$this->store    = new Store();
	}

	/**
	 * A tracker over the test components.
	 *
	 * @return Tracker
	 */
	private function tracker(): Tracker {
		$inventory = Mockery::mock( Inventory::class );
		$inventory->shouldReceive( 'all' )->andReturn( $this->components );
		$inventory->shouldReceive( 'flush' );
		$bundles = Mockery::mock( Bundles::class );
		$bundles->shouldReceive( 'plugins' )->andReturn( array() );
		$bundles->shouldReceive( 'theme_name' )->andReturn( '' );

		$updates  = new Updates( $this->store );
		$scanner  = new Scanner( $this->store, $inventory );
		$registry = new Registry();
		$vendors  = new Vendors();

		return new Tracker( $inventory, $this->store, $updates, $scanner, new Classifier( $updates, $scanner, $vendors, $registry ), $registry, $vendors, $this->licenses, $bundles, $this->settings );
	}

	public function test_rows_merge_detection_and_manual_details(): void {
		$tracker = $this->tracker();
		$rows    = $tracker->rows();

		$this->assertSame( array( 'premium', 'valid', 'ok' ), array( $rows['plugin:good/good.php']['type'], $rows['plugin:good/good.php']['state'], $rows['plugin:good/good.php']['risk'] ) );
		$this->assertSame( 'expired', $rows['plugin:lapsed/lapsed.php']['state'] );
		$this->assertSame( array( 'directory', 'not-applicable', false ), array( $rows['plugin:hello.php']['type'], $rows['plugin:hello.php']['state'], $rows['plugin:hello.php']['tracked'] ) );
		$this->assertCount( 2, $tracker->tracked() );
		$this->assertStringEndsWith( '-7890', $rows['plugin:good/good.php']['fingerprint'] );

		// A renewal date entered by hand inside the reminder window makes it expiring.
		( new Details( $this->licenses ) )->save( $rows['plugin:good/good.php'], array( 'renewal' => gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ), 'vendor' => 'Good Co' ), 1 );
		$tracker->flush();
		$row = $tracker->row( 'plugin:good/good.php' );
		$this->assertSame( 'expiring', $row['state'] );
		$this->assertSame( 10, $row['days_left'] );
		$this->assertSame( array( 'vendor' => 'manual', 'renewal' => 'manual' ), $row['sources'] );
		$this->assertSame( 'Good Co', $row['vendor'] );
	}

	public function test_state_vendor_url_and_reminder_day_filters(): void {
		Filters\expectApplied( 'axle_license_tracker_license_state' )->andReturnUsing(
			static function ( $state, $component ) {
				return 'good' === $component['slug'] ? 'invalid' : ( 'lapsed' === $component['slug'] ? 'bogus' : $state );
			}
		);
		Filters\expectApplied( 'axle_license_tracker_vendor_license_url' )->andReturn( 'https://licenses.example.com/' );
		Filters\expectApplied( 'axle_license_tracker_reminder_days' )->andReturn( array( 3, 'x', 900 ) );

		$tracker = $this->tracker();
		$this->assertSame( 'invalid', $tracker->row( 'plugin:good/good.php' )['state'] );
		$this->assertSame( 'expired', $tracker->row( 'plugin:lapsed/lapsed.php' )['state'] );
		$this->assertSame( 'https://licenses.example.com/', $tracker->row( 'plugin:good/good.php' )['license_url'] );
		$this->assertSame( array( 3 ), $tracker->reminder_days() );
	}

	public function test_sync_saves_state_and_fires_each_change_once(): void {
		Actions\expectDone( 'axle_license_tracker_state_changed' )->twice();
		Actions\expectDone( 'axle_license_tracker_license_expired' )->once()->with( Mockery::on( static function ( $row ) {
			return 'plugin:lapsed/lapsed.php' === $row['id'];
		} ) );
		Actions\expectDone( 'axle_license_tracker_risk_changed' )->twice();
		Actions\expectDone( 'axle_license_tracker_detected' )->twice();

		$tracker = $this->tracker();
		$tracker->sync();
		$this->assertSame( 'expired', $this->licenses->meta( 'plugin:lapsed/lapsed.php', '_alc_state' ) );
		$this->assertSame( 'ok', $this->licenses->meta( 'plugin:good/good.php', '_alc_risk' ) );
		$this->assertSame( 0, $this->licenses->find( 'plugin:hello.php' ) );

		// Nothing changed: only the detected action again.
		$tracker->flush();
		$tracker->sync();
	}

	public function test_ignore_and_totals(): void {
		$tracker = $this->tracker();
		Actions\expectDone( 'axle_license_tracker_ignored' )->once()->with( 'plugin:lapsed/lapsed.php', true, 'Retired', 1 );
		$tracker->ignore( 'plugin:lapsed/lapsed.php', true, 'Retired', 1 );

		$this->assertTrue( $tracker->row( 'plugin:lapsed/lapsed.php' )['ignored'] );
		$totals = Tracker::totals( $tracker->rows() );
		$this->assertSame( 1, $totals['premium'] );
		$this->assertSame( 0, $totals['expired'] );

		$rows = array(
			array( 'tracked' => true, 'ignored' => false, 'type' => 'premium', 'state' => 'valid', 'risk' => 'blocked', 'cost' => 1000, 'currency' => 'USD', 'cycle' => 'monthly' ),
			array( 'tracked' => true, 'ignored' => false, 'type' => 'premium', 'state' => 'missing', 'risk' => 'at-risk', 'cost' => 5000, 'currency' => 'EUR', 'cycle' => 'yearly' ),
			array( 'tracked' => true, 'ignored' => false, 'type' => 'bundled', 'state' => 'expiring', 'risk' => 'watch', 'cost' => 9900, 'currency' => 'USD', 'cycle' => 'lifetime' ),
		);
		$this->assertSame(
			array(
				'premium'  => 3,
				'expired'  => 0,
				'expiring' => 1,
				'at_risk'  => 2,
				'blocked'  => 1,
				'missing'  => 1,
				'costs'    => array( 'EUR' => 5000, 'USD' => 12000 ),
			),
			Tracker::totals( $rows )
		);
	}

	public function test_reminders_go_out_once_per_threshold_with_every_hook(): void {
		$tracker = $this->tracker();
		$good    = $tracker->row( 'plugin:good/good.php' );
		( new Details( $this->licenses ) )->save( $good, array( 'renewal' => gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ), 'owner_email' => 'owner@example.com', 'cost' => '10', 'currency' => 'USD' ), 1 );
		$this->options['admin_email'] = 'admin@example.com';
		$tracker->flush();
		$tracker->sync();

		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject ) use ( &$sent ) {
				$sent[] = array( $to, $subject );
				return true;
			}
		);
		Filters\expectApplied( 'axle_license_tracker_reminder_recipients' )->andReturnUsing(
			static function ( array $list ) {
				$list[] = 'not an email';
				return $list;
			}
		);
		Filters\expectApplied( 'axle_license_tracker_reminder_email' )->andReturnUsing(
			static function ( array $email, $row, $threshold, $kind ) {
				$email['subject'] = strtoupper( $kind ) . ' ' . $threshold . ': ' . $email['subject'];
				return $email;
			}
		);
		Actions\expectDone( 'axle_license_tracker_license_expiring' )->once()->with( Mockery::type( 'array' ), 7 );
		Actions\expectDone( 'axle_license_tracker_reminder_sent' )->twice();

		$reminders = new Reminders( $tracker, $this->licenses, $this->settings, $this->store );
		$this->assertCount( 2, $reminders->run( true ) );
		$this->assertSame( array(), $sent );

		$reminders->run( false );
		$this->assertSame(
			array(
				array( array( 'admin@example.com', 'owner@example.com' ), 'RENEWAL 7: [Example] Test good/good.php renews in 7 days' ),
				array( array( 'admin@example.com' ), 'ALERT expired: [Example] Test lapsed/lapsed.php: Expired' ),
			),
			$sent
		);
		$this->assertContains( 'renewal:' . gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) . ':30', $this->licenses->meta( 'plugin:good/good.php', '_alc_reminders_sent' ) );

		// The second run sends nothing.
		$this->assertSame( array(), $reminders->run( false ) );
		$this->assertCount( 2, $sent );
	}

	public function test_digest_once_a_month_when_enabled(): void {
		$tracker   = $this->tracker();
		$reminders = new Reminders( $tracker, $this->licenses, $this->settings, $this->store );
		$this->settings->update( array( 'alerts' => false ) );
		Functions\when( 'wp_mail' )->justReturn( true );
		$this->assertSame( array(), $reminders->run( false ) );

		Filters\expectApplied( 'axle_license_tracker_digest_enabled' )->andReturn( true );
		$planned = $reminders->run( false );
		$this->assertSame( 'digest', $planned[0]['kind'] );
		$this->assertStringContainsString( '2 premium components: 1 expired', $planned[0]['body'] );
		$this->assertSame( gmdate( 'Y-m' ), $this->store->get( 'digest' ) );
		$this->assertSame( array(), $reminders->run( false ) );
	}

	public function test_report_export_and_expiring_list_never_hold_keys(): void {
		$tracker = $this->tracker();
		( new Details( $this->licenses ) )->save( $tracker->row( 'plugin:good/good.php' ), array( 'owner_email' => 'owner@example.com', 'notes' => '=HYPERLINK("x")', 'cost' => '9.5', 'currency' => 'USD', 'renewal' => gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ), 1 );
		$tracker->flush();
		$report = new Report( $tracker );

		Filters\expectApplied( 'axle_license_tracker_report' )->andReturnUsing(
			static function ( array $data, bool $full ) {
				$data['full'] = $full;
				return $data;
			}
		);
		$public = $report->build();
		$this->assertFalse( $public['full'] );
		$this->assertCount( 2, $public['components'] );
		$this->assertArrayNotHasKey( 'owner_email', $public['components'][0] );
		$json = (string) wp_json_encode( $public );
		$this->assertStringNotContainsString( 'GOOD-KEY', $json );
		$this->assertStringNotContainsString( 'owner@example.com', $json );
		$this->assertCount( 3, $report->build( true )['components'] );

		$csv = ( new Export( $report ) )->render( 'csv' );
		$this->assertStringContainsString( '"owner@example.com"', $csv );
		$this->assertStringContainsString( '"9.50"', $csv );
		$this->assertStringContainsString( '"\'=HYPERLINK(""x"")"', $csv );
		$this->assertStringNotContainsString( 'GOOD-KEY', $csv . ( new Export( $report ) )->render( 'json' ) );

		$expiring = Abilities::expiring( $tracker->tracked(), 30 );
		$this->assertSame( array( 'plugin:lapsed/lapsed.php', 'plugin:good/good.php' ), array_column( $expiring, 'id' ) );
		$this->assertSame( array( 'plugin:lapsed/lapsed.php' ), array_column( Abilities::expiring( $tracker->tracked(), 2 ), 'id' ) );
	}

	public function test_privacy_export_and_erase(): void {
		$tracker = $this->tracker();
		( new Details( $this->licenses ) )->save( $tracker->row( 'plugin:good/good.php' ), array( 'account_email' => 'Buyer@Example.com', 'owner' => 'Buyer' ), 1 );
		Functions\when( 'get_user_by' )->justReturn( false );
		$privacy = new Privacy( $this->licenses );

		$this->assertArrayHasKey( 'axle-license-tracker', $privacy->add_exporter( array() ) );
		$this->assertArrayHasKey( 'axle-license-tracker', $privacy->add_eraser( array() ) );
		$export = $privacy->export( 'buyer@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'Buyer@Example.com', $export['data'][0]['data'][1]['value'] );
		$this->assertSame( array(), $privacy->export( 'someone@example.com' )['data'] );

		$this->assertTrue( $privacy->erase( 'buyer@example.com' )['items_removed'] );
		$this->assertSame( '', $this->licenses->meta( 'plugin:good/good.php', '_alc_account_email' ) );
		$this->assertSame( 'Buyer', $this->licenses->meta( 'plugin:good/good.php', '_alc_owner' ) );
	}

	public function test_site_health(): void {
		Functions\when( 'esc_html__' )->returnArg();
		$health = new SiteHealth( $this->tracker() );
		$this->assertArrayHasKey( 'axle_license_tracker', $health->add_test( array() )['direct'] );

		$result = $health->run();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'License expired: Test lapsed/lapsed.php', $result['description'] );

		unset( $this->components['plugin:lapsed/lapsed.php'] );
		$this->assertSame( 'good', ( new SiteHealth( $this->tracker() ) )->run()['status'] );
	}
}
