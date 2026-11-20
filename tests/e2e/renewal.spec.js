// Updates around a renewal: the blocked-update message, the renewed notice and email, and the reminder line.
const { test, expect, wp, wpEval } = require( './utils' );
const seed = require( './seed' );

const EDD = 'plugin:alc-edd-fixture/alc-edd-fixture.php';
const EDD_FILE = 'alc-edd-fixture/alc-edd-fixture.php';
const BLOCKED_FILE = 'alc-blocked-fixture/alc-blocked-fixture.php';

const day = ( offset ) => wpEval( `echo gmdate( "Y-m-d", strtotime( current_time( "Y-m-d" ) . " 00:00:00 UTC" ) + ( ${ Number( offset ) } * DAY_IN_SECONDS ) );` );

const mails = () => {
	try {
		return JSON.parse( wp( 'option', 'get', 'alc_fixture_mail', '--format=json' ) );
	} catch ( error ) {
		return [];
	}
};

const offerEddUpdate = seed.offerEddUpdate;

const stateOf = () => seed.rows()[ EDD ].state;

test.describe( 'Updates around a renewal', () => {
	test.beforeAll( () => {
		seed.removeFixtures();
		seed.installFixtures();
		seed.setFreemius( -1 );
		seed.setEdd( 'valid' );
		try {
			// Exits 1 while a fixture license is expired; the check itself ran.
			wp( 'axle-license-tracker', 'scan' );
		} catch ( error ) {
			// Expected.
		}
	} );

	test.afterAll( () => {
		wp( 'option', 'delete', 'alc_fixture_mail' );
		seed.removeFixtures();
	} );

	test( 'a blocked update says why on the Plugins screen', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		const message = page.locator( `tr.plugin-update-tr[data-plugin="${ BLOCKED_FILE }"] .alc-blocked-update` );
		await expect( message ).toContainText( 'Blocked:' );
		await expect( message ).toContainText( 'ALC Blocked Fixture' );
	} );

	test( 'an expired license says so, with its date in the site format, and the owner for people who manage licenses', async ( { page } ) => {
		wp( 'option', 'update', 'date_format', 'j F Y' );
		const renewal = day( -3 );
		wp( 'axle-license-tracker', 'set', EDD, `--renewal=${ renewal }`, '--owner=Priya' );
		seed.setEdd( 'expired' );
		wp( 'axle-license-tracker', 'remind', '--dry-run' );
		wpEval( `
			$t = get_site_transient( "update_plugins" );
			$t->response["${ EDD_FILE }"] = (object) array( "id" => "vendor.example/edd", "slug" => "alc-edd-fixture", "plugin" => "${ EDD_FILE }", "new_version" => "9.9.9", "url" => "https://vendor.example/", "package" => "" );
			set_site_transient( "update_plugins", $t );` );

		await page.goto( '/wp-admin/plugins.php' );
		const message = page.locator( `tr.plugin-update-tr[data-plugin="${ EDD_FILE }"] .alc-blocked-update` );
		const pretty = wpEval( `echo wp_date( "j F Y", strtotime( "${ renewal } 12:00:00 UTC" ), new DateTimeZone( "UTC" ) );` );
		await expect( message ).toContainText( `license expired on ${ pretty }` );
		await expect( message ).toContainText( 'Owner: Priya.' );
	} );

	test( 'a renewed license that left the site behind gets a notice, one email and a plain update link', async ( { page } ) => {
		wp( 'option', 'delete', 'alc_fixture_mail' );
		offerEddUpdate( true );
		expect( stateOf() ).toBe( 'expired' );
		wp( 'axle-license-tracker', 'remind', '--dry-run' );

		// The vendor stored a renewed license.
		seed.setEdd( 'valid' );
		wp( 'axle-license-tracker', 'remind', '--dry-run' );
		expect( stateOf() ).not.toBe( 'expired' );

		const renewed = mails().filter( ( mail ) => mail.subject.includes( 'renewed: rehearse the update first' ) );
		expect( renewed ).toHaveLength( 1 );
		expect( renewed[ 0 ].message ).toMatch( /Rehearse the jump from \S+ to 9\.9\.9 first, then update\./ );
		// A second run sends nothing more.
		wp( 'axle-license-tracker', 'remind', '--dry-run' );
		expect( mails().filter( ( mail ) => mail.subject.includes( 'renewed: rehearse the update first' ) ) ).toHaveLength( 1 );

		await page.goto( '/wp-admin/plugins.php' );
		const notice = page.locator( '.alc-renewed-notice' );
		await expect( notice ).toContainText( 'ALC EDD Fixture:' );
		await expect( notice ).toContainText( /Renewed\. Rehearse the jump from \S+ to 9\.9\.9 first\./ );
		// Update Rehearsal is not in this test site, so the link is the plain update link.
		await expect( notice.locator( '.alc-renewed-link' ) ).toHaveText( 'Update' );
		await expect( notice.locator( '.alc-renewed-link' ) ).toHaveAttribute( 'href', /update\.php\?action=upgrade-plugin&plugin=alc-edd-fixture/ );

		await notice.getByRole( 'link', { name: 'Dismiss' } ).click();
		await expect( page.locator( '.alc-renewed-notice' ) ).toHaveCount( 0 );
	} );

	test( 'the renewed notice goes away when the site is up to date', async ( { page } ) => {
		wpEval( `$t = get_site_transient( "update_plugins" ); unset( $t->response["${ EDD_FILE }"] ); set_site_transient( "update_plugins", $t );` );
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.alc-renewed-notice' ) ).toHaveCount( 0 );
	} );

	test( 'a reminder says an update can be taken now, and not when there is nothing to download', async () => {
		wp( 'option', 'delete', 'alc_fixture_mail' );
		const renewal = day( 6 );
		wp( 'axle-license-tracker', 'set', EDD, `--renewal=${ renewal }`, '--cost=199', '--currency=USD', '--cycle=yearly' );
		offerEddUpdate( true );
		wp( 'axle-license-tracker', 'remind' );

		const reminder = mails().filter( ( mail ) => mail.subject.includes( 'ALC EDD Fixture renews in 6 days' ) );
		expect( reminder ).toHaveLength( 1 );
		expect( reminder[ 0 ].message ).toContain( 'ALC EDD Fixture 9.9.9 is available now and will not be after' );
		expect( reminder[ 0 ].message ).toContain( 'rehearse it, then update.' );
		expect( reminder[ 0 ].message ).not.toContain( renewal );

		// With nothing to download, a later reminder day says nothing about an update.
		wp( 'option', 'delete', 'alc_fixture_mail' );
		offerEddUpdate( false );
		wp( 'axle-license-tracker', 'set', EDD, `--renewal=${ day( 5 ) }` );
		wp( 'axle-license-tracker', 'remind' );
		const second = mails().filter( ( mail ) => mail.subject.includes( 'ALC EDD Fixture renews in 5 days' ) );
		expect( second ).toHaveLength( 1 );
		expect( second[ 0 ].message ).not.toContain( 'is available now' );
	} );
} );
