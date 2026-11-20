<?php
/**
 * Tests for updates around a renewal, the blocked-update message and the site date format.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Admin\PluginsScreen;
use AryanX\Axle\LicenseTracker\Bundles;
use AryanX\Axle\LicenseTracker\Classifier;
use AryanX\Axle\LicenseTracker\Details;
use AryanX\Axle\LicenseTracker\Forecast;
use AryanX\Axle\LicenseTracker\Format;
use AryanX\Axle\LicenseTracker\Inventory;
use AryanX\Axle\LicenseTracker\Licenses;
use AryanX\Axle\LicenseTracker\Registry;
use AryanX\Axle\LicenseTracker\Reminders;
use AryanX\Axle\LicenseTracker\Renewal;
use AryanX\Axle\LicenseTracker\Scanner;
use AryanX\Axle\LicenseTracker\Settings;
use AryanX\Axle\LicenseTracker\Store;
use AryanX\Axle\LicenseTracker\Tracker;
use AryanX\Axle\LicenseTracker\UpdateLinks;
use AryanX\Axle\LicenseTracker\Updates;
use AryanX\Axle\LicenseTracker\Vendors;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Renewal, UpdateLinks, Format and the Plugins screen's blocked message.
 */
final class RenewalTest extends LicenseTestCase {

	/**
	 * Installed components.
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
		Functions\when( 'self_admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url ) {
				return $url . '&_wpnonce=abc';
			}
		);
		Functions\when( 'is_main_site' )->justReturn( true );
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp = null ) {
				return gmdate( $format, (int) $timestamp );
			}
		);

		foreach ( array( 'good/good.php', 'lapsed/lapsed.php' ) as $file ) {
			$component                            = $this->plugin( $file, array( 'version' => '2.6.1' ) );
			$this->components[ $component['id'] ] = $component;
		}
		$this->options['date_format']           = 'j F Y';
		$this->options['admin_email']           = 'admin@example.com';
		$this->options['good_license_key']      = 'GOOD-KEY-1234567890';
		$this->options['good_license_status']   = 'valid';
		$this->options['lapsed_license_key']    = 'LAPSED-KEY-0987654321';
		$this->options['lapsed_license_status'] = 'expired';

		$this->licenses = new Licenses();
		$this->settings = new Settings();
		$this->store    = new Store();
	}

	/**
	 * Puts update entries in the transient.
	 *
	 * @param array<string, string> $packages Plugin file => package URL ('' for none). Every entry offers 2.9.4.
	 */
	private function offer( array $packages ): void {
		$response = array();
		foreach ( $packages as $file => $package ) {
			$response[ $file ] = (object) array(
				'id'          => 'vendor.example/' . $file,
				'new_version' => '2.9.4',
				'package'     => $package,
				'url'         => 'https://vendor.example/',
			);
		}
		$this->site_transients['update_plugins'] = (object) array(
			'last_checked' => time(),
			'response'     => $response,
			'no_update'    => array(),
		);
	}

