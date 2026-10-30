<?php
/**
 * Tests for manual details and money.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Details;
use AryanX\Axle\LicenseTracker\Licenses;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Details.
 */
final class DetailsTest extends LicenseTestCase {

	public function test_money_is_kept_in_minor_units_without_floats(): void {
		$this->assertSame( 19900, Details::to_minor( '199', 'USD' ) );
		$this->assertSame( 19950, Details::to_minor( '199.5', 'usd' ) );
		$this->assertSame( 129900, Details::to_minor( '1,299.00', 'EUR' ) );
		$this->assertSame( 5000, Details::to_minor( '5000', 'JPY' ) );
		$this->assertNull( Details::to_minor( '5000.5', 'JPY' ) );
		$this->assertNull( Details::to_minor( '19.999', 'USD' ) );
		$this->assertNull( Details::to_minor( '-5', 'USD' ) );
		$this->assertNull( Details::to_minor( 'lots', 'USD' ) );

		$this->assertSame( '199.00', Details::from_minor( 19900, 'USD' ) );
		$this->assertSame( '0.05', Details::from_minor( 5, 'USD' ) );
		$this->assertSame( '5000', Details::from_minor( 5000, 'JPY' ) );

		$this->assertSame( 2400, Details::yearly( 200, 'monthly' ) );
		$this->assertSame( 200, Details::yearly( 200, 'yearly' ) );
		$this->assertNull( Details::yearly( 200, 'lifetime' ) );
		$this->assertNull( Details::yearly( 200, 'one-off' ) );
	}

	public function test_validate_cleans_good_input(): void {
		$result = Details::validate(
			array(
				'renewal'         => '2027-02-28',
				'cost'            => '59.00',
				'currency'        => 'usd',
				'cycle'           => 'yearly',
				'owner_email'     => 'owner@example.com',
				'account_url'     => 'https://vendor.example.com/account',
				'seats'           => '3',
				'auto_renew'      => 'no',
				'purchase_source' => 'gpl-club',
				'notes'           => '',
				'unknown'         => 'ignored',
			)
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array(
				'purchase_source' => 'gpl-club',
				'owner_email'     => 'owner@example.com',
				'renewal'         => '2027-02-28',
				'cycle'           => 'yearly',
				'cost'            => 5900,
				'currency'        => 'USD',
				'auto_renew'      => 'no',
				'seats'           => 3,
				'account_url'     => 'https://vendor.example.com/account',
				'notes'           => '',
			),
			$result['clean']
		);
	}

	public function test_validate_reports_each_bad_field(): void {
		$result = Details::validate(
			array(
				'renewal'         => '2027-02-30',
				'cost'            => '12',
				'cycle'           => 'weekly',
				'owner_email'     => 'nope',
				'invoice_url'     => 'javascript:alert(1)',
				'seats'           => '2.5',
				'auto_renew'      => 'maybe',
				'currency'        => 'dollars',
				'purchase_source' => 'friend',
			)
		);

		$this->assertSame( array( 'purchase_source', 'owner_email', 'renewal', 'cycle', 'currency', 'auto_renew', 'seats', 'invoice_url' ), array_keys( $result['errors'] ) );
	}

	public function test_cost_without_currency_is_an_error(): void {
		$result = Details::validate( array( 'cost' => '10' ) );

		$this->assertArrayHasKey( 'currency', $result['errors'] );
	}

	public function test_purchase_sources_filter_is_sanitised(): void {
		Filters\expectApplied( 'axle_license_tracker_purchase_sources' )->once()->andReturn(
			array(
				'Agency Card' => array(
					'label' => 'Agency card',
					'flag'  => 0,
				),
				'broken'      => 'x',
			)
		);

		$this->assertSame(
			array(
				'agencycard' => array(
					'label' => 'Agency card',
					'flag'  => false,
				),
			),
			Details::purchase_sources()
		);
	}

	public function test_save_stores_meta_and_fires_details_saved(): void {
		$licenses  = new Licenses();
		$details   = new Details( $licenses );
		$component = $this->plugin( 'pro/pro.php' );

		Actions\expectDone( 'axle_license_tracker_details_saved' )->once()->with(
			$component,
			Mockery::on(
				static function ( $fields ) {
					return 4900 === $fields['cost'] && 'EUR' === $fields['currency'];
				}
			),
			7
		);

		$this->assertSame(
			array(),
			$details->save(
				$component,
				array(
					'cost'     => '49',
					'currency' => 'EUR',
				),
				7
			)
		);
		$this->assertSame( 4900, $this->meta[100]['_alc_cost'] );
		$this->assertSame( 7, $this->meta[100]['_alc_saved_by'] );
		$this->assertSame( 'plugin:pro/pro.php', $this->meta[100]['_alc_component'] );
		$this->assertSame( array( 'cost' => 4900, 'currency' => 'EUR' ), $licenses->details( 'plugin:pro/pro.php' ) );
	}

	public function test_clearing_deletes_and_a_bad_field_saves_nothing(): void {
		$licenses  = new Licenses();
		$details   = new Details( $licenses );
		$component = $this->plugin( 'pro/pro.php' );
		$details->save( $component, array( 'currency' => 'EUR' ), 7 );
		$this->assertSame( 'EUR', $this->meta[100]['_alc_currency'] );

		$details->save( $component, array( 'currency' => '' ), 7 );
		$this->assertArrayNotHasKey( '_alc_currency', $this->meta[100] );
		$this->assertArrayHasKey( 'renewal', $details->save( $component, array( 'renewal' => 'soon' ), 7 ) );
	}
}
