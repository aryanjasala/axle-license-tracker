<?php
/**
 * Tests for the seven vendor adapters, against the storage shapes each vendor writes.
 *
 * @package AryanX\Axle\LicenseTracker
 */

namespace AryanX\Axle\LicenseTracker\Tests\Unit;

use AryanX\Axle\LicenseTracker\Adapters\AcfPro;
use AryanX\Axle\LicenseTracker\Adapters\EddSoftwareLicensing;
use AryanX\Axle\LicenseTracker\Adapters\ElementorPro;
use AryanX\Axle\LicenseTracker\Adapters\EnvatoMarket;
use AryanX\Axle\LicenseTracker\Adapters\Freemius;
use AryanX\Axle\LicenseTracker\Adapters\GravityForms;
use AryanX\Axle\LicenseTracker\Adapters\WooCommerce;
use AryanX\Axle\LicenseTracker\Fingerprint;

/**
 * Covers the adapters.
 */
final class AdaptersTest extends LicenseTestCase {

	public function test_freemius_reads_serialized_objects_whose_classes_are_not_loaded(): void {
		$component = $this->plugin( 'pro-forms/pro-forms.php' );
		$adapter   = new Freemius();
		$this->assertFalse( $adapter->detect( $component, array() ) );
		$this->assertTrue( $adapter->detect( $component, array( 'sdks' => array( 'freemius' ) ) ) );

		// What the SDK stores, as WordPress hands it back without the SDK loaded.
		$raw = 'a:3:{s:5:"sites";a:1:{s:9:"pro-forms";O:7:"FS_Site":3:{s:9:"plugin_id";i:9;s:10:"license_id";i:70;s:10:"is_premium";b:1;}}'
			. 's:12:"all_licenses";a:1:{i:9;a:1:{i:0;O:17:"FS_Plugin_License":5:{s:2:"id";i:70;s:10:"expiration";s:19:"2099-01-31 00:00:00";s:10:"secret_key";s:24:"sk_secret_abcdefghijkl12";s:5:"quota";i:3;s:9:"activated";i:2;}}}'
			. 's:13:"file_slug_map";a:1:{s:23:"pro-forms/pro-forms.php";s:9:"pro-forms";}}';
		$this->options['fs_accounts'] = unserialize( $raw ); // phpcs:ignore

		$this->assertTrue( $adapter->detect( $component, array() ) );
		$state = $adapter->read( $component );
		$this->assertSame( 'valid', $state->state );
		$this->assertSame( '2099-01-31', $state->expires );
		$this->assertSame( 3, $state->seats );
		$this->assertSame( 2, $state->seats_used );
		$this->assertSame( Fingerprint::of( 'sk_secret_abcdefghijkl12' ), $state->fingerprint );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=pro-forms-account', $adapter->license_url( $component ) );

		// Lifetime: no expiration. Cancelled: invalid. No license: missing.
		$this->options['fs_accounts']['all_licenses'][9][0] = array( 'id' => 70, 'expiration' => null, 'secret_key' => 'x' );
		$this->assertSame( 'lifetime', $adapter->read( $component )->expires );
		$this->options['fs_accounts']['all_licenses'][9][0] = array( 'id' => 70, 'expiration' => '2001-01-01 00:00:00', 'is_cancelled' => true );
		$this->assertSame( 'invalid', $adapter->read( $component )->state );
		$this->options['fs_accounts']['all_licenses'][9] = array();
		$this->assertSame( 'unknown', $adapter->read( $component )->state );
		$this->options['fs_accounts']['sites']['pro-forms'] = array( 'is_premium' => true, 'license_id' => null );
		$this->assertSame( 'missing', $adapter->read( $component )->state );
	}

	public function test_a_free_freemius_plugin_is_not_premium(): void {
		$this->options['fs_accounts'] = array( 'sites' => array( 'free-thing' => array( 'is_premium' => false, 'license_id' => null ) ) );

		$this->assertFalse( ( new Freemius() )->detect( $this->plugin( 'free-thing/free-thing.php' ), array( 'sdks' => array( 'freemius' ) ) ) );
	}