	/**
	 * A tracker over the test components.
	 *
	 * @return Tracker
	 */
	private function tracker(): Tracker {
		$inventory = Mockery::mock( Inventory::class );
		$inventory->shouldReceive( 'all' )->andReturnUsing(
			function () {
				return $this->components;
			}
		);
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

	/**
	 * Gives the valid plugin a renewal date.
	 *
	 * @param Tracker $tracker Tracker.
	 * @param int     $days    Days from today.
	 */
	private function renews_in( Tracker $tracker, int $days ): void {
		$date = gmdate( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
		( new Details( $this->licenses ) )->save( $tracker->row( 'plugin:good/good.php' ), array( 'renewal' => $date ), 1 );
		$tracker->flush();
	}

	public function test_dates_use_the_site_format_and_keep_the_day(): void {
		$this->assertSame( '23 November 2026', Format::date( '2026-11-23' ) );
		$this->options['date_format'] = 'Y/m/d';
		$this->assertSame( '2026/11/23', Format::date( '2026-11-23' ) );
		$this->assertSame( 'soon', Format::date( 'soon' ) );
	}

	public function test_today_follows_the_site_time_zone(): void {
		Functions\when( 'current_time' )->alias(
			static function ( $type ) {
				return 'Y-m-d' === $type ? '2026-11-24' : time();
			}
		);
		$this->assertSame( '2026-11-24', Forecast::today() );
	}

	public function test_newer_version_is_downloadable_only_with_a_package_and_a_working_license(): void {
		$this->offer(
			array(
				'good/good.php'     => 'https://vendor.example/good.zip',
				'lapsed/lapsed.php' => 'https://vendor.example/lapsed.zip',
			)
		);
		$tracker = $this->tracker();
		$renewal = new Renewal( $tracker, new Updates( $this->store ), $this->licenses );

		$good = $renewal->newer( $tracker->row( 'plugin:good/good.php' ) );
		$this->assertSame( array( '2.6.1', '2.9.4', true ), array( $good['from'], $good['to'], $good['downloadable'] ) );
		// The vendor still lists a package, but the stored state says expired.
		$this->assertFalse( $renewal->newer( $tracker->row( 'plugin:lapsed/lapsed.php' ) )['downloadable'] );

		$this->offer( array( 'good/good.php' => '' ) );
		$tracker->flush();
		$this->assertFalse( $renewal->newer( $tracker->row( 'plugin:good/good.php' ) )['downloadable'] );

		$this->components['plugin:good/good.php']['version'] = '2.9.4';
		$tracker->flush();
		$this->assertNull( $renewal->newer( $tracker->row( 'plugin:good/good.php' ) ) );
	}

	public function test_the_14_7_and_1_day_reminders_say_take_it_now_and_the_30_day_one_does_not(): void {
		$this->offer( array( 'good/good.php' => 'https://vendor.example/good.zip' ) );
		$tracker = $this->tracker();
		$this->renews_in( $tracker, 7 );
		$renewal   = new Renewal( $tracker, new Updates( $this->store ), $this->licenses );
		$reminders = new Reminders( $tracker, $this->licenses, $this->settings, $this->store, $renewal );
		$this->settings->update( array( 'alerts' => false ) );

		$planned = $reminders->run( true );
		$this->assertCount( 1, $planned );
		$date = ( new \DateTimeImmutable( '@' . ( time() + 7 * DAY_IN_SECONDS ) ) )->format( 'j F Y' );
		$this->assertStringContainsString( 'renews on ' . $date . '.', $planned[0]['body'] );
		$this->assertStringContainsString( 'Test good/good.php 2.9.4 is available now and will not be after ' . $date . ': rehearse it, then update.', $planned[0]['body'] );
		$this->assertStringNotContainsString( gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ), $planned[0]['body'] );

		$this->licenses->set_meta( 'plugin:good/good.php', '_alc_reminders_sent', array() );
		$this->renews_in( $tracker, 25 );
		$planned = $reminders->run( true );
		$this->assertCount( 1, $planned );
		$this->assertStringNotContainsString( 'is available now', $planned[0]['body'] );
	}

	public function test_no_take_it_now_line_when_the_package_is_missing(): void {
		$this->offer( array( 'good/good.php' => '' ) );
		$tracker = $this->tracker();
		$this->renews_in( $tracker, 7 );
		$renewal = new Renewal( $tracker, new Updates( $this->store ), $this->licenses );

		$this->assertSame( '', $renewal->last_chance_line( $tracker->row( 'plugin:good/good.php' ) ) );
	}

	public function test_each_person_gets_the_link_they_can_use(): void {
		$this->offer( array( 'good/good.php' => 'https://vendor.example/good.zip' ) );
		$tracker = $this->tracker();
		$this->renews_in( $tracker, 7 );
		$this->settings->update( array( 'alerts' => false, 'recipients' => array( 'dev@example.com', 'client@example.com' ) ) );
		Functions\when( 'get_user_by' )->alias(
			static function ( $field, $email ) {
				$ids = array(
					'admin@example.com' => 1,
					'dev@example.com'   => 2,
				);
				return isset( $ids[ $email ] ) ? (object) array( 'ID' => $ids[ $email ] ) : false;
			}
		);
		// Admin can rehearse, the developer can only update, the client has no account.
		Functions\when( 'user_can' )->alias(
			static function ( $user, $cap ) {
				return 1 === $user || ( 2 === $user && 'update_plugins' === $cap );
			}
		);
		Filters\expectApplied( 'axle_license_tracker_rehearsal_active' )->andReturn( true );
		Filters\expectApplied( 'update_rehearsal_capability' )->andReturn( 'manage_options' );

		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body ) use ( &$sent ) {
				$sent[ $to ] = $body;
				return true;
			}
		);
		$renewal = new Renewal( $tracker, new Updates( $this->store ), $this->licenses );
		( new Reminders( $tracker, $this->licenses, $this->settings, $this->store, $renewal ) )->run( false );

