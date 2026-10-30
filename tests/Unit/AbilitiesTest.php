<?php
/**
 * Tests for the abilities, the REST route and service hooks.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Abilities;
use AryanX\Axle\LicenseTracker\Admin\DashboardWidget;
use AryanX\Axle\LicenseTracker\Admin\PluginsScreen;
use AryanX\Axle\LicenseTracker\Bundles;
use AryanX\Axle\LicenseTracker\Capability;
use AryanX\Axle\LicenseTracker\Details;
use AryanX\Axle\LicenseTracker\Inventory;
use AryanX\Axle\LicenseTracker\Lifecycle;
use AryanX\Axle\LicenseTracker\Licenses;
use AryanX\Axle\LicenseTracker\Report;
use AryanX\Axle\LicenseTracker\Rest\ReportController;
use AryanX\Axle\LicenseTracker\Tracker;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Abilities, ReportController, Lifecycle, Bundles and the small services.
 */
final class AbilitiesTest extends LicenseTestCase {

	/**
	 * Abilities registered, by name.
	 *
	 * @var array<string, array>
	 */
	private $registered = array();

	private function abilities( ?Tracker $tracker = null ): Abilities {
		$tracker = $tracker ?? Mockery::mock( Tracker::class );

		return new Abilities( $tracker, Mockery::mock( Report::class ), new Details( new Licenses() ) );
	}

	public function test_nothing_is_hooked_without_the_abilities_api(): void {
		$this->abilities()->register();

		$this->assertFalse( has_action( 'wp_abilities_api_init' ) );
	}

	public function test_registers_six_abilities_with_annotations_and_the_capability(): void {
		Functions\when( 'wp_register_ability' )->alias(
			function ( $name, $args ) {
				$this->registered[ $name ] = $args;
			}
		);
		Functions\expect( 'wp_register_ability_category' )->once()->with( 'axle-license-tracker', Mockery::type( 'array' ) );

		$abilities = $this->abilities();
		$abilities->register();
		$this->assertNotFalse( has_action( 'wp_abilities_api_init', array( $abilities, 'register_abilities' ) ) );
		$abilities->register_category();
		$abilities->register_abilities();

		$this->assertSame(
			array( 'get-license-report', 'list-expiring', 'get-component', 'recheck-licenses', 'update-license-details', 'ignore-component' ),
			array_map(
				static function ( string $name ): string {
					return substr( $name, strlen( 'axle-license-tracker/' ) );
				},
				array_keys( $this->registered )
			)
		);
		foreach ( array( 'get-license-report', 'list-expiring', 'get-component' ) as $name ) {
			$this->assertTrue( $this->registered[ 'axle-license-tracker/' . $name ]['meta']['annotations']['readonly'] );
		}
		foreach ( array( 'recheck-licenses', 'update-license-details', 'ignore-component' ) as $name ) {
			$this->assertSame(
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				$this->registered[ 'axle-license-tracker/' . $name ]['meta']['annotations']
			);
		}
		$this->assertTrue( $this->registered['axle-license-tracker/get-license-report']['meta']['mcp']['public'] );

		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		$this->assertFalse( call_user_func( $this->registered['axle-license-tracker/get-component']['permission_callback'] ) );
	}

	public function test_unknown_components_are_a_404(): void {
		$tracker = Mockery::mock( Tracker::class );
		$tracker->shouldReceive( 'row' )->andReturn( null );
		$abilities = $this->abilities( $tracker );

		foreach ( array( 'get_component', 'update_details', 'ignore' ) as $method ) {
			$error = $abilities->$method( array( 'component' => 'plugin:nope/nope.php' ) );
			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( array( 'status' => 404 ), $error->get_error_data() );
		}
	}

