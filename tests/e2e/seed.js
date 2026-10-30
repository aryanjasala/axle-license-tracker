// Fixture plugins, theme and data for the end-to-end tests, the screenshots and the demo.
// Every fixture is plain GPL code written for these tests, mimicking where one vendor stores its state.
const { wp, wpEval } = require( './utils' );

const SRC = 'WP_PLUGIN_DIR . "/axle-license-tracker/tests/e2e/fixtures"';
const PLUGINS = [ 'alc-freemius-fixture', 'alc-edd-fixture', 'alc-blocked-fixture', 'alc-silent-fixture', 'alc-bundled-fixture' ];
const OPTIONS = [
	'axle_license_tracker_settings',
	'axle_license_tracker_detection',
	'alc_fixture_mail',
	'fs_accounts',
	'alc_edd_fixture_license_key',
	'alc_edd_fixture_license_status',
];

// Keys the tests look for in every output. Neither may ever show up.
const KEYS = {
	edd: 'ALCEDDKEY-1f2e3d4c5b6a79880011',
	freemius: 'sk_ALCFREEMIUSSECRET9a8b7c6d5e4f',
};

const COPY = `
	$copy = function ( $from, $to ) use ( &$copy ) {
		wp_mkdir_p( $to );
		foreach ( scandir( $from ) as $name ) {
			if ( "." === $name || ".." === $name ) { continue; }
			is_dir( "$from/$name" ) ? $copy( "$from/$name", "$to/$name" ) : copy( "$from/$name", "$to/$name" );
		}
	};`;

/** Copies the fixture plugins, theme and must-use plugin into the site, and activates the plugins. */
function installFixtures() {
	wpEval(
		`${ COPY }
		foreach ( ${ JSON.stringify( PLUGINS ) } as $plugin ) { $copy( ${ SRC } . "/plugins/$plugin", WP_PLUGIN_DIR . "/$plugin" ); }
		$copy( ${ SRC } . "/themes/alc-fixture-theme", get_theme_root() . "/alc-fixture-theme" );
		wp_mkdir_p( WPMU_PLUGIN_DIR );
		copy( ${ SRC } . "/alc-e2e-fixture.php", WPMU_PLUGIN_DIR . "/alc-e2e-fixture.php" );`
	);
	wp( 'plugin', 'activate', ...PLUGINS );
}

/** Removes everything installFixtures() added, and the plugin's data. */
function removeFixtures() {
	try {
		wp( 'plugin', 'deactivate', ...PLUGINS );
	} catch ( error ) {
		// Not active.
	}
	wpEval(
		`foreach ( ${ JSON.stringify( PLUGINS ) } as $plugin ) { $plugin && is_dir( WP_PLUGIN_DIR . "/$plugin" ) && ( new WP_Filesystem_Direct( null ) )->rmdir( WP_PLUGIN_DIR . "/$plugin", true ); }
		( new WP_Filesystem_Direct( null ) )->rmdir( get_theme_root() . "/alc-fixture-theme", true );
		@unlink( WPMU_PLUGIN_DIR . "/alc-e2e-fixture.php" );`.replace( /^/, 'require_once ABSPATH . "wp-admin/includes/class-wp-filesystem-base.php"; require_once ABSPATH . "wp-admin/includes/class-wp-filesystem-direct.php"; ' )
	);
	resetData();
}

/** Clears the plugin's records, settings and the fixtures' vendor options. */
function resetData() {
	for ( const option of OPTIONS ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Not set.
		}
	}
	wpEval( 'foreach ( get_posts( array( "post_type" => "alc_license", "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) ) as $id ) { wp_delete_post( $id, true ); }' );
	wpEval( 'delete_site_transient( "update_plugins" ); delete_site_transient( "update_themes" );' );
}

/**
 * Stores a Freemius account for the fixture, the way the SDK does: serialized
 * FS_Site and FS_Plugin_License objects whose classes are not loaded.
 *
 * @param {number} expiresInDays Days until the license expires; negative is in the past.
 */
