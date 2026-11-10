#!/usr/bin/env node
/**
 * Runs the Playwright user-config checks (S12 and S13), one config after another.
 *
 * For every tests/user-configs/NN-slug.php in numeric order (slugs starting "19-" are skipped: multisite is
 * not set up in the browser tests and runs in PHPUnit only) it resets the test site, installs core, applies the
 * config with tests/user-configs/apply-cli.php, dumps what the config stored to tests/e2e/.state/<slug>.json
 * and runs tests/e2e/user-configs.spec.js with SF_CONFIG=<slug>. Prints PASS or FAIL per config and exits 1 on any failure.
 *
 *   node bin/run-e2e-configs.mjs [slug-or-number-prefix ...]
 *
 * Two ways to reach the site's WP-CLI:
 *
 *   wp-env (CI, needs Docker, a running `npx wp-env start`): the default. WP_BASE_URL defaults to http://localhost:8889.
 *   a local WordPress (no Docker): set SF_E2E_WP_PATH to the folder of a WordPress install that has the plugin,
 *     the three fixture themes and the clock mu-plugin linked in (bin/setup-e2e-local-site.sh builds one), that is
 *     served at WP_BASE_URL (for example `wp server --port=8889` run in that folder) and uses its own database. The script
 *     resets that database for every config, so never point it at a site you care about.
 *
 * The plugin's clock stands still at 2026-11-19T10:00:00Z (the config time) while a config runs: the script writes it to
 * tests/e2e/.state/now, which the clock mu-plugin reads on every request, and removes the file at the end.
 */
import { spawnSync } from 'node:child_process';
/* eslint-disable no-console */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const stateDir = path.join( root, 'tests/e2e/.state' );
const clockFile = path.join( stateDir, 'now' );
const FROZEN_TIME = 1795082400;
const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8889';
const localPath = process.env.SF_E2E_WP_PATH || '';
// Where the plugin sits for the WP-CLI that runs eval-file: inside the container, or the real path.
const pluginDir = localPath ? root : 'wp-content/plugins/swiftforms';

function run( command, args, options = {} ) {
	return spawnSync( command, args, {
		cwd: root,
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
		...options,
	} );
}

/**
 * Run a WP-CLI command against the test site (wp-env's tests container, or the local WordPress).
 * @param {...any} args
 */
function wp( ...args ) {
	if ( localPath ) {
		return run( 'wp', [ `--path=${ localPath }`, ...args ] );
	}
	return run( 'npx', [ 'wp-env', 'run', 'tests-cli', 'wp', ...args ] );
}

function fail( result, label ) {
	console.error(
		`${ label } failed:\n${ result.stdout || '' }${ result.stderr || '' }`
	);
}

const requested = process.argv.slice( 2 );
const slugs = fs
	.readdirSync( path.join( root, 'tests/user-configs' ) )
	.filter( ( file ) => /^[0-9]{2}-.*\.php$/.test( file ) )
	.map( ( file ) => file.replace( /\.php$/, '' ) )
	.filter( ( slug ) => ! slug.startsWith( '19-' ) )
	.filter(
		( slug ) =>
			requested.length === 0 ||
			requested.some( ( wanted ) => slug.startsWith( wanted ) )
	)
	.sort();

if ( slugs.length === 0 ) {
	console.error( 'No user configs found in tests/user-configs/.' );
	process.exit( 1 );
}

fs.mkdirSync( stateDir, { recursive: true } );
const outcomes = [];

for ( const slug of slugs ) {
	console.log( `\n=== ${ slug } ===` );
	let ok = false;

	fs.writeFileSync( clockFile, String( FROZEN_TIME ) );

	const steps = [
		[ 'db reset', () => wp( 'db', 'reset', '--yes' ) ],
		[
			'core install',
			() =>
				wp(
					'core',
					'install',
					`--url=${ baseUrl }`,
					'--title=SwiftForms tests',
					'--admin_user=admin',
					'--admin_password=password',
					'--admin_email=admin@example.org',
					'--skip-email'
				),
		],
		[ 'activate plugin', () => wp( 'plugin', 'activate', 'swiftforms' ) ],
		[
			'apply config',
			() =>
				wp(
					'eval-file',
					`${ pluginDir }/tests/user-configs/apply-cli.php`,
					slug
				),
		],
	];

	let stepFailed = false;
	for ( const [ label, step ] of steps ) {
		const result = step();
		if ( result.status !== 0 ) {
			fail( result, `${ slug }: ${ label }` );
			stepFailed = true;
			break;
		}
	}

	if ( ! stepFailed ) {
		const dump = wp(
			'eval-file',
			`${ pluginDir }/tests/e2e/dump-posts.php`
		);
		const match = /SF_STATE_BEGIN\s*([\s\S]*?)\s*SF_STATE_END/.exec(
			dump.stdout || ''
		);
		if ( dump.status !== 0 || ! match ) {
			fail( dump, `${ slug }: post dump` );
		} else {
			fs.writeFileSync(
				path.join( stateDir, `${ slug }.json` ),
				match[ 1 ]
			);
			const result = run(
				'npx',
				[ 'playwright', 'test', 'tests/e2e/user-configs.spec.js' ],
				{
					stdio: 'inherit',
					encoding: undefined,
					env: {
						...process.env,
						SF_CONFIG: slug,
						SF_E2E_NOW_FILE: clockFile,
						WP_BASE_URL: baseUrl,
					},
				}
			);
			ok = result.status === 0;
		}
	}

	outcomes.push( [ slug, ok ] );
	console.log( `${ ok ? 'PASS' : 'FAIL' } ${ slug }` );
}

fs.rmSync( clockFile, { force: true } );

console.log( '\nSummary' );
for ( const [ slug, ok ] of outcomes ) {
	console.log( `  ${ ok ? 'PASS' : 'FAIL' }  ${ slug }` );
}
process.exit( outcomes.every( ( [ , ok ] ) => ok ) ? 0 : 1 );