	public function test_edd_reads_the_option_pair_and_cached_version(): void {
		$component = $this->plugin( 'my-addon/my-addon.php' );
		$adapter   = new EddSoftwareLicensing();
		$this->assertFalse( $adapter->detect( $component, array() ) );
		$this->assertTrue( $adapter->detect( $component, array( 'sdks' => array( 'edd-sl' ) ) ) );
		$this->assertSame( 'missing', $adapter->read( $component )->state );

		$this->options['my_addon_license_key']    = 'KEY-0123456789abcdef';
		$this->options['my_addon_license_status'] = 'valid';
		$this->options[ 'edd_sl_' . md5( serialize( 'my-addon' . 'KEY-0123456789abcdef' ) ) ] = array( 'timeout' => time() + 60, 'value' => '{"new_version":"2.5.0"}' ); // phpcs:ignore

		$this->assertTrue( $adapter->detect( $component, array() ) );
		$state = $adapter->read( $component );
		$this->assertSame( 'valid', $state->state );
		$this->assertSame( '2.5.0', $state->latest );
		$this->assertStringEndsWith( '-cdef', $state->fingerprint );

		// The whole check_license response as the status.
		$this->options['my_addon_license_status'] = (object) array( 'license' => 'expired', 'expires' => '2020-02-01 23:59:59', 'license_limit' => 5, 'site_count' => 4 );
		$state = $adapter->read( $component );
		$this->assertSame( 'expired', $state->state );
		$this->assertSame( '2020-02-01', $state->expires );
		$this->assertSame( 5, $state->seats );

		foreach ( array( 'site_inactive' => 'invalid', 'disabled' => 'invalid', '' => 'unknown', 'VALID' => 'valid' ) as $word => $expected ) {
			$this->assertSame( $expected, EddSoftwareLicensing::map( $word ) );
		}
	}

	public function test_woocommerce_reads_the_helper_subscriptions(): void {
		$component = $this->plugin( 'woo-addon/woo-addon.php', array( 'woo' => '18734:abc123' ) );
		$adapter   = new WooCommerce();
		$this->assertTrue( $adapter->detect( $component, array() ) );
		$this->assertFalse( $adapter->detect( $this->plugin( 'x/x.php' ), array() ) );
		$this->assertSame( 18734, WooCommerce::product_id( $component ) );

		$this->assertSame( 'missing', $adapter->read( $component )->state );

		$this->options['woocommerce_helper_data'] = array( 'auth' => array( 'access_token' => 't' ) );
		$state                                    = $adapter->read( $component );
		$this->assertSame( 'unknown', $state->state );
		$this->assertTrue( $state->stale );

		$this->transients['_woocommerce_helper_subscriptions'] = array(
			array(
				'product_id'   => 18734,
				'product_key'  => 'woo-key-123456789',
				'expires'      => gmmktime( 0, 0, 0, 6, 1, 2099 ),
				'expired'      => false,
				'autorenew'    => true,
				'sites_max'    => 1,
				'sites_active' => 1,
			),
		);
		$state = $adapter->read( $component );
		$this->assertSame( 'valid', $state->state );
		$this->assertSame( '2099-06-01', $state->expires );
		$this->assertSame( 'yes', $state->auto_renew );
		$this->assertSame( 1, $state->seats );

		$this->transients['_woocommerce_helper_subscriptions'][0]['expired'] = true;
		$this->assertSame( 'expired', $adapter->read( $component )->state );
		$this->transients['_woocommerce_helper_subscriptions'][0]['lifetime'] = true;
		$this->assertSame( 'lifetime', $adapter->read( $component )->expires );
		$this->transients['_woocommerce_helper_subscriptions'] = array();
		$this->assertSame( 'missing', $adapter->read( $component )->state );
	}

