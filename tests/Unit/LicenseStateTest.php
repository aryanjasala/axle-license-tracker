<?php
/**
 * Tests for the license state value object and fingerprints.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Adapters\LicenseState;
use AryanX\Axle\LicenseTracker\Fingerprint;

/**
 * Covers LicenseState and Fingerprint.
 */
final class LicenseStateTest extends LicenseTestCase {

	public function test_dates_from_every_format(): void {
		$this->assertSame( '2027-01-31', LicenseState::date( '2027-01-31 23:59:59' ) );
		$this->assertSame( '2027-01-31', LicenseState::date( gmmktime( 12, 0, 0, 1, 31, 2027 ) ) );
		$this->assertSame( '2027-01-31', LicenseState::date( (string) gmmktime( 12, 0, 0, 1, 31, 2027 ) ) );
		$this->assertSame( '2027-01-31', LicenseState::date( 'January 31, 2027' ) );
		$this->assertSame( 'lifetime', LicenseState::date( 'Lifetime' ) );
		$this->assertSame( '', LicenseState::date( '' ) );
		$this->assertSame( '', LicenseState::date( 'not a date at all' ) );
		$this->assertSame( '', LicenseState::date( null ) );
	}

	public function test_a_valid_license_past_its_date_is_expired(): void {
		$past = new LicenseState(
			array(
				'state'   => 'valid',
				'expires' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 'expired', $past->state );

		$lifetime = new LicenseState(
			array(
				'state'   => 'valid',
				'expires' => 'lifetime',
			)
		);
		$this->assertSame( 'valid', $lifetime->state );
		$this->assertSame( 'unknown', ( new LicenseState( array( 'state' => 'great' ) ) )->state );
	}

	public function test_the_key_is_only_kept_as_a_fingerprint(): void {
		$state = new LicenseState(
			array(
				'state'      => 'valid',
				'key'        => 'SECRET-KEY-1234567890',
				'auto_renew' => true,
			)
		);
		$array = $state->to_array();

		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $array ) );
		$this->assertSame( Fingerprint::of( 'SECRET-KEY-1234567890' ), $array['fingerprint'] );
		$this->assertSame( 'yes', $array['auto_renew'] );
	}

	public function test_fingerprint_shape(): void {
		$fingerprint = Fingerprint::of( 'ABCDEFGHIJKLMNOP1234' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}-1234$/', $fingerprint );
		$this->assertSame( substr( hash( 'sha256', 'ABCDEFGHIJKLMNOP1234' ), 0, 12 ) . '-1234', $fingerprint );
		$this->assertTrue( Fingerprint::is_valid( $fingerprint ) );
		$this->assertSame( '', Fingerprint::of( '  ' ) );
		// Short keys show no tail at all.
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', Fingerprint::of( 'short' ) );
	}
}
