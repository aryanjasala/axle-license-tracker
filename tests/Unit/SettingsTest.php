<?php
/**
 * Tests for settings, capabilities and messages.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Capability;
use AryanX\Axle\LicenseTracker\Messages;
use AryanX\Axle\LicenseTracker\Settings;
use Brain\Monkey\Filters;

/**
 * Covers Settings, Capability and Messages.
 */
final class SettingsTest extends LicenseTestCase {

	public function test_defaults(): void {
		$this->assertSame(
			array(
				'reminder_days' => array( 30, 14, 7, 1 ),
				'recipients'    => array(),
				'alerts'        => true,
				'digest'        => false,
			),
			( new Settings() )->all()
		);
	}

	public function test_sanitize_cleans_days_and_recipients(): void {
		$clean = Settings::sanitize(
			array(
				'reminder_days' => '7, 30, nope, 400, 7, -1',
				'recipients'    => 'a@example.com, not-an-email,b@example.com a@example.com',
				'alerts'        => 0,
				'digest'        => '1',
			)
		);

		$this->assertSame( array( 30, 7 ), $clean['reminder_days'] );
		$this->assertSame( array( 'a@example.com', 'b@example.com' ), $clean['recipients'] );
		$this->assertFalse( $clean['alerts'] );
		$this->assertTrue( $clean['digest'] );
		$this->assertSame( Settings::defaults(), Settings::sanitize( 'garbage' ) );
		$this->assertSame( array( 30, 14, 7, 1 ), Settings::sanitize( array( 'reminder_days' => 'none' ) )['reminder_days'] );
	}

	public function test_update_merges_and_saves(): void {
		$settings = new Settings();
		$settings->update( array( 'digest' => true ) );

		$this->assertTrue( $this->options[ Settings::OPTION ]['digest'] );
		$this->assertSame( array( 30, 14, 7, 1 ), $settings->get( 'reminder_days' ) );
	}

	public function test_capability_filters_fall_back_on_empty(): void {
		$this->assertSame( 'manage_options', Capability::manage() );
		$this->assertSame( 'axle_license_tracker_read_report', Capability::report() );

		Filters\expectApplied( 'axle_license_tracker_capability' )->once()->with( 'manage_options' )->andReturn( '  ' );
		$this->assertSame( 'manage_options', Capability::manage() );

		Filters\expectApplied( 'axle_license_tracker_report_capability' )->once()->andReturn( 'read_licenses' );
		$this->assertSame( 'read_licenses', Capability::report() );
	}

	public function test_messages_filter_keeps_only_known_non_empty_strings(): void {
		Filters\expectApplied( 'axle_license_tracker_messages' )->once()->andReturnUsing(
			static function ( array $messages ): array {
				$messages['saved']       = 'Stored.';
				$messages['state_valid'] = '';
				$messages['made_up']     = 'x';
				return $messages;
			}
		);

		$this->assertSame( 'Stored.', Messages::get( 'saved' ) );
		$this->assertSame( 'Valid', Messages::get( 'state_valid' ) );
		$this->assertArrayNotHasKey( 'made_up', Messages::all() );
		$this->assertSame( 'no_such_key', Messages::get( 'no_such_key' ) );
	}
}