function setFreemius( expiresInDays ) {
	wpEval( `
		$site = new stdClass(); $site->id = 501; $site->plugin_id = 9001; $site->license_id = 7001; $site->is_premium = true; $site->version = "3.1.0";
		$license = new stdClass(); $license->id = 7001; $license->plugin_id = 9001; $license->quota = 1; $license->activated = 1;
		$license->expiration = gmdate( "Y-m-d H:i:s", time() + ( ${ Number( expiresInDays ) } * DAY_IN_SECONDS ) ); $license->secret_key = "${ KEYS.freemius }"; $license->is_cancelled = false;
		$raw = serialize( array(
			"sites"         => array( "alc-freemius-fixture" => $site ),
			"all_licenses"  => array( 9001 => array( $license ) ),
			"file_slug_map" => array( "alc-freemius-fixture/alc-freemius-fixture.php" => "alc-freemius-fixture" ),
		) );
		$raw = str_replace( array( 'O:8:"stdClass":6', 'O:8:"stdClass":8' ), array( 'O:7:"FS_Site":6', 'O:17:"FS_Plugin_License":8' ), $raw );
		update_option( "fs_accounts", unserialize( $raw ) );` );
}

/** Stores the EDD fixture's key and status. */
function setEdd( status = 'valid' ) {
	wp( 'option', 'update', 'alc_edd_fixture_license_key', KEYS.edd );
	wp( 'option', 'update', 'alc_edd_fixture_license_status', status );
}

/** Pretends the silent fixture's updater last answered some days ago. */
function setLastSeen( component, daysAgo ) {
	wpEval( `$d = get_option( "axle_license_tracker_detection", array() ); $d["seen"][ ${ JSON.stringify( component ) } ] = time() - ${ Number( daysAgo ) } * DAY_IN_SECONDS; update_option( "axle_license_tracker_detection", $d, false );` );
}

/** Rows from WP-CLI, keyed by component id. */
function rows( all = true ) {
	const list = JSON.parse( wp( 'axle-license-tracker', 'list', ...( all ? [ '--all' ] : [] ), '--format=json', '--fields=id,name,type,vendor,sdk,state,expires,risk,renewal,cost,currency,owner' ) );
	return Object.fromEntries( list.map( ( row ) => [ row.id, row ] ) );
}

/** Everything the screenshots and demo need: fixtures, a fresh scan, and some details. */
function seedDemo() {
	installFixtures();
	setFreemius( -1 );
	setEdd( 'valid' );
	try {
		wp( 'axle-license-tracker', 'scan' );
	} catch ( error ) {
		// Exits 1: something is expired or at risk, which is the point.
	}
	setLastSeen( 'plugin:alc-silent-fixture/alc-silent-fixture.php', 40 );
	const date = ( days ) => new Date( Date.now() + days * 86400000 ).toISOString().slice( 0, 10 );
	wp( 'axle-license-tracker', 'set', 'plugin:alc-edd-fixture/alc-edd-fixture.php', `--renewal=${ date( 12 ) }`, '--cost=199', '--currency=USD', '--cycle=yearly', '--owner=Bean There', '--seats=3', '--auto-renew=no' );
	wp( 'axle-license-tracker', 'set', 'plugin:alc-freemius-fixture/alc-freemius-fixture.php', '--cost=79', '--currency=EUR', '--cycle=yearly', '--owner=Agency' );
	wp( 'axle-license-tracker', 'set', 'plugin:alc-blocked-fixture/alc-blocked-fixture.php', `--renewal=${ date( -3 ) }`, '--cost=12', '--currency=USD', '--cycle=monthly', '--owner=Bean There', '--purchase-source=gpl-club' );
	wp( 'axle-license-tracker', 'set', 'plugin:alc-silent-fixture/alc-silent-fixture.php', `--renewal=${ date( 45 ) }`, '--cost=49', '--currency=USD', '--cycle=yearly', '--owner=Agency' );
}

module.exports = { KEYS, PLUGINS, installFixtures, removeFixtures, resetData, setFreemius, setEdd, setLastSeen, rows, seedDemo };
