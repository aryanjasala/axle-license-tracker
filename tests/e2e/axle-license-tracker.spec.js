const { request } = require( '@playwright/test' );
const { test, expect, wp, wpEval, appPasswordClient, logInAs } = require( './utils' );
const { BASE_URL } = require( './utils/config' );
const seed = require( './seed' );

const SCREEN = '/wp-admin/plugins.php?page=axle-license-tracker';
const ID = {
	freemius: 'plugin:alc-freemius-fixture/alc-freemius-fixture.php',
	edd: 'plugin:alc-edd-fixture/alc-edd-fixture.php',
	blocked: 'plugin:alc-blocked-fixture/alc-blocked-fixture.php',
	silent: 'plugin:alc-silent-fixture/alc-silent-fixture.php',
	bundled: 'plugin:alc-bundled-fixture/alc-bundled-fixture.php',
	hello: 'plugin:hello.php',
};
const THEME = process.env.WP_THEME || 'twentytwentyfive';

/** Whether a WP-CLI command succeeds. */
const succeeds = ( ...args ) => {
	try {
		wp( ...args );
		return true;
	} catch ( error ) {
		return false;
	}
};

/** A date relative to today on the site, Y-m-d. */
const day = ( offset ) => wpEval( `echo gmdate( "Y-m-d", strtotime( current_time( "Y-m-d" ) . " 00:00:00 UTC" ) + ( ${ Number( offset ) } * DAY_IN_SECONDS ) );` );

/** A meta value of a component's alc_license record, decoded, or null. */
const meta = ( id, key ) =>
	JSON.parse(
		wpEval(
			`$p = get_posts( array( "post_type" => "alc_license", "post_status" => "any", "numberposts" => 1, "fields" => "ids", "meta_key" => "_alc_component", "meta_value" => ${ JSON.stringify( id ) } ) );
			echo $p && metadata_exists( "post", $p[0], ${ JSON.stringify( key ) } ) ? wp_json_encode( get_post_meta( $p[0], ${ JSON.stringify( key ) }, true ) ) : "null";`
		)
	);

/** Emails the fixture caught. */
const mails = () => {
	try {
		return JSON.parse( wp( 'option', 'get', 'alc_fixture_mail', '--format=json' ) );
	} catch ( error ) {
		return [];
	}
};

const abilities = () => '1' === wpEval( 'echo function_exists( "wp_register_ability" ) ? 1 : 0;' );

/** Ids of the sites in the hub. */
const listedIds = () => JSON.parse( wp( 'axle-license-tracker', 'hub', 'list', '--format=json' ) ).map( ( site ) => site.id );

/** The table row of a component on the screen. */
const row = ( page, id ) => page.locator( `.alc-table tr[data-component="${ id }"]` );

