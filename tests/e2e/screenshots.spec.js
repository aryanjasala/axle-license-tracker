// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const { seedDemo, removeFixtures } = require( './seed' );

const SCREEN = '/wp-admin/plugins.php?page=axle-license-tracker';

test.describe( 'Screenshots', () => {
	let context;
	let page;

	test.beforeAll( async ( { browser } ) => {
		test.setTimeout( 180_000 );
		prepareSite( 'Bean There Coffee' );
		removeFixtures();
		seedDemo();

		context = await browser.newContext( { storageState: STORAGE_STATE, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
	} );

	test.afterAll( async () => {
		await context.close();
		removeFixtures();
	} );

	test( '1: the license list', async () => {
		await page.goto( SCREEN );
		await expect( page.locator( '.alc-table tbody tr' ) ).toHaveCount( 4 );
		await shot( page, 1, { fullPage: true } );
	} );

	test( '2: editing license details', async () => {
		await page.goto( `${ SCREEN }&edit=${ encodeURIComponent( 'plugin:alc-edd-fixture/alc-edd-fixture.php' ) }` );
		await expect( page.locator( '.alc-edit' ) ).toBeVisible();
		await shot( page, 2, { fullPage: true } );
	} );

	test( '3: license pills on the Plugins screen', async () => {
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.alc-pill' ).first() ).toBeVisible();
		await shot( page, 3 );
	} );

	test( '4: the dashboard widget', async () => {
		await page.goto( '/wp-admin/index.php' );
		const widget = page.locator( '#axle_license_tracker' );
		await expect( widget ).toBeVisible();
		await shot( page, 4 );
	} );
} );