	public function test_elementor_pro_reads_the_cached_check(): void {
		$component = $this->plugin( 'elementor-pro/elementor-pro.php' );
		$adapter   = new ElementorPro();
		$this->assertTrue( $adapter->detect( $component, array() ) );
		$this->assertSame( 'missing', $adapter->read( $component )->state );

		$this->options['elementor_pro_license_key'] = 'el-key-1234567890';
		$this->assertSame( 'unknown', $adapter->read( $component )->state );

		$this->options['_elementor_pro_license_v2_data'] = array(
			'timeout' => time() + HOUR_IN_SECONDS,
			'value'   => '{"success":true,"license":"valid","expires":"2099-03-01 23:59:59"}',
		);
		$state = $adapter->read( $component );
		$this->assertSame( 'valid', $state->state );
		$this->assertSame( '2099-03-01', $state->expires );
		$this->assertFalse( $state->stale );
		$this->assertGreaterThan( 0, $state->checked );

		$this->options['_elementor_pro_license_v2_data'] = array(
			'timeout' => time() - HOUR_IN_SECONDS,
			'value'   => '{"success":false,"license":"site_inactive"}',
		);
		$state = $adapter->read( $component );
		$this->assertSame( 'invalid', $state->state );
		$this->assertSame( 'site inactive', $state->note );
		$this->assertTrue( $state->stale );
	}

	public function test_acf_pro_reads_the_key_without_unserializing(): void {
		$component = $this->plugin( 'advanced-custom-fields-pro/acf.php' );
		$adapter   = new AcfPro();
		$this->assertTrue( $adapter->detect( $component, array() ) );
		$this->assertSame( 'missing', $adapter->read( $component )->state );

		$this->options['acf_pro_license']        = base64_encode( serialize( array( 'key' => 'acf-key-ABCDEFGH', 'url' => 'https://example.com' ) ) ); // phpcs:ignore
		$this->options['acf_pro_license_status'] = array( 'status' => 'active', 'expiry' => gmmktime( 0, 0, 0, 1, 1, 2099 ) );
		$this->assertSame( 'acf-key-ABCDEFGH', AcfPro::key() );
		$state = $adapter->read( $component );
		$this->assertSame( 'valid', $state->state );
		$this->assertSame( '2099-01-01', $state->expires );

		$this->options['acf_pro_license_status'] = array( 'status' => 'active', 'lifetime' => true );
		$this->assertSame( 'lifetime', $adapter->read( $component )->expires );
		$this->options['acf_pro_license_status'] = array( 'status' => 'expired' );
		$this->assertSame( 'expired', $adapter->read( $component )->state );
		$this->options['acf_pro_license_status'] = array( 'status' => 'active', 'refunded' => true );
		$this->assertSame( 'invalid', $adapter->read( $component )->state );
	}

	public function test_gravity_forms_reads_the_hashed_key_and_version_info(): void {
		$component = $this->plugin( 'gravityforms/gravityforms.php', array( 'version' => '2.8.0' ) );
		$adapter   = new GravityForms();
		$this->assertTrue( $adapter->detect( $component, array() ) );
		$this->assertSame( 'missing', $adapter->read( $component )->state );

		$this->options['rg_gforms_key'] = md5( 'gf-key' );
		$this->assertSame( 'unknown', $adapter->read( $component )->state );

		$this->options['gform_version_info'] = array( 'is_valid_key' => '1', 'expiration_time' => '2099-05-05', 'version' => '2.9.1', 'timestamp' => 1700000000 );
		$state                               = $adapter->read( $component );
		$this->assertSame( 'valid', $state->state );
		$this->assertSame( '2.9.1', $state->latest );
		$this->assertSame( 1700000000, $state->checked );
		$this->assertSame( Fingerprint::of( md5( 'gf-key' ) ), $state->fingerprint );

		$this->options['gform_version_info']['is_valid_key'] = '0';
		$this->assertSame( 'invalid', $adapter->read( $component )->state );
	}

	public function test_envato_market_reports_a_token(): void {
		$adapter = new EnvatoMarket();
		$theme   = $this->plugin( 'avada-builder/avada-builder.php', array( 'author_uri' => 'https://themeforest.net/user/themefusion' ) );
		$this->assertTrue( $adapter->detect( $theme, array() ) );
		$this->assertTrue( $adapter->detect( $this->plugin( 'envato-market/envato-market.php' ), array() ) );
		$this->assertFalse( $adapter->detect( $this->plugin( 'x/x.php', array( 'author_uri' => 'https://notthemeforest.net.example/' ) ), array() ) );

		$this->assertSame( 'missing', $adapter->read( $theme )->state );
		$this->options['envato_market'] = array( 'token' => 'personal-token' );
		$state                          = $adapter->read( $theme );
		$this->assertSame( 'valid', $state->state );
		$this->assertSame( 'lifetime', $state->expires );
		$this->assertSame( '', $state->fingerprint );
	}
}