test.describe( 'Axle License Tracker', () => {
	const people = [];

	test.beforeAll( () => {
		seed.removeFixtures();
		seed.installFixtures();
		seed.setFreemius( -1 );
		seed.setEdd( 'valid' );
		// The first check: core asks for updates, the scan runs and records are made.
		succeeds( 'axle-license-tracker', 'scan' );
	} );

	test.afterAll( () => {
		seed.removeFixtures();
		succeeds( 'theme', 'activate', THEME );
		for ( const login of people ) {
			succeeds( 'user', 'delete', login, '--yes' );
		}
	} );

	test( 'an expired Freemius license shows as expired, in Site Health, and makes scan exit 1', async ( { page } ) => {
		let status = 0;
		try {
			wp( 'axle-license-tracker', 'scan' );
		} catch ( error ) {
			status = error.status;
			expect( String( error.stdout ) ).toContain( 'ALC Freemius Fixture: expired' );
		}
		expect( status ).toBe( 1 );

		expect( seed.rows()[ ID.freemius ] ).toMatchObject( { type: 'premium', vendor: 'Freemius', sdk: 'Freemius', state: 'expired', expires: day( -1 ) } );
		expect( meta( ID.freemius, '_alc_state' ) ).toBe( 'expired' );
		expect( meta( ID.freemius, '_alc_key_fp' ) ).toMatch( /^[0-9a-f]{12}-5e4f$/ );

		await page.goto( SCREEN );
		const freemius = row( page, ID.freemius );
		await expect( freemius.locator( '.alc-state-expired' ) ).toHaveText( 'Expired' );
		await expect( freemius ).toContainText( 'Expires' );
		await expect( freemius.locator( `time.axle-time[datetime^="${ day( -1 ) }"]` ).first() ).toBeVisible();
		await expect( freemius ).toContainText( 'first read' );
		await expect( page.locator( '.alc-tile-expired .alc-tile-number' ) ).toHaveText( '1' );

		const health = JSON.parse( wpEval( '$t = apply_filters( "site_status_tests", array() ); echo wp_json_encode( call_user_func( $t["direct"]["axle_license_tracker"]["test"] ) );' ) );
		expect( [ 'recommended', 'critical' ] ).toContain( health.status );
		expect( health.description ).toContain( 'License expired: ALC Freemius Fixture' );

		await page.goto( '/wp-admin/site-health.php' );
		await expect( page.getByText( 'A premium plugin or theme cannot download its update' ) ).toBeVisible( { timeout: 20000 } );
	} );

	test( 'an EDD plugin with a valid key is valid, with its SDK named', async ( { page } ) => {
		expect( seed.rows()[ ID.edd ] ).toMatchObject( { type: 'premium', sdk: 'EDD Software Licensing', state: 'valid', risk: 'ok' } );
		expect( meta( ID.edd, '_alc_state' ) ).toBe( 'valid' );

		await page.goto( SCREEN );
		const edd = row( page, ID.edd );
		await expect( edd.locator( '.alc-state-valid' ) ).toBeVisible();
		await expect( edd ).toContainText( 'EDD Software Licensing' );
		await expect( edd.locator( '.alc-risk-ok' ) ).toHaveText( 'Updates arriving' );

		// Without a key it is missing.
		wp( 'option', 'update', 'alc_edd_fixture_license_key', '' );
		expect( seed.rows()[ ID.edd ].state ).toBe( 'missing' );
		seed.setEdd( 'valid' );
	} );

	test( 'an update offered with no package is blocked, with a badge on the Plugins screen', async ( { page } ) => {
		expect( seed.rows()[ ID.blocked ] ).toMatchObject( { type: 'premium', vendor: 'updates.example.com', risk: 'blocked' } );
		expect( meta( ID.blocked, '_alc_risk' ) ).toBe( 'blocked' );

		await page.goto( '/wp-admin/plugins.php' );
		const pluginRow = page.locator( 'tr[data-plugin="alc-blocked-fixture/alc-blocked-fixture.php"]' );
		await expect( pluginRow.locator( '.alc-risk-blocked' ) ).toHaveText( 'Update blocked' );
		await expect( page.locator( 'tr[data-plugin="hello.php"] .alc-pill' ) ).toHaveCount( 0 );
	} );

	test( 'an updater that stays silent for 31 days puts the plugin at risk', async ( { page } ) => {
		expect( seed.rows()[ ID.silent ] ).toMatchObject( { type: 'premium', vendor: 'example.invalid', risk: 'ok' } );

		seed.setLastSeen( ID.silent, 31 );
		expect( seed.rows()[ ID.silent ].risk ).toBe( 'at-risk' );

		await page.goto( SCREEN );
		const silent = row( page, ID.silent );
		await expect( silent.locator( '.alc-risk-at-risk' ) ).toHaveText( 'At risk' );
		await expect( silent ).toContainText( 'The updater has not answered for 31 days.' );
		expect( meta( ID.silent, '_alc_risk' ) ).toBe( 'at-risk' );
	} );

	test( 'a directory plugin needs no license and is not in the premium list', async ( { page } ) => {
		expect( seed.rows()[ ID.hello ] ).toMatchObject( { type: 'directory', state: 'not-applicable' } );
		expect( seed.rows( false )[ ID.hello ] ).toBeUndefined();
		expect( meta( ID.hello, '_alc_state' ) ).toBeNull();

		await page.goto( SCREEN );
		await expect( row( page, ID.hello ) ).toHaveCount( 0 );
		await page.getByRole( 'link', { name: /All plugins and themes/ } ).click();
		await expect( row( page, ID.hello ).locator( '.alc-state-not-applicable' ) ).toHaveText( 'Not needed' );
	} );

	test( 'a plugin the theme ships as a zip through TGMPA is bundled', async ( { page } ) => {
		expect( seed.rows()[ ID.bundled ].type ).toBe( 'custom' );
		wp( 'theme', 'activate', 'alc-fixture-theme' );
		try {
			expect( seed.rows()[ ID.bundled ] ).toMatchObject( { type: 'bundled', state: 'not-applicable', risk: 'watch' } );

			await page.goto( SCREEN );
			const bundled = row( page, ID.bundled );
			await expect( bundled ).toContainText( 'Bundled' );
			await expect( bundled ).toContainText( 'with ALC Fixture Theme' );
			await expect( bundled ).toContainText( 'Updates only arrive with ALC Fixture Theme updates.' );
			expect( meta( ID.bundled, '_alc_risk' ) ).toBe( 'watch' );
		} finally {
			wp( 'theme', 'activate', THEME );
		}
	} );

	test( 'details entered by hand win, a reminder goes out once, and costs add up per currency', async ( { page } ) => {
		const renewal = day( 7 );
		await page.addInitScript( () => {
			window.alcSaved = [];
			document.addEventListener( 'DOMContentLoaded', () => {
				window.wp.hooks.addAction( 'axleLicenseTracker.detailsSaved', 'e2e', ( component, fields ) => window.alcSaved.push( [ component, fields.owner ] ) );
				window.wp.hooks.addFilter( 'axleLicenseTracker.costFormat', 'e2e', ( text, minor, currency ) => `${ currency }:${ minor }` );
			} );
		} );

		await page.goto( SCREEN );
		await row( page, ID.edd ).locator( 'a[data-action="edit"]' ).click();
		await expect( page.getByRole( 'heading', { name: 'ALC EDD Fixture' } ) ).toBeVisible();

		// A bad cost saves nothing.
		await page.getByLabel( 'Cost per cycle' ).fill( 'a lot' );
		await page.getByLabel( 'Currency' ).fill( 'USD' );
		await page.getByRole( 'button', { name: 'Save details' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'Nothing was saved' );
		await expect( page.locator( '#alc-cost-error' ) ).toContainText( 'Enter the cost as a number' );
		await expect( page.getByLabel( 'Cost per cycle' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( meta( ID.edd, '_alc_cost' ) ).toBeNull();

		await page.getByLabel( 'Renewal date' ).fill( renewal );
		await page.getByLabel( 'Billing cycle' ).selectOption( 'yearly' );
		await page.getByLabel( 'Cost per cycle' ).fill( '199.00' );
		await page.getByLabel( 'Currency' ).fill( 'usd' );
		await page.getByLabel( 'Owner', { exact: true } ).fill( 'Bean There' );
		await page.getByLabel( 'Owner email' ).fill( 'owner@example.com' );
		await page.getByLabel( 'Auto-renew' ).selectOption( 'no' );
		await page.getByRole( 'button', { name: 'Save details' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'License details saved.' );
		expect( await page.evaluate( () => window.alcSaved ) ).toEqual( [ [ ID.edd, 'Bean There' ] ] );

		expect( meta( ID.edd, '_alc_renewal' ) ).toBe( renewal );
		expect( Number( meta( ID.edd, '_alc_cost' ) ) ).toBe( 19900 );
		expect( meta( ID.edd, '_alc_currency' ) ).toBe( 'USD' );
		expect( meta( ID.edd, '_alc_owner_email' ) ).toBe( 'owner@example.com' );
		expect( Number( meta( ID.edd, '_alc_saved_by' ) ) ).toBe( 1 );

		wp( 'axle-license-tracker', 'set', ID.freemius, '--cost=49', '--currency=EUR', '--cycle=yearly' );
		wp( 'axle-license-tracker', 'set', ID.silent, '--cost=5', '--currency=USD', '--cycle=monthly' );

		await page.goto( SCREEN );
		const edd = row( page, ID.edd );
		await expect( edd.locator( '.alc-state-expiring' ) ).toHaveText( 'Expiring' );
		await expect( edd ).toContainText( 'set by hand' );
		// Dates are shown relative, with the exact date in the title and the datetime.
		const due = edd.locator( `time.axle-time[datetime^="${ renewal }"]` );
		await expect( due ).toHaveText( /^in (\d+ (days?|hours?)|1 week)$/ );
		await expect( due ).toHaveAttribute( 'title', /20\d\d/ );
		await expect( edd.locator( '.alc-cost' ) ).toHaveText( 'USD:19900' );
		// 199.00 a year plus 5.00 a month in USD, 49.00 in EUR, never added together.
		await expect( page.locator( '.alc-tile-cost .alc-cost[data-currency="USD"]' ) ).toHaveText( 'USD:25900' );
		await expect( page.locator( '.alc-tile-cost .alc-cost[data-currency="EUR"]' ) ).toHaveText( 'EUR:4900' );
		expect( JSON.parse( wp( 'axle-license-tracker', 'export', '--format=json' ) ).totals.costs ).toEqual( { EUR: 4900, USD: 25900 } );

		// The daily run twice: the 7-day reminder goes out once.
		succeeds( 'option', 'delete', 'alc_fixture_mail' );
		wp( 'cron', 'event', 'run', 'axle_license_tracker_daily' );
		wp( 'cron', 'event', 'run', 'axle_license_tracker_daily' );
		const reminders = mails().filter( ( mail ) => mail.subject.includes( 'ALC EDD Fixture renews in 7 days' ) );
		expect( reminders ).toHaveLength( 1 );
		expect( reminders[ 0 ].to ).toEqual( [ 'admin@example.com', 'owner@example.com' ] );
		expect( reminders[ 0 ].message ).toContain( 'Cost: 199.00 USD.' );
		expect( meta( ID.edd, '_alc_reminders_sent' ) ).toContain( `renewal:${ renewal }:7` );
		// Alerts go out once too.
		expect( mails().filter( ( mail ) => mail.subject.includes( 'ALC Freemius Fixture: Expired' ) ) ).toHaveLength( 1 );
		expect( wp( 'axle-license-tracker', 'remind', '--dry-run', '--format=count' ) ).toBe( '0' );
	} );

	test( 'filters, the row actions hook and the reminder settings', async ( { page } ) => {
		await page.addInitScript( () => {
			document.addEventListener( 'DOMContentLoaded', () => {
				window.wp.hooks.addFilter( 'axleLicenseTracker.rowActions', 'e2e', ( actions, item ) =>
					'expired' === item.state ? actions.concat( [ { key: 'crm', label: 'Open in CRM', url: 'https://crm.example.com/' } ] ) : actions
				);
			} );
		} );
		await page.goto( SCREEN );
		await expect( row( page, ID.freemius ).locator( 'a[data-action="crm"]' ) ).toHaveText( 'Open in CRM' );
		await expect( row( page, ID.edd ).locator( 'a[data-action="crm"]' ) ).toHaveCount( 0 );

		await page.getByLabel( 'License state' ).selectOption( 'expired' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( page.locator( '.alc-table tbody tr' ) ).toHaveCount( 1 );
		await expect( row( page, ID.freemius ) ).toBeVisible();

		await page.goto( `${ SCREEN }&tab=settings` );
		await page.getByLabel( 'Remind before renewal' ).fill( '20, 5, nope, 400' );
		await page.getByLabel( 'Also send to' ).fill( 'billing@example.com, not-an-email' );
		await page.getByLabel( 'Send a monthly digest of every license' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'Settings saved.' );
		const settings = JSON.parse( wp( 'option', 'get', 'axle_license_tracker_settings', '--format=json' ) );
		expect( settings ).toEqual( { reminder_days: [ 20, 5 ], recipients: [ 'billing@example.com' ], alerts: true, digest: true, hub: false } );

		// The digest goes out once a month, to the extra recipient too.
		succeeds( 'option', 'delete', 'alc_fixture_mail' );
		wp( 'cron', 'event', 'run', 'axle_license_tracker_daily' );
		wp( 'cron', 'event', 'run', 'axle_license_tracker_daily' );
		const digests = mails().filter( ( mail ) => mail.subject.includes( 'Monthly license digest' ) );
		expect( digests ).toHaveLength( 1 );
		expect( digests[ 0 ].to ).toEqual( [ 'admin@example.com', 'billing@example.com' ] );
		wp( 'option', 'delete', 'axle_license_tracker_settings' );
	} );

	test( 'an ignored component stays listed but gets no reminders', async ( { page } ) => {
		await page.goto( SCREEN );
		await row( page, ID.silent ).locator( 'a[data-action="ignore"]' ).click();
		await page.getByLabel( 'Why (optional)' ).fill( 'Retired, removed next sprint' );
		await page.getByRole( 'button', { name: 'Ignore this component' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'The component is ignored.' );
		await expect( row( page, ID.silent ) ).toContainText( 'Ignored' );
		expect( meta( ID.silent, '_alc_ignored' ) ).toMatchObject( { note: 'Retired, removed next sprint', by: 1 } );
		// The notice offers Undo, and Undo tracks the component again.
		await expect( page.locator( '.alc-notice .alc-undo' ) ).toBeVisible();
		await page.locator( '.alc-notice .alc-undo' ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'The component is tracked again.' );
		expect( meta( ID.silent, '_alc_ignored' ) ).toBeNull();
		await row( page, ID.silent ).locator( 'a[data-action="ignore"]' ).click();
		await page.getByLabel( 'Why (optional)' ).fill( 'Retired, removed next sprint' );
		await page.getByRole( 'button', { name: 'Ignore this component' } ).click();
		await expect( row( page, ID.silent ) ).toContainText( 'Ignored' );

		succeeds( 'option', 'delete', 'alc_fixture_mail' );
		wp( 'cron', 'event', 'run', 'axle_license_tracker_daily' );
		expect( mails().filter( ( mail ) => mail.subject.includes( 'ALC Silent Fixture' ) ) ).toHaveLength( 0 );

		wp( 'axle-license-tracker', 'ignore', ID.silent, '--undo' );
		expect( meta( ID.silent, '_alc_ignored' ) ).toBeNull();
	} );

	test( 'license keys never leave the site', async ( { page, request, rest } ) => {
		const leaks = ( text ) => Object.values( seed.KEYS ).filter( ( key ) => String( text ).includes( key ) );

		await page.goto( SCREEN );
		expect( leaks( await page.content() ) ).toEqual( [] );
		await expect( row( page, ID.edd ).locator( '.alc-fp' ) ).toContainText( '... 0011' );

		const csv = await request.get( await page.locator( '.alc-exports a', { hasText: 'CSV' } ).getAttribute( 'href' ) );
		expect( csv.headers()[ 'content-type' ] ).toContain( 'text/csv' );
		const csvText = await csv.text();
		expect( csvText ).toContain( 'ALC EDD Fixture' );
		expect( leaks( csvText ) ).toEqual( [] );

		const json = await request.get( await page.locator( '.alc-exports a', { hasText: 'JSON' } ).getAttribute( 'href' ) );
		expect( leaks( await json.text() ) ).toEqual( [] );

		const report = await rest( 'GET', '/axle-license-tracker/v1/report' );
		expect( report.status ).toBe( 200 );
		expect( report.body.components.map( ( c ) => c.id ) ).toContain( ID.edd );
		expect( report.body.components.find( ( c ) => c.id === ID.edd ).fingerprint ).toMatch( /^[0-9a-f]{12}-0011$/ );
		expect( JSON.stringify( report.body ) ).not.toContain( 'owner@example.com' );
		expect( leaks( JSON.stringify( report.body ) ) ).toEqual( [] );

		expect( leaks( wp( 'axle-license-tracker', 'export', '--format=csv' ) ) ).toEqual( [] );
		expect( leaks( wp( 'axle-license-tracker', 'show', ID.edd, '--format=json' ) ) ).toEqual( [] );
		expect( leaks( wpEval( 'global $wpdb; echo wp_json_encode( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE \'_alc_%\'" ) );' ) ) ).toEqual( [] );

		if ( abilities() ) {
			const ability = await rest( 'GET', '/wp-abilities/v1/abilities/axle-license-tracker/get-license-report/run' );
			expect( ability.status ).toBe( 200 );
			expect( leaks( JSON.stringify( ability.body ) ) ).toEqual( [] );
		}
	} );

	test( 'the License reporter can read the report and nothing else', async ( { browser } ) => {
		people.push( 'alc-reporter' );
		succeeds( 'user', 'delete', 'alc-reporter', '--yes' );
		wp( 'user', 'create', 'alc-reporter', 'alc-reporter@example.com', '--role=axle_license_reporter', '--user_pass=reporter-pass-1' );
		expect( wpEval( 'echo user_can( get_user_by( "login", "alc-reporter" ), "axle_license_tracker_read_report" ) ? "yes" : "no";' ) ).toBe( 'yes' );

		const hub = await appPasswordClient( 'alc-reporter', 'Agency hub' );
		try {
			const report = await hub.call( 'GET', '/axle-license-tracker/v1/report' );
			expect( report.status ).toBe( 200 );
			expect( report.body.components.length ).toBeGreaterThan( 3 );
			expect( ( await hub.call( 'GET', '/wp/v2/users?context=edit' ) ).status ).toBe( 403 );
			expect( ( await hub.call( 'GET', '/wp/v2/settings' ) ).status ).toBe( 403 );
		} finally {
			await hub.dispose();
		}

		const { context, page } = await logInAs( browser, 'alc-reporter', 'reporter-pass-1' );
		try {
			const response = await page.goto( SCREEN );
			expect( response.status() ).toBe( 403 );
		} finally {
			await context.close();
		}
	} );

	test( 'an editor cannot see licenses', async ( { browser } ) => {
		people.push( 'alc-editor' );
		succeeds( 'user', 'delete', 'alc-editor', '--yes' );
		wp( 'user', 'create', 'alc-editor', 'alc-editor@example.com', '--role=editor', '--user_pass=editor-pass-1' );

		const editor = await appPasswordClient( 'alc-editor', 'Editor tools' );
		try {
			expect( ( await editor.call( 'GET', '/axle-license-tracker/v1/report' ) ).status ).toBe( 403 );
			if ( abilities() ) {
				expect( ( await editor.call( 'GET', '/wp-abilities/v1/abilities/axle-license-tracker/get-license-report/run' ) ).status ).toBe( 403 );
				const update = await editor.call( 'POST', '/wp-abilities/v1/abilities/axle-license-tracker/update-license-details/run', { input: { component: ID.edd, details: { owner: 'Editor' } } } );
				expect( update.status ).toBe( 403 );
				expect( meta( ID.edd, '_alc_owner' ) ).toBe( 'Bean There' );
			}
		} finally {
			await editor.dispose();
		}

		const { context, page } = await logInAs( browser, 'alc-editor', 'editor-pass-1' );
		try {
			expect( ( await page.goto( SCREEN ) ).status() ).toBe( 403 );
			await page.goto( '/wp-admin/index.php' );
			await expect( page.locator( '#axle_license_tracker' ) ).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );

	test( 'abilities report, list, update and ignore', async ( { rest } ) => {
		test.skip( ! abilities(), 'The Abilities API arrived in WordPress 6.9.' );

		const listed = await rest( 'GET', '/wp-abilities/v1/abilities/axle-license-tracker/get-license-report' );
		expect( listed.body.meta.annotations ).toMatchObject( { readonly: true } );

		const expiring = await rest( 'GET', '/wp-abilities/v1/abilities/axle-license-tracker/list-expiring/run?input[days]=10' );
		expect( expiring.status ).toBe( 200 );
		expect( expiring.body.map( ( entry ) => entry.id ) ).toEqual( expect.arrayContaining( [ ID.freemius, ID.edd ] ) );

		const one = await rest( 'GET', `/wp-abilities/v1/abilities/axle-license-tracker/get-component/run?input[component]=${ encodeURIComponent( ID.blocked ) }` );
		expect( one.body ).toMatchObject( { id: ID.blocked, risk: 'blocked' } );

		const updated = await rest( 'POST', '/wp-abilities/v1/abilities/axle-license-tracker/update-license-details/run', { input: { component: ID.blocked, details: { owner: 'Client', seats: 2 } } } );
		expect( updated.status ).toBe( 200 );
		expect( updated.body ).toMatchObject( { owner: 'Client', seats: 2 } );
		expect( meta( ID.blocked, '_alc_owner' ) ).toBe( 'Client' );

		const invalid = await rest( 'POST', '/wp-abilities/v1/abilities/axle-license-tracker/update-license-details/run', { input: { component: ID.blocked, details: { renewal: '31-12-2027' } } } );
		expect( invalid.status ).toBe( 500 );
		expect( invalid.body.code ).toBe( 'axle_license_tracker_invalid' );

		const ignored = await rest( 'POST', '/wp-abilities/v1/abilities/axle-license-tracker/ignore-component/run', { input: { component: ID.blocked, note: 'Agent test' } } );
		expect( ignored.body.ignored ).toBe( true );
		expect( meta( ID.blocked, '_alc_ignored' ) ).toMatchObject( { note: 'Agent test' } );
		await rest( 'POST', '/wp-abilities/v1/abilities/axle-license-tracker/ignore-component/run', { input: { component: ID.blocked, ignore: false } } );
		expect( meta( ID.blocked, '_alc_ignored' ) ).toBeNull();

		const recheck = await rest( 'POST', '/wp-abilities/v1/abilities/axle-license-tracker/recheck-licenses/run' );
		expect( recheck.status ).toBe( 200 );
		expect( recheck.body.totals.blocked ).toBe( 1 );

		const missing = await rest( 'GET', '/wp-abilities/v1/abilities/axle-license-tracker/get-component/run?input[component]=plugin:nope/nope.php' );
		expect( missing.status ).toBe( 404 );
	} );

	test( 'the dashboard widget lists the next renewals and what needs a look', async ( { page } ) => {
		await page.goto( '/wp-admin/index.php' );
		const widget = page.locator( '#axle_license_tracker' );
		await expect( widget ).toContainText( 'Needs a look' );
		await expect( widget.locator( 'li', { hasText: 'ALC Freemius Fixture' } ).locator( '.alc-state-expired' ) ).toBeVisible();
		await expect( widget.locator( 'li', { hasText: 'ALC EDD Fixture' } ).locator( `time.axle-time[datetime^="${ day( 7 ) }"]` ) ).toBeVisible();
	} );

	test( 'Check again rereads stored data and asks for updates', async ( { page } ) => {
		seed.setFreemius( 200 );
		await page.goto( SCREEN );
		await page.getByRole( 'button', { name: 'Check again' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'Licenses checked again.' );
		await expect( row( page, ID.freemius ).locator( '.alc-state-valid' ) ).toBeVisible();
		expect( meta( ID.freemius, '_alc_state' ) ).toBe( 'valid' );
		expect( Number( wpEval( 'echo get_site_transient( "update_plugins" )->last_checked;' ) ) ).toBeGreaterThan( Date.now() / 1000 - 120 );
		seed.setFreemius( -1 );
	} );

	test( 'privacy export and erase cover the emails in license details', () => {
		const exported = JSON.parse( wpEval( '$e = apply_filters( "wp_privacy_personal_data_exporters", array() ); echo wp_json_encode( call_user_func( $e["axle-license-tracker"]["callback"], "owner@example.com", 1 ) );' ) );
		expect( exported.data ).toHaveLength( 1 );
		expect( JSON.stringify( exported.data[ 0 ].data ) ).toContain( 'owner@example.com' );

		const erased = JSON.parse( wpEval( '$e = apply_filters( "wp_privacy_personal_data_erasers", array() ); echo wp_json_encode( call_user_func( $e["axle-license-tracker"]["callback"], "owner@example.com", 1 ) );' ) );
		expect( erased.items_removed ).toBe( true );
		expect( meta( ID.edd, '_alc_owner_email' ) ).toBeNull();
		expect( meta( ID.edd, '_alc_owner' ) ).toBe( 'Bean There' );
	} );

	test( 'the Licenses screen has a Help tab, a Get started card and a link on the Plugins screen', async ( { page } ) => {
		succeeds( 'user', 'meta', 'delete', '1', 'axle_license_tracker_steps_dismissed' );
		await page.goto( SCREEN );

		await page.locator( '#contextual-help-link' ).click();
		await expect( page.locator( '#tab-link-axle-license-tracker-overview' ) ).toBeVisible();
		await expect( page.locator( '#tab-link-axle-license-tracker-states' ) ).toBeVisible();
		await page.locator( '#contextual-help-link' ).click();

		const steps = page.locator( '.alc-steps' );
		await expect( steps ).toContainText( 'Get started' );
		await expect( steps.locator( '.axle-step' ) ).toHaveCount( 4 );
		await expect( steps.locator( '.axle-step' ).first().locator( '.axle-badge--good' ) ).toHaveText( 'Done' );
		await steps.getByRole( 'button', { name: 'Dismiss this card' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'The Get started card is hidden.' );
		await expect( steps ).toHaveCount( 0 );
		expect( wp( 'user', 'meta', 'get', '1', 'axle_license_tracker_steps_dismissed' ) ).toBe( '1' );
		succeeds( 'user', 'meta', 'delete', '1', 'axle_license_tracker_steps_dismissed' );

		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( 'tr[data-slug="axle-license-tracker"] .row-actions a', { hasText: 'Licenses' } ) ).toHaveAttribute( 'href', /page=axle-license-tracker/ );
	} );

	test( 'the Costs tab adds up the next 12 months per currency and lists what it left out', async ( { page } ) => {
		wp( 'axle-license-tracker', 'set', ID.edd, `--renewal=${ day( 40 ) }`, '--cost=100', '--currency=USD', '--cycle=yearly' );
		wp( 'axle-license-tracker', 'set', ID.blocked, `--renewal=${ day( 10 ) }`, '--cost=10', '--currency=EUR', '--cycle=monthly' );
		wp( 'axle-license-tracker', 'set', ID.silent, '--cost=5', '--currency=USD', '--cycle=monthly', '--renewal=' );

		await page.goto( `${ SCREEN }&tab=costs` );
		await expect( page.getByRole( 'heading', { name: 'The next 12 months' } ) ).toBeVisible();
		const forecast = JSON.parse( wp( 'axle-license-tracker', 'costs', '--format=json' ) );
		expect( forecast.totals.USD ).toBe( 10000 );
		expect( forecast.totals.EUR ).toBeGreaterThanOrEqual( 11 * 1000 );
		expect( forecast.no_date.map( ( item ) => item.name ) ).toContain( 'ALC Silent Fixture' );
		await expect( page.locator( '.alc-tile-total' ) ).toHaveCount( 2 );
		await expect( page.locator( '.alc-forecast tbody tr' ) ).toHaveCount( 12 );
		await expect( page.locator( '.alc-forecast' ).getByText( 'ALC EDD Fixture' ) ).toBeVisible();
		await expect( page.locator( '.alc-left-out [data-group="no_date"]' ) ).toContainText( 'ALC Silent Fixture' );

		// The same numbers as a CSV, with the left-out items after the charges.
		const href = await page.getByRole( 'link', { name: 'Download CSV' } ).getAttribute( 'href' );
		const csv = await ( await page.request.get( href ) ).text();
		expect( csv ).toContain( '"charge","' + day( 40 ) + '","' );
		expect( csv ).toContain( '"100.00"' );
		expect( csv ).toContain( '"no-date"' );
		expect( wp( 'axle-license-tracker', 'costs', '--format=csv' ) ).toContain( '"ALC EDD Fixture"' );

		wp( 'axle-license-tracker', 'set', ID.edd, '--renewal=' + day( 7 ), '--cost=199', '--currency=USD', '--cycle=yearly' );
		wp( 'axle-license-tracker', 'set', ID.blocked, '--cost=', '--currency=', '--cycle=', '--renewal=' );
		wp( 'axle-license-tracker', 'set', ID.silent, '--cost=5', '--currency=USD', '--cycle=monthly' );
	} );

	test( 'the calendar feed is off by default, serves renewals behind a secret link and never leaks keys', async ( { page } ) => {
		wp( 'axle-license-tracker', 'set', ID.edd, `--renewal=${ day( 20 ) }`, '--owner-email=owner@example.com' );
		const anonymous = await request.newContext( { baseURL: BASE_URL, storageState: { cookies: [], origins: [] } } );
		try {
			expect( wp( 'axle-license-tracker', 'feed', 'status' ) ).toBe( 'off' );
			expect( ( await anonymous.get( '/?axle-license-tracker-ics=' + 'a'.repeat( 40 ) ) ).status() ).toBe( 404 );

			await page.goto( `${ SCREEN }&tab=costs` );
			await expect( page.locator( '.alc-feed' ) ).toContainText( 'The calendar feed is off' );
			await page.getByRole( 'button', { name: 'Turn on the calendar feed' } ).click();
			await expect( page.locator( '.alc-notice' ) ).toContainText( 'The calendar feed is on.' );
			const url = await page.locator( '#alc-feed-url' ).inputValue();
			expect( url ).toMatch( /\?axle-license-tracker-ics=[A-Za-z0-9]{40}$/ );
			expect( JSON.parse( wp( 'option', 'get', 'axle_license_tracker_feed', '--format=json' ) ).token ).toHaveLength( 40 );

			const feed = await anonymous.get( url.replace( /^https?:\/\/[^/]+/, '' ) );
			expect( feed.status() ).toBe( 200 );
			expect( feed.headers()[ 'content-type' ] ).toContain( 'text/calendar' );
			expect( feed.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
			const ics = await feed.text();
			expect( ics ).toContain( 'BEGIN:VCALENDAR' );
			expect( ics ).toContain( 'SUMMARY:Renews: ALC EDD Fixture' );
			expect( ics ).toContain( 'DTSTART;VALUE=DATE:' + day( 20 ).replace( /-/g, '' ) );
			expect( ics ).toContain( 'Cost: ' );
			expect( ics ).not.toMatch( /ALCEDDKEY|sk_ALCFREEMIUSSECRET|owner@example\.com|[0-9a-f]{12}-0011/ );
			expect( ics ).not.toContain( 'wp-admin' );

			// A new link kills the old one, through the in-place confirm step.
			const regenerate = page.getByRole( 'button', { name: 'Make a new link', exact: true } );
			await expect( page.locator( '#alc-confirm-regenerate' ) ).toBeHidden();
			await regenerate.click();
			await expect( page.locator( '#alc-confirm-regenerate' ) ).toBeFocused();
			await expect( page.locator( '#alc-confirm-regenerate' ) ).toContainText( 'This cannot be undone' );
			await page.locator( '#alc-confirm-regenerate' ).getByRole( 'button', { name: 'Cancel' } ).click();
			await expect( page.locator( '#alc-confirm-regenerate' ) ).toBeHidden();
			await expect( regenerate ).toBeFocused();
			await regenerate.click();
			await page.locator( '#alc-confirm-regenerate' ).getByRole( 'button', { name: 'Make a new link' } ).click();
			await expect( page.locator( '.alc-notice' ) ).toContainText( 'The link is new.' );
			const fresh = await page.locator( '#alc-feed-url' ).inputValue();
			expect( fresh ).not.toBe( url );
			expect( ( await anonymous.get( url.replace( /^https?:\/\/[^/]+/, '' ) ) ).status() ).toBe( 404 );
			expect( ( await anonymous.get( fresh.replace( /^https?:\/\/[^/]+/, '' ) ) ).status() ).toBe( 200 );

			await page.locator( '.alc-confirm-trigger[data-alc-confirm="alc-confirm-disable"]' ).click();
			await page.locator( '#alc-confirm-disable' ).getByRole( 'button', { name: 'Turn off the feed' } ).click();
			await expect( page.locator( '.alc-notice' ) ).toContainText( 'The calendar feed is off.' );
			expect( ( await anonymous.get( fresh.replace( /^https?:\/\/[^/]+/, '' ) ) ).status() ).toBe( 404 );
			expect( wp( 'axle-license-tracker', 'feed', 'status' ) ).toBe( 'off' );
		} finally {
			await anonymous.dispose();
			succeeds( 'option', 'delete', 'axle_license_tracker_feed' );
			wp( 'axle-license-tracker', 'set', ID.edd, `--renewal=${ day( 7 ) }`, '--owner-email=owner@example.com' );
		}
	} );

	test( 'the hub shows the licenses of other sites, keeps the password encrypted and only reads', async ( { page } ) => {
		people.push( 'alc-hub-reporter' );
		succeeds( 'user', 'delete', 'alc-hub-reporter', '--yes' );
		wp( 'user', 'create', 'alc-hub-reporter', 'alc-hub-reporter@example.com', '--role=axle_license_reporter', '--user_pass=reporter-pass-9' );
		const password = wp( 'user', 'application-password', 'create', 'alc-hub-reporter', 'Hub', '--porcelain' );
		const secretsGone = () => {
			const stored = wp( 'option', 'get', 'axle_license_tracker_hub_sites', '--format=json' );
			expect( stored ).not.toContain( password );
			expect( stored ).not.toContain( password.replace( / /g, '' ) );
		};

		// Off by default: the tab explains, and one button turns it on.
		await page.goto( `${ SCREEN }&tab=sites` );
		await expect( page.getByRole( 'heading', { name: 'The hub is off' } ) ).toBeVisible();
		await expect( page.locator( '.alc-wrap' ).getByText( 'Add a site' ) ).toHaveCount( 0 );
		await page.getByRole( 'button', { name: 'Turn on the hub' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'The hub is on.' );
		expect( JSON.parse( wp( 'option', 'get', 'axle_license_tracker_settings', '--format=json' ) ).hub ).toBe( true );
		expect( wpEval( 'echo (bool) wp_next_scheduled( "axle_license_tracker_hub_sync" ) ? 1 : 0;' ) ).toBe( '1' );
		await expect( page.locator( '.alc-sites' ) ).toContainText( 'No other sites yet' );

		// This same site is its own remote. Inside the Docker network it answers as http://wp/, for the web and the CLI container alike.
		await page.getByLabel( 'Site address' ).fill( 'http://wp/' );
		await page.getByLabel( 'User name' ).fill( 'alc-hub-reporter' );
		await page.getByLabel( 'Application password' ).fill( password );
		await page.getByLabel( 'Name (optional)' ).fill( 'Loopback' );
		await page.getByRole( 'button', { name: 'Add site and check it' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( /Loopback was added and reported \d+ licenses?\./ );
		secretsGone();
		expect( wp( 'option', 'get', 'axle_license_tracker_hub_sites', '--format=json' ) ).toContain( '"secret":"v1:' );

		const siteRow = page.locator( '.alc-sites-table tr[data-site]' ).first();
		await expect( siteRow ).toContainText( 'Loopback' );
		await expect( siteRow.locator( '.axle-badge--good' ) ).toHaveText( 'Reached' );
		await expect( siteRow.locator( 'time.axle-time' ).first() ).toBeVisible();

		// The licenses of both sites in one table, and the same key found on both.
		const licenses = page.locator( '.alc-hub-table' );
		await expect( licenses.locator( 'tr', { hasText: 'ALC EDD Fixture' } ) ).toHaveCount( 2 );
		await expect( licenses.locator( 'tr', { hasText: 'ALC EDD Fixture' } ).first() ).toContainText( 'On 2 sites' );
		await expect( page.locator( '.alc-hub-dups' ) ).toContainText( 'The same license on several sites' );
		await expect( page.locator( '.alc-hub-costs' ) ).toContainText( 'USD' );
		await expect( page.locator( '.alc-tile-sites .axle-stat__value' ) ).toHaveText( '2' );

		await page.getByLabel( 'Search licenses' ).fill( 'Freemius' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( licenses.locator( 'tbody tr' ) ).toHaveCount( 2 );
		await page.getByLabel( 'Search licenses' ).fill( '' );
		await page.getByLabel( 'Site', { exact: true } ).selectOption( 'local' );
		await page.getByLabel( 'Expired or renewing in 30 days' ).check();
		await page.getByRole( 'button', { name: 'Filter' } ).click();
		await expect( licenses.locator( 'tbody tr' ) ).not.toHaveCount( 0 );
		await expect( licenses.locator( 'tbody tr[data-site="local"]' ) ).not.toHaveCount( 0 );

		// CLI and abilities agree, and neither shows a password or a key.
		const listed = JSON.parse( wp( 'axle-license-tracker', 'hub', 'list', '--format=json' ) );
		expect( listed ).toHaveLength( 1 );
		expect( listed[ 0 ].failures ).toBe( 0 );
		expect( JSON.stringify( listed ) ).not.toContain( password );
		const dups = JSON.parse( wp( 'axle-license-tracker', 'hub', 'licenses', '--duplicates', '--format=json' ) );
		expect( dups.length ).toBeGreaterThan( 0 );
		expect( wp( 'axle-license-tracker', 'hub', 'sync', '--all' ) ).toContain( 'Fetched 1 sites.' );
		if ( abilities() ) {
			const hub = await page.request.get( '/index.php?rest_route=/wp-abilities/v1/abilities/axle-license-tracker/get-hub-report/run', { headers: { 'X-WP-Nonce': ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim() } } );
			expect( hub.status() ).toBe( 200 );
			const body = JSON.stringify( await hub.json() );
			expect( body ).not.toContain( password );
			expect( body ).not.toMatch( /secret|ALCEDDKEY|sk_ALCFREEMIUSSECRET/ );
			expect( body ).toContain( 'Loopback' );
		}

		// Remove: the in-place confirm step says what is lost, then the site, password and data are gone.
		const id = listed[ 0 ].id;
		await page.goto( `${ SCREEN }&tab=sites` );
		const trigger = page.locator( `[data-alc-confirm="alc-remove-${ id }"]` );
		await page.evaluate( () => {
			window.confirmEvents = [];
			window.wp.hooks.addAction( 'axleLicenseTracker.confirmOpened', 'e2e', ( opened ) => window.confirmEvents.push( 'open:' + opened ) );
			window.wp.hooks.addAction( 'axleLicenseTracker.confirmClosed', 'e2e', ( closed ) => window.confirmEvents.push( 'close:' + closed ) );
		} );
		await trigger.click();
		const confirm = page.locator( `#alc-remove-${ id }` );
		await expect( confirm ).toBeFocused();
		await expect( confirm ).toContainText( 'This cannot be undone' );
		await page.keyboard.press( 'Escape' );
		await expect( confirm ).toBeHidden();
		await expect( trigger ).toBeFocused();
		expect( await page.evaluate( () => window.confirmEvents ) ).toEqual( [ `open:alc-remove-${ id }`, `close:alc-remove-${ id }` ] );
		expect( listedIds() ).toContain( id );
		await trigger.click();
		await confirm.getByRole( 'button', { name: 'Remove Loopback' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'Loopback was removed, with its password and its licenses.' );
		expect( listedIds() ).not.toContain( id );
		expect( wpEval( `echo get_option( "axle_license_tracker_hub_data_${ id }", "none" );` ) ).toBe( 'none' );

		// Turning the hub off keeps the Sites tab but offers Undo.
		await page.locator( '.alc-sites' ).waitFor();
		wp( 'axle-license-tracker', 'hub', 'add', 'http://wp/', '--username=alc-hub-reporter', `--password=${ password }`, '--label=Again' );
		await page.goto( `${ SCREEN }&tab=settings` );
		await page.getByLabel( 'Use this site as a hub for the licenses of other sites' ).uncheck();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		expect( wpEval( 'echo (bool) wp_next_scheduled( "axle_license_tracker_hub_sync" ) ? 1 : 0;' ) ).toBe( '0' );
		expect( listedIds() ).toHaveLength( 1 );
		await page.goto( `${ SCREEN }&tab=sites` );
		await expect( page.getByRole( 'heading', { name: 'The hub is off' } ) ).toBeVisible();
		wp( 'axle-license-tracker', 'hub', 'enable' );
		wp( 'axle-license-tracker', 'hub', 'remove', listedIds()[ 0 ], '--yes' );
		expect( listedIds() ).toHaveLength( 0 );
	} );

	test( 'a site that cannot be reached says why and is retried later, and private addresses are refused', async ( { page } ) => {
		wp( 'axle-license-tracker', 'hub', 'enable' );
		await page.goto( `${ SCREEN }&tab=sites` );
		await page.getByLabel( 'Site address' ).fill( 'http://localhost:9/' );
		await page.getByLabel( 'User name' ).fill( 'nobody' );
		await page.getByLabel( 'Application password' ).fill( 'abcd efgh ijkl mnop' );
		await page.getByRole( 'button', { name: 'Add site and check it' } ).click();
		await expect( page.locator( '.alc-notice' ) ).toContainText( 'was added, but the first check failed' );
		const failed = page.locator( '.alc-sites-table tr[data-site]' ).first();
		await expect( failed.locator( '.axle-badge--error' ) ).toHaveText( 'Could not be reached' );
		await expect( failed.locator( '.alc-error' ) ).toContainText( 'could not be reached' );
		const [ site ] = JSON.parse( wp( 'axle-license-tracker', 'hub', 'list', '--format=json' ) );
		expect( site.failures ).toBe( 1 );
		const next = Number( wpEval( `$s = get_option( "axle_license_tracker_hub_sites" ); echo $s[ "${ site.id }" ]["next"] - time();` ) );
		expect( next ).toBeGreaterThan( 50 * 60 );
		expect( next ).toBeLessThanOrEqual( 60 * 60 );
		expect( wp( 'axle-license-tracker', 'hub', 'list', '--format=json' ) ).not.toContain( 'abcd efgh' );

		// A site that keeps failing shows up in Site Health.
		wpEval( `$s = get_option( "axle_license_tracker_hub_sites" ); $s[ "${ site.id }" ]["fails"] = 3; update_option( "axle_license_tracker_hub_sites", $s, false );` );
		const health = JSON.parse( wpEval( '$t = apply_filters( "site_status_tests", array() ); echo wp_json_encode( call_user_func( $t["direct"]["axle_license_tracker_hub"]["test"] ) );' ) );
		expect( health.status ).toBe( 'recommended' );
		expect( health.description ).toContain( 'Could not be reached' );

		wp( 'axle-license-tracker', 'hub', 'remove', site.id, '--yes' );

		// With the test filter off, loopback and plain http are refused.
		wp( 'option', 'update', 'alc_fixture_allow_private', '0' );
		try {
			await page.goto( `${ SCREEN }&tab=sites` );
			await page.getByLabel( 'Site address' ).fill( 'https://localhost/' );
			await page.getByLabel( 'User name' ).fill( 'nobody' );
			await page.getByLabel( 'Application password' ).fill( 'abcd efgh ijkl mnop' );
			await page.getByRole( 'button', { name: 'Add site and check it' } ).click();
			await expect( page.locator( '.alc-notice' ) ).toContainText( 'points inside a private network' );
			await expect( page.getByLabel( 'Site address' ) ).toHaveValue( 'https://localhost/' );
			await expect( page.getByLabel( 'Application password' ) ).toHaveValue( '' );
			expect( succeeds( 'axle-license-tracker', 'hub', 'add', 'http://example.com', '--username=a', '--password=b' ) ).toBe( false );
			expect( listedIds() ).toHaveLength( 0 );
		} finally {
			wp( 'option', 'delete', 'alc_fixture_allow_private' );
			wp( 'axle-license-tracker', 'hub', 'disable' );
		}
	} );

	test( 'the report a License reporter reads has no emails and no paths', async () => {
		people.push( 'alc-redact-reporter' );
		succeeds( 'user', 'delete', 'alc-redact-reporter', '--yes' );
		wp( 'user', 'create', 'alc-redact-reporter', 'alc-redact-reporter@example.com', '--role=axle_license_reporter', '--user_pass=reporter-pass-8' );
		wp( 'axle-license-tracker', 'set', ID.edd, '--owner=Pat pat@agency.example', '--account-email=billing@example.com' );
		const reporter = await appPasswordClient( 'alc-redact-reporter', 'Privacy check' );
		try {
			const report = await reporter.call( 'GET', '/axle-license-tracker/v1/report' );
			expect( report.status ).toBe( 200 );
			const body = JSON.stringify( report.body );
			expect( body ).not.toMatch( /@example\.com|pat@agency|billing@/ );
			expect( body ).not.toMatch( /\/var\/www|wp-content\/plugins\// );
			expect( body ).not.toMatch( /ALCEDDKEY|sk_ALCFREEMIUSSECRET/ );
			expect( report.body.components.find( ( component ) => component.id === ID.edd ).owner ).toBe( 'Pat' );
		} finally {
			await reporter.dispose();
			wp( 'axle-license-tracker', 'set', ID.edd, '--owner=Bean There', '--account-email=billing@example.com' );
		}
	} );

	test( 'copying the calendar link is announced and hookable', async ( { page } ) => {
		wp( 'axle-license-tracker', 'feed', 'enable' );
		try {
			await page.addInitScript( () => {
				Object.defineProperty( navigator, 'clipboard', { value: { writeText: () => Promise.resolve() }, configurable: true } );
			} );
			await page.goto( `${ SCREEN }&tab=costs` );
			await page.evaluate( () => window.wp.hooks.addFilter( 'axleLicenseTracker.announce', 'e2e', ( text, kind ) => `${ kind }: ${ text }` ) );
			await page.getByRole( 'button', { name: 'Copy link' } ).click();
			await expect( page.locator( '#a11y-speak-assertive' ) ).toHaveText( 'copied: Link copied.' );
		} finally {
			wp( 'option', 'delete', 'axle_license_tracker_feed' );
		}
	} );

	test( 'uninstall removes options, records, cron events and the role', () => {
		const state = () =>
			wpEval(
				'echo wp_json_encode( array( get_option( "axle_license_tracker_settings", null ), get_option( "axle_license_tracker_detection", null ) !== null, count( get_posts( array( "post_type" => "alc_license", "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) ) ) > 0, (bool) wp_next_scheduled( "axle_license_tracker_daily" ), (bool) get_role( "axle_license_reporter" ) ) );'
			);
		wp( 'option', 'update', 'axle_license_tracker_settings', '{"digest":true}', '--format=json' );
		wp( 'option', 'update', 'axle_license_tracker_hub_sites', '{"s1":{"id":"s1"}}', '--format=json' );
		wp( 'option', 'update', 'axle_license_tracker_hub_data_s1', '{"components":[]}', '--format=json' );
		wp( 'option', 'update', 'axle_license_tracker_feed', '{"token":"x"}', '--format=json' );
		wp( 'user', 'meta', 'update', '1', 'axle_license_tracker_steps_dismissed', '1' );
		wpEval( 'wp_schedule_event( time() + 3600, "hourly", "axle_license_tracker_hub_sync" );' );
		expect( state() ).toBe( '[{"digest":true},true,true,true,true]' );

		// Run uninstall.php itself: `wp plugin uninstall` would delete the mounted source folder.
		wpEval( 'define( "WP_UNINSTALL_PLUGIN", "axle-license-tracker/axle-license-tracker.php" ); include WP_PLUGIN_DIR . "/axle-license-tracker/uninstall.php";' );
		expect( state() ).toBe( '[null,false,false,false,false]' );
		expect( wpEval( 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \'axle\\_license\\_tracker\\_%\'" );' ) ).toBe( '0' );
		expect( wpEval( 'echo (int) wp_next_scheduled( "axle_license_tracker_hub_sync" );' ) ).toBe( '0' );
		expect( wpEval( 'echo (int) metadata_exists( "user", 1, "axle_license_tracker_steps_dismissed" );' ) ).toBe( '0' );
		expect( wpEval( 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE \'\\_alc\\_%\'" );' ) ).toBe( '0' );

		// Activating again puts the role and the daily check back.
		wp( 'plugin', 'deactivate', 'axle-license-tracker' );
		wp( 'plugin', 'activate', 'axle-license-tracker' );
		expect( state() ).toBe( '[null,false,false,true,true]' );
	} );
} );