	public function test_update_details_returns_field_errors(): void {
		$tracker = Mockery::mock( Tracker::class );
		$tracker->shouldReceive( 'row' )->andReturn( $this->plugin( 'pro/pro.php' ) );
		$error = $this->abilities( $tracker )->update_details(
			array(
				'component' => 'plugin:pro/pro.php',
				'details'   => array( 'renewal' => 'tomorrow' ),
			)
		);

		$this->assertSame( 'axle_license_tracker_invalid', $error->get_error_code() );
		$this->assertArrayHasKey( 'renewal', $error->get_error_data()['fields'] );
	}

	public function test_report_route_needs_either_capability(): void {
		$report = Mockery::mock( Report::class );
		$report->shouldReceive( 'build' )->andReturn( array( 'components' => array() ) );
		$controller = new ReportController( $report );
		Functions\expect( 'register_rest_route' )->once()->with( 'axle-license-tracker/v1', '/report', Mockery::on( static function ( $args ) {
			return 'GET' === $args['methods'] && array( Capability::class, 'can_read_report' ) === $args['permission_callback'];
		} ) );
		$controller->routes();
		$this->assertSame( array( 'components' => array() ), $controller->get()->get_data() );

		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) {
				return 'axle_license_tracker_read_report' === $cap;
			}
		);
		$this->assertTrue( Capability::can_read_report() );
		$this->assertFalse( Capability::can_manage() );
	}

	public function test_activation_adds_the_reporter_role_and_the_daily_event(): void {
		Functions\when( 'get_role' )->justReturn( null );
		Functions\expect( 'add_role' )->once()->with( 'axle_license_reporter', 'License reporter', array( 'read' => true, 'axle_license_tracker_read_report' => true ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), 'daily', Lifecycle::DAILY );
		Lifecycle::activate();

		Functions\expect( 'wp_clear_scheduled_hook' )->twice();
		Lifecycle::deactivate();
	}

	public function test_bundles_reads_local_tgmpa_sources_only(): void {
		$GLOBALS['tgmpa'] = (object) array(
			'plugins' => array(
				'slider' => array( 'slug' => 'slider', 'name' => 'Slider', 'source' => '/themes/big/plugins/slider.zip', 'source_type' => 'bundled' ),
				'remote' => array( 'slug' => 'remote', 'name' => 'Remote', 'source' => 'https://vendor.example/remote.zip', 'source_type' => 'external' ),
				'repo'   => array( 'slug' => 'repo', 'name' => 'Repo', 'source' => 'repo' ),
			),
		);
		try {
			$this->assertSame( array( 'slider' => 'Slider', 'repo' => 'Repo' ), ( new Bundles() )->plugins() );
		} finally {
			unset( $GLOBALS['tgmpa'] );
		}
		$this->assertSame( array(), ( new Bundles() )->plugins() );
	}

	public function test_inventory_adds_the_woo_header(): void {
		$inventory = new Inventory();
		$inventory->register();
		$this->assertNotFalse( has_filter( 'extra_plugin_headers', array( $inventory, 'headers' ) ) );
		$this->assertSame( array( 'Foo', 'Woo' ), $inventory->headers( array( 'Foo', 'Woo' ) ) );

		$theme = Inventory::theme( 'pro-theme', array( 'Name' => 'Pro', 'UpdateURI' => 'https://vendor.example/' ), '/themes/pro-theme', true );
		$this->assertSame( array( 'theme:pro-theme', 'https://vendor.example/' ), array( $theme['id'], $theme['update_uri'] ) );
	}

	public function test_plugins_column_and_dashboard_widget_respect_the_capability(): void {
		$tracker = Mockery::mock( Tracker::class );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_add_dashboard_widget' )->never();

		$screen = new PluginsScreen( $tracker );
		$this->assertSame( array( 'name' => 'Plugin' ), $screen->columns( array( 'name' => 'Plugin' ) ) );
		$this->assertSame( array( 'x' => array() ), $screen->themes( array( 'x' => array() ) ) );
		( new DashboardWidget( $tracker ) )->add();
	}
}
