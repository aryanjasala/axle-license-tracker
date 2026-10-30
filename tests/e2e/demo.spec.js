// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp, wpEval } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { seedDemo, removeFixtures } = require( './seed' );

const SCREEN = '/wp-admin/plugins.php?page=axle-license-tracker';
const EDD = 'plugin:alc-edd-fixture/alc-edd-fixture.php';

test( 'demo: spot a lapsed plugin and add a renewal date', async ( { browser } ) => {
	test.setTimeout( 240_000 );
	prepareSite( 'Bean There Coffee' );
	removeFixtures();
	seedDemo();
	// The demo enters the EDD plugin's details by hand.
	for ( const field of [ 'renewal', 'cost', 'currency', 'cycle', 'owner' ] ) {
		wpEval( `$p = get_posts( array( "post_type" => "alc_license", "post_status" => "any", "numberposts" => 1, "fields" => "ids", "meta_key" => "_alc_component", "meta_value" => "${ EDD }" ) ); $p && delete_post_meta( $p[0], "_alc_${ field }" );` );
	}
	const renewal = wpEval( 'echo gmdate( "Y-m-d", strtotime( current_time( "Y-m-d" ) . " 00:00:00 UTC" ) + 9 * DAY_IN_SECONDS );' );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( SCREEN );
			await expect( page.locator( '.alc-table' ) ).toBeVisible();
			await demo.start();
			await demo.pause( 1500 );

			// What lapsed and what stopped getting updates.
			await demo.moveTo( page.locator( '.alc-tile-expired' ) );
			await demo.pause( 1000 );
			await demo.moveTo( page.locator( '.alc-risk-blocked' ) );
			await demo.pause( 1800 );

			// Add the renewal date and cost detection cannot know.
			await demo.click( page.locator( `tr[data-component="${ EDD }"] a[data-action="edit"]` ), 500 );
			await expect( page.locator( '.alc-edit' ) ).toBeVisible();
			await demo.choose( page.getByLabel( 'Billing cycle' ), 'yearly' );
			await page.getByLabel( 'Renewal date' ).fill( renewal );
			await demo.pause( 600 );
			await demo.type( page.getByLabel( 'Cost per cycle' ), '199.00' );
			await demo.type( page.getByLabel( 'Currency' ), 'USD' );
			await demo.type( page.getByLabel( 'Owner', { exact: true } ), 'Bean There' );
			await demo.click( page.getByRole( 'button', { name: 'Save details' } ), 500 );
			await expect( page.locator( '.alc-notice' ) ).toContainText( 'License details saved.' );
			await demo.pause( 1200 );

			// Back on the list: it renews soon, and the yearly cost adds up.
			await demo.click( page.getByRole( 'link', { name: 'Back to Licenses' } ), 500 );
			await expect( page.locator( `tr[data-component="${ EDD }"] .alc-state-expiring` ) ).toBeVisible();
			await demo.moveTo( page.locator( `tr[data-component="${ EDD }"] .alc-state-expiring` ) );
			await demo.pause( 1500 );
			await demo.moveTo( page.locator( '.alc-tile-cost' ) );
			await demo.pause( 2000 );
		} );
	} finally {
		removeFixtures();
	}

	expect( wp( 'option', 'get', 'blogname' ) ).toBe( 'Bean There Coffee' );
} );
