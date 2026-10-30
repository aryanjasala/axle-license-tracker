const { test, expect, wp, wpEval, appPasswordClient, logInAs } = require( './utils' );
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
		await expect( freemius ).toContainText( `Expires: ${ day( -1 ) }` );
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
		await expect( edd ).toContainText( `${ renewal } set by hand` );
		await expect( edd ).toContainText( 'in 7 days' );
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
		expect( settings ).toEqual( { reminder_days: [ 20, 5 ], recipients: [ 'billing@example.com' ], alerts: true, digest: true } );

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
		await expect( widget.locator( 'li', { hasText: 'ALC EDD Fixture' } ) ).toContainText( day( 7 ) );
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

	test( 'uninstall removes options, records, cron events and the role', () => {
		const state = () =>
			wpEval(
				'echo wp_json_encode( array( get_option( "axle_license_tracker_settings", null ), get_option( "axle_license_tracker_detection", null ) !== null, count( get_posts( array( "post_type" => "alc_license", "post_status" => "any", "numberposts" => -1, "fields" => "ids" ) ) ) > 0, (bool) wp_next_scheduled( "axle_license_tracker_daily" ), (bool) get_role( "axle_license_reporter" ) ) );'
			);
		wp( 'option', 'update', 'axle_license_tracker_settings', '{"digest":true}', '--format=json' );
		expect( state() ).toBe( '[{"digest":true},true,true,true,true]' );

		// Run uninstall.php itself: `wp plugin uninstall` would delete the mounted source folder.
		wpEval( 'define( "WP_UNINSTALL_PLUGIN", "axle-license-tracker/axle-license-tracker.php" ); include WP_PLUGIN_DIR . "/axle-license-tracker/uninstall.php";' );
		expect( state() ).toBe( '[null,false,false,false,false]' );
		expect( wpEval( 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE \'\\_alc\\_%\'" );' ) ).toBe( '0' );

		// Activating again puts the role and the daily check back.
		wp( 'plugin', 'deactivate', 'axle-license-tracker' );
		wp( 'plugin', 'activate', 'axle-license-tracker' );
		expect( state() ).toBe( '[null,false,false,true,true]' );
	} );
} );