		$this->assertSame( array( 'admin@example.com', 'dev@example.com', 'client@example.com' ), array_keys( $sent ) );
		$this->assertStringContainsString( 'Rehearse the update: https://example.com/wp-admin/tools.php?page=update-rehearsal&kind=update&plugin=good%2Fgood.php', $sent['admin@example.com'] );
		$this->assertStringContainsString( 'Update: https://example.com/wp-admin/update-core.php', $sent['dev@example.com'] );
		$this->assertStringNotContainsString( 'update-rehearsal', $sent['dev@example.com'] );
		$this->assertStringNotContainsString( 'wp-admin/update', $sent['client@example.com'] );
		$this->assertStringNotContainsString( 'update-rehearsal', $sent['client@example.com'] );
		$this->assertStringContainsString( 'will not be after', $sent['client@example.com'] );
	}

	public function test_update_links_for_a_screen(): void {
		$tracker = $this->tracker();
		$row     = $tracker->row( 'plugin:good/good.php' );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->justReturn( true );

		// Without Update Rehearsal: a plain update link carrying a nonce.
		$link = UpdateLinks::for_row( $row );
		$this->assertSame( 'update', $link['kind'] );
		$this->assertStringContainsString( 'action=upgrade-plugin&plugin=good%2Fgood.php', $link['url'] );
		$this->assertStringContainsString( '_wpnonce', $link['url'] );

		// Someone who cannot update gets nothing.
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertNull( UpdateLinks::for_row( $row ) );
		$this->assertNull( UpdateLinks::for_row( $row, 0 ) );
	}

	public function test_expired_to_valid_with_a_gap_makes_a_record_and_one_action(): void {
		$this->offer( array( 'lapsed/lapsed.php' => 'https://vendor.example/lapsed.zip' ) );
		$tracker = $this->tracker();
		$renewal = new Renewal( $tracker, new Updates( $this->store ), $this->licenses );
		Actions\expectDone( 'axle_license_tracker_renewed' )->once()->with( Mockery::type( 'array' ), '2.6.1', '2.9.4' );
		$renewal->register();
		$this->assertNotFalse( has_action( 'axle_license_tracker_state_changed', array( $renewal, 'state_changed' ) ) );

		$tracker->sync();
		$id = 'plugin:lapsed/lapsed.php';
		$this->assertNull( $this->licenses->meta( $id, Renewal::RECORD, null ) );

		// The vendor stored a renewed license.
		$this->options['lapsed_license_status'] = 'valid';
		$tracker->flush();
		$row = $tracker->row( $id );
		$renewal->state_changed( $row, 'expired', 'valid' );
		$this->assertIsArray( $this->licenses->meta( $id, Renewal::RECORD, null ) );
		$renewal->detected( $tracker->rows() );
		$renewal->detected( $tracker->rows() );
		$this->assertSame( '2.9.4', $this->licenses->meta( $id, Renewal::RECORD )['sent'] );
	}

	public function test_a_first_sight_or_a_valid_to_expired_change_starts_nothing(): void {
		$tracker = $this->tracker();
		$renewal = new Renewal( $tracker, new Updates( $this->store ), $this->licenses );
		$row     = $tracker->row( 'plugin:good/good.php' );
		$renewal->state_changed( $row, '', 'valid' );
		$renewal->state_changed( $row, 'valid', 'expired' );
		$renewal->state_changed( $row, 'expired', 'invalid' );
		$this->assertNull( $this->licenses->meta( 'plugin:good/good.php', Renewal::RECORD, null ) );
	}

	public function test_the_renewed_email_goes_out_through_its_filter_and_respects_alerts(): void {
		$tracker = $this->tracker();
		$row     = $tracker->row( 'plugin:good/good.php' );
		Functions\when( 'get_user_by' )->justReturn( false );
		$mails = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body ) use ( &$mails ) {
				$mails[] = array( $to, $subject, $body );
				return true;
			}
		);
		Filters\expectApplied( 'axle_license_tracker_renewed_email' )->once()->andReturnUsing(
			static function ( array $email ) {
				$email['subject'] .= ' (filtered)';
				return $email;
			}
		);
		$reminders = new Reminders( $tracker, $this->licenses, $this->settings, $this->store );

		$this->settings->update( array( 'alerts' => false ) );
		$reminders->send_renewed( $row, '2.6.1', '2.9.4' );
		$this->assertSame( array(), $mails );

		$this->settings->update( array( 'alerts' => true ) );
		$reminders->send_renewed( $row, '2.6.1', '2.9.4' );
		$this->assertCount( 1, $mails );
		$this->assertSame( '[Example] Test good/good.php renewed: rehearse the update first (filtered)', $mails[0][1] );
		$this->assertStringContainsString( 'Renewed. Rehearse the jump from 2.6.1 to 2.9.4 first, then update.', $mails[0][2] );
	}

	public function test_blocked_message_says_why_and_hides_the_owner_from_people_who_cannot_manage(): void {
		$this->offer( array( 'lapsed/lapsed.php' => '' ) );
		$tracker = $this->tracker();
		$id      = 'plugin:lapsed/lapsed.php';
		( new Details( $this->licenses ) )->save( $tracker->row( $id ), array( 'renewal' => '2026-10-03', 'owner' => 'Priya', 'account_url' => 'https://vendor.example/account' ), 1 );
		$tracker->flush();
		$screen   = new PluginsScreen( $tracker );
		$response = (object) array( 'package' => '' );

		Functions\when( 'current_user_can' )->justReturn( true );
		$text = $screen->blocked_message( $id, $response );
		$this->assertStringContainsString( 'Blocked: the Test lapsed/lapsed.php license expired on 3 October 2026.', $text );
		$this->assertStringContainsString( 'href="https://vendor.example/account"', $text );
		$this->assertStringContainsString( 'Owner: Priya.', $text );

		Functions\when( 'current_user_can' )->justReturn( false );
		$text = $screen->blocked_message( $id, $response );
		$this->assertStringContainsString( 'Blocked: the Test lapsed/lapsed.php license expired on 3 October 2026.', $text );
		$this->assertStringNotContainsString( 'Priya', $text );
		$this->assertStringNotContainsString( 'vendor.example', $text );

		// An update with a download is not blocked.
		$this->assertSame( '', $screen->blocked_message( $id, (object) array( 'package' => 'https://vendor.example/x.zip' ) ) );
		$this->assertSame( '', $screen->blocked_message( 'plugin:nope/nope.php', $response ) );
	}
}
