/**
 * Captures the six wordpress.org screenshots (1280 wide) from a scratch WordPress that has the plugin active
 * and the demo content loaded: `wp eval-file` of assets/wporg-src/demo-setup.php with its first `require` line
 * removed, on a site titled "SwiftForms demo" with Twenty Twenty-Five, admin / password, and no personal data.
 *
 *   WP_BASE_URL=http://localhost:8889 NODE_PATH=<plugin>/node_modules node assets/wporg-src/capture-screenshots.js <out-dir> [1,2,...]
 *
 * Not part of the plugin ZIP. Copy the results to .wordpress-org/screenshot-N.png.
 */
const { chromium } = require( '@playwright/test' );
const base = process.env.WP_BASE_URL || 'http://localhost:8889';
const out = process.argv[ 2 ];
const only = process.argv[ 3 ] ? process.argv[ 3 ].split( ',' ) : null;
const want = ( n ) => ! only || only.includes( n );
const H = 900;
( async () => {
	const browser = await chromium.launch();
	if ( want( '2' ) ) {
		const guest = await browser.newContext( {
			viewport: { width: 1280, height: 1000 },
			reducedMotion: 'reduce',
		} );
		const gp = await guest.newPage();
		await gp.goto( base + '/contact/' );
		await gp.waitForSelector( 'form' );
		await gp.evaluate( () => window.scrollTo( 0, 130 ) );
		await gp.waitForTimeout( 500 );
		await gp.screenshot( { path: out + '/screenshot-2.png' } );
		await guest.close();
	}
	const ctx = await browser.newContext( {
		viewport: { width: 1280, height: H },
		reducedMotion: 'reduce',
	} );
	const page = await ctx.newPage();
	await page.goto( base + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	const ready = () =>
		page.waitForFunction(
			() =>
				window.wp &&
				window.wp.data &&
				window.wp.data.select( 'core/block-editor' ).getBlocks()
					.length > 0,
			null,
			{ timeout: 40000 }
		);

	if ( want( '1' ) ) {
		await page.goto( base + '/wp-admin/post.php?post=100&action=edit' );
		await ready();
		await page.evaluate( () => {
			const d = window.wp.data;
			d.dispatch( 'core/preferences' ).set(
				'core/edit-post',
				'welcomeGuide',
				false
			);
			const form = d
				.select( 'core/block-editor' )
				.getBlocks()
				.find( ( b ) => b.name === 'swiftforms/form' );
			d.dispatch( 'core/block-editor' ).selectBlock( form.clientId );
			d.dispatch( 'core/edit-post' ).openGeneralSidebar(
				'edit-post/block'
			);
		} );
		await page.waitForTimeout( 1500 );
		await page.screenshot( { path: out + '/screenshot-1.png' } );
	}
	if ( want( '3' ) ) {
		await page.goto( base + '/wp-admin/post-new.php?post_type=page' );
		await page.waitForFunction(
			() =>
				window.wp &&
				window.wp.data &&
				window.wp.data.select( 'core/editor' ),
			null,
			{ timeout: 40000 }
		);
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false );
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core', 'enableChoosePatternModal', false );
		} );
		await page.reload();
		const toggle = page.getByRole( 'button', {
			name: /Block Inserter|Toggle block inserter/,
		} );
		await toggle.first().click();
		await page.getByRole( 'tab', { name: 'Patterns' } ).click();
		await page.waitForTimeout( 2000 );
		await page.waitForTimeout( 4000 );
		await page.screenshot( { path: out + '/probe-3c.png' } );
		await page
			.locator(
				'.block-editor-inserter__panel-content, .block-editor-inserter__menu'
			)
			.getByText( 'Forms', { exact: true } )
			.first()
			.click();
		await page.waitForTimeout( 4000 );
		await page.screenshot( { path: out + '/screenshot-3.png' } );
	}
	if ( want( '4' ) || want( '5' ) ) {
		// The list first (an unread starred row is visible); opening an entry marks it read.
		await page.goto( base + '/wp-admin/admin.php?page=swiftforms' );
		await page.waitForSelector( 'text=Aisha Rahman' );
		await page.waitForTimeout( 800 );
		if ( want( '4' ) ) {
			await page.screenshot( { path: out + '/screenshot-4.png' } );
		}
		if ( want( '5' ) ) {
			await page.click(
				'#the-list a.row-title:has-text("Aisha Rahman")'
			);
			await page.waitForSelector( 'table.swiftforms-entry-fields' );
			await page.waitForTimeout( 800 );
			await page.screenshot( { path: out + '/screenshot-5.png' } );
		}
	}
	if ( want( '6' ) ) {
		await page.goto(
			base + '/wp-admin/admin.php?page=swiftforms-settings'
		);
		await page.getByRole( 'tab', { name: 'Email' } ).click();
		await page.waitForTimeout( 1500 );
		await page.screenshot( { path: out + '/screenshot-6.png' } );
	}
	await browser.close();
} )();
