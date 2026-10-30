<?php
/**
 * Tests for layers 1 and 3: update data, the scan, the registry, classification and risk.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Adapters\Adapter;
use AryanX\Axle\LicenseTracker\Adapters\LicenseState;
use AryanX\Axle\LicenseTracker\Classifier;
use AryanX\Axle\LicenseTracker\Inventory;
use AryanX\Axle\LicenseTracker\Registry;
use AryanX\Axle\LicenseTracker\Risk;
use AryanX\Axle\LicenseTracker\Scanner;
use AryanX\Axle\LicenseTracker\Store;
use AryanX\Axle\LicenseTracker\Updates;
use AryanX\Axle\LicenseTracker\Vendors;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Updates, Scanner, Registry, Vendors, Classifier and Risk.
 */
final class DetectionTest extends LicenseTestCase {

	/**
	 * Temporary folders made by a test.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			$this->remove( $dir );
		}
		parent::tearDown();
	}

	public function test_directory_entries_and_update_uri_hosts(): void {
		$this->assertTrue( Updates::is_directory( Updates::normalize( (object) array( 'id' => 'w.org/plugins/hello-dolly' ) ) ) );
		$this->assertTrue( Updates::is_directory( Updates::normalize( array( 'package' => 'https://downloads.wordpress.org/theme/x.zip' ) ) ) );
		$this->assertFalse( Updates::is_directory( Updates::normalize( (object) array( 'id' => 'vendor.com/x', 'package' => 'https://vendor.com/x.zip' ) ) ) );
		$this->assertFalse( Updates::is_directory( Updates::normalize( array( 'url' => 'https://evilwordpress.org.example/' ) ) ) );

		$this->assertSame( 'updates.vendor.com', Updates::external_host( 'https://updates.vendor.com/x' ) );
		$this->assertSame( 'vendor.com', Updates::external_host( 'vendor.com/x' ) );
		$this->assertSame( '', Updates::external_host( 'https://wordpress.org/plugins/x/' ) );
		$this->assertSame( '', Updates::external_host( 'w.org' ) );
		$this->assertSame( '', Updates::external_host( 'false' ) );
		$this->assertSame( '', Updates::external_host( '' ) );
	}

	public function test_saving_the_update_transient_records_vendor_answers_only(): void {
		$store   = new Store();
		$updates = new Updates( $store );
		$updates->record_plugins(
			(object) array(
				'response'  => array( 'pro/pro.php' => (object) array( 'id' => 'vendor.com/pro', 'package' => '' ) ),
				'no_update' => array( 'hello.php' => (object) array( 'id' => 'w.org/plugins/hello-dolly' ) ),
			)
		);
		$updates->record_themes( (object) array( 'no_update' => array( 'pro-theme' => array( 'theme' => 'pro-theme', 'package' => 'https://vendor.com/t.zip' ) ) ) );

		$seen = $this->options[ Store::OPTION ]['seen'];
		$this->assertSame( array( 'plugin:pro/pro.php', 'theme:pro-theme' ), array_keys( $seen ) );
		$this->assertEqualsWithDelta( time(), $updates->last_seen( 'plugin:pro/pro.php' ), 2 );
		$this->assertSame( 0, $updates->last_seen( 'plugin:hello.php' ) );
	}

	public function test_entry_reads_the_transient(): void {
		$updates = new Updates( new Store() );
		$this->assertNull( $updates->entry( $this->plugin( 'pro/pro.php' ) ) );
		$this->assertFalse( $updates->checked( 'plugin' ) );

		$this->site_transients['update_plugins'] = (object) array(
			'last_checked' => time(),
			'response'     => array(
				'pro/pro.php' => (object) array(
					'id'          => 'vendor.com/pro',
					'new_version' => '2.0',
					'package'     => '',
				),
			),
		);
		$entry = $updates->entry( $this->plugin( 'pro/pro.php' ) );
		$this->assertSame( 'response', $entry['list'] );
		$this->assertFalse( $entry['directory'] );
		$this->assertTrue( $updates->checked( 'plugin' ) );
	}

	public function test_the_scan_finds_sdks_within_its_limits_and_never_runs_code(): void {
		$dir = $this->make(
			array(
				'pro.php'                         => '<?php // main',
				'freemius/start.php'              => '<?php exit( "never run" );',
				'inc/class-updater.php'           => '<?php class Pro_EDD' . '_SL_Plugin_Updater {}',
				'a/b/c/d/deep.php'                => '<?php class WC_AM' . '_Client {}',
				'big.php'                         => '<?php ' . str_repeat( ' ', 2000 ) . 'use YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory;',
				'admin/bsf-core/readme.txt'       => 'not php',
				'vendor/appsero/client/x.txt'     => 'Appsero\\Client',
			)
		);
		$scanner   = new Scanner( new Store(), new Inventory() );
		$component = $this->plugin( 'pro/pro.php', array( 'path' => $dir ) );

		$this->assertSame( array( 'bsf-core', 'edd-sl', 'freemius', 'puc' ), $scanner->scan( $component ) );

		// Smaller limits through the filters.
		Filters\expectApplied( 'axle_license_tracker_scan_max_file_bytes' )->andReturn( 1024 );
		Filters\expectApplied( 'axle_license_tracker_scan_max_depth' )->andReturn( 0 );
		$this->assertSame( array(), $scanner->scan( $component ) );
	}

	public function test_scan_limits_are_clamped(): void {
		Filters\expectApplied( 'axle_license_tracker_scan_max_depth' )->andReturn( 99 );
		Filters\expectApplied( 'axle_license_tracker_scan_max_file_bytes' )->andReturn( -1 );
		Filters\expectApplied( 'axle_license_tracker_scan_batch_size' )->andReturn( 0 );

		$this->assertSame( 10, Scanner::max_depth() );
		$this->assertSame( 1024, Scanner::max_bytes() );
		$this->assertSame( 1, Scanner::batch_size() );
	}

	public function test_signatures_filter_drops_broken_entries(): void {
		Filters\expectApplied( 'axle_license_tracker_signatures' )->once()->andReturnUsing(
			static function ( array $list ): array {
				$list[] = array( 'id' => 'mine', 'label' => 'My SDK', 'pattern' => 'class\\s+My_Licensing\\b' );
				$list[] = array( 'id' => 'broken', 'pattern' => '(' );
				$list[] = array( 'id' => 'empty' );
				return $list;
			}
		);
		$ids = array_column( ( new Scanner( new Store(), new Inventory() ) )->signatures(), 'id' );

		$this->assertContains( 'mine', $ids );
		$this->assertContains( 'freemius', $ids );
		$this->assertNotContains( 'broken', $ids );
		$this->assertNotContains( 'empty', $ids );
	}

	public function test_run_batch_scans_five_and_schedules_the_rest(): void {
		$components = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$component                      = $this->plugin( "p{$i}/p{$i}.php" );
			$components[ $component['id'] ] = $component;
		}
		$inventory = Mockery::mock( Inventory::class );
		$inventory->shouldReceive( 'all' )->andReturn( $components );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once();

		$scanner = new Scanner( new Store(), $inventory );
		$this->assertSame( 5, $scanner->run_batch() );
		$this->assertCount( 2, $scanner->pending() );
		$this->assertSame( array(), $scanner->cached( $components['plugin:p1/p1.php'] ) );
	}

	public function test_registry_and_vendor_filters(): void {
		$custom = new class() implements Adapter {
			public function id(): string {
				return 'mine';
			}
			public function label(): string {
				return 'Mine';
			}
			public function detect( array $component, array $signals ): bool {
				return 'mine' === $component['slug'];
			}
			public function read( array $component ): LicenseState {
				return new LicenseState( array( 'state' => 'valid' ) );
			}
			public function license_url( array $component ): string {
				return '';
			}
		};
		Filters\expectApplied( 'axle_license_tracker_adapters' )->once()->andReturnUsing(
			static function ( array $adapters ) use ( $custom ): array {
				array_unshift( $adapters, $custom, 'not an adapter' );
				return $adapters;
			}
		);
		$registry = new Registry();
		$this->assertSame( 'mine', array_keys( $registry->all() )[0] );
		$this->assertCount( 8, $registry->all() );
		$this->assertSame( $custom, $registry->for_component( $this->plugin( 'mine/mine.php' ), array() ) );
		$this->assertNull( $registry->for_component( $this->plugin( 'other/other.php' ), array() ) );

		Filters\expectApplied( 'axle_license_tracker_vendors' )->once()->andReturnUsing(
			static function ( array $vendors ): array {
				$vendors[] = array( 'id' => 'acme', 'name' => 'Acme', 'slugs' => array(), 'authors' => array( 'acme.test' ) );
				$vendors[] = array( 'name' => 'No id' );
				return $vendors;
			}
		);
		$vendors = new Vendors();
		$this->assertSame( 'Acme', $vendors->match( $this->plugin( 'a/a.php', array( 'author_uri' => 'https://www.acme.test/' ) ) )['name'] );
		$this->assertSame( 'WP Rocket', $vendors->match( $this->plugin( 'wp-rocket/wp-rocket.php' ) )['name'] );
		$this->assertNull( $vendors->match( $this->plugin( 'b/b.php' ) ) );
		$this->assertNull( $vendors->get( 'no-id' ) );
	}

	public function test_classifier_types(): void {
		$store   = new Store();
		$scanner = new Scanner( $store, new Inventory() );
		$updates = new Updates( $store );
		$c       = new Classifier( $updates, $scanner, new Vendors(), new Registry() );
		Functions\when( 'wp_get_theme' )->justReturn( null );

		$this->site_transients['update_plugins'] = (object) array(
			'last_checked' => time(),
			'no_update'    => array(
				'hello.php'   => (object) array( 'id' => 'w.org/plugins/hello-dolly' ),
				'vend/v.php'  => (object) array( 'id' => 'vendor.com/v', 'package' => 'https://vendor.com/v.zip' ),
			),
		);
		$this->options[ Store::OPTION ] = array( 'scan' => array( 'plugin:mine/mine.php@1.0.0' => array(), 'plugin:sdk/sdk.php@1.0.0' => array( 'puc' ) ) );

		$this->assertSame( 'directory', $c->classify( $this->plugin( 'hello.php' ) )['type'] );
		$injected = $c->classify( $this->plugin( 'vend/v.php' ) );
		$this->assertSame( 'premium', $injected['type'] );
		$this->assertSame( 'vendor.com', $injected['vendor'] );
		$uri = $c->classify( $this->plugin( 'own/own.php', array( 'update_uri' => 'https://own.example/' ) ) );
		$this->assertSame( array( 'premium', 'own.example' ), array( $uri['type'], $uri['source'] ) );
		$this->assertSame( 'Plugin Update Checker', $c->classify( $this->plugin( 'sdk/sdk.php' ) )['sdk'] );
		$elementor = $c->classify( $this->plugin( 'elementor-pro/elementor-pro.php' ) );
		$this->assertSame( array( 'premium', 'elementor-pro', 'Elementor' ), array( $elementor['type'], $elementor['adapter'], $elementor['vendor'] ) );
		$this->assertSame( 'custom', $c->classify( $this->plugin( 'mine/mine.php' ) )['type'] );
		$this->assertSame( 'unknown', $c->classify( $this->plugin( 'unscanned/unscanned.php' ) )['type'] );
		$bundled = $c->classify( $this->plugin( 'slider/slider.php' ), array( 'slider' => 'Slider' ), 'Big Theme' );
		$this->assertSame( array( 'bundled', 'Big Theme' ), array( $bundled['type'], $bundled['bundled_by'] ) );

		Filters\expectApplied( 'axle_license_tracker_component_type' )->andReturnUsing(
			static function ( $type, $component ) {
				return 'mine' === $component['slug'] ? 'premium' : 'nonsense';
			}
		);
		$this->assertSame( 'premium', $c->classify( $this->plugin( 'mine/mine.php' ) )['type'] );
		$this->assertSame( 'directory', $c->classify( $this->plugin( 'hello.php' ) )['type'] );
	}

	public function test_risk_levels(): void {
		$now       = time();
		$component = $this->plugin( 'pro/pro.php' );
		$premium   = array(
			'type'       => 'premium',
			'updater'    => true,
			'entry'      => null,
			'bundled_by' => '',
		);
		$version   = array(
			'version' => '1.0.0',
			'since'   => $now,
			'first'   => $now,
		);

		$this->assertSame( '', Risk::assess( $component, array( 'type' => 'directory' ) + $premium, array(), $version, 0, $now )['risk'] );
		$this->assertSame( 'ok', Risk::assess( $component, $premium, array(), $version, $now, $now )['risk'] );

		$blocked = $premium;
		$blocked['entry'] = array( 'list' => 'response', 'new_version' => '1.1.0', 'package' => '' );
		$result = Risk::assess( $component, $blocked, array(), $version, $now, $now );
		$this->assertSame( 'blocked', $result['risk'] );
		$this->assertStringContainsString( '1.1.0', $result['signals'][0] );

		$this->assertSame( 'at-risk', Risk::assess( $component, $premium, array( 'latest' => '1.2.0' ), $version, $now, $now )['risk'] );
		$this->assertSame( 'at-risk', Risk::assess( $component, $premium, array(), $version, $now - 31 * DAY_IN_SECONDS, $now )['risk'] );
		$this->assertSame( 'ok', Risk::assess( $component, $premium, array(), $version, $now - 29 * DAY_IN_SECONDS, $now )['risk'] );
		$old = array( 'first' => $now - 40 * DAY_IN_SECONDS ) + $version;
		$this->assertSame( 'at-risk', Risk::assess( $component, $premium, array(), $old, 0, $now )['risk'] );

		$stale = array( 'since' => $now - 200 * DAY_IN_SECONDS ) + $version;
		$this->assertSame( 'watch', Risk::assess( $component, $premium, array(), $stale, $now, $now )['risk'] );

		$bundled = array( 'type' => 'bundled', 'updater' => false, 'bundled_by' => 'Big Theme' ) + $premium;
		$this->assertSame( 'watch', Risk::assess( $component, $bundled, array(), $version, 0, $now )['risk'] );
	}

	public function test_risk_filters(): void {
		$now       = time();
		$component = $this->plugin( 'pro/pro.php' );
		$premium   = array(
			'type'       => 'premium',
			'updater'    => true,
			'entry'      => null,
			'bundled_by' => '',
		);
		$version   = array( 'version' => '1.0.0', 'since' => $now, 'first' => $now );

		Filters\expectApplied( 'axle_license_tracker_silent_days' )->andReturn( 5 );
		Filters\expectApplied( 'axle_license_tracker_stale_version_days' )->andReturn( 0 );
		$this->assertSame( 5, Risk::silent_days() );
		$this->assertSame( 1, Risk::stale_version_days() );
		$this->assertSame( 'at-risk', Risk::assess( $component, $premium, array(), $version, $now - 6 * DAY_IN_SECONDS, $now )['risk'] );

		Filters\expectApplied( 'axle_license_tracker_vulnerability_flags' )->andReturn( array( 'CVE-2026-0001: XSS in the settings screen' ) );
		$flagged = Risk::assess( $component, $premium, array(), $version, $now, $now );
		$this->assertSame( 'at-risk', $flagged['risk'] );
		$this->assertSame( array( 'CVE-2026-0001: XSS in the settings screen' ), $flagged['flags'] );

		Filters\expectApplied( 'axle_license_tracker_update_risk' )->once()->andReturn( 'ok' );
		$this->assertSame( 'ok', Risk::assess( $component, $premium, array(), $version, $now, $now )['risk'] );
	}

	/**
	 * Makes a temporary folder with files.
	 *
	 * @param array<string, string> $files Relative path => contents.
	 * @return string
	 */
	private function make( array $files ): string {
		$dir          = sys_get_temp_dir() . '/alc-unit-' . bin2hex( random_bytes( 4 ) );
		$this->dirs[] = $dir;
		foreach ( $files as $path => $contents ) {
			if ( ! is_dir( dirname( "$dir/$path" ) ) ) {
				mkdir( dirname( "$dir/$path" ), 0777, true );
			}
			file_put_contents( "$dir/$path", $contents );
		}

		return $dir;
	}

	/**
	 * Removes a folder.
	 *
	 * @param string $dir Folder.
	 */
	private function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $dir );
	}
}
