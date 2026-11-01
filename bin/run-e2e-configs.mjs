#!/usr/bin/env node
/**
 * Runs the Playwright user-config checks, one config after another.
 *
 * For every tests/user-configs/NN-slug.php in numeric order (slugs starting
 * "19-" are skipped: multisite is not set up in wp-env and runs in PHPUnit
 * only) it resets the wp-env tests site, installs core, applies the config with
 * tests/user-configs/apply-cli.php, dumps the stored posts to
 * tests/e2e/.state/<slug>.json and runs tests/e2e/user-configs.spec.js with
 * SF_CONFIG=<slug>. Prints PASS or FAIL per config and exits 1 on any failure.
 *
 *   node bin/run-e2e-configs.mjs [slug ...]
 *
 * Needs a running wp-env (npx wp-env start).
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
const pluginDir = 'wp-content/plugins/swiftforms';
const stateDir = path.join( root, 'tests/e2e/.state' );
const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8889';

function run( command, args, options = {} ) {
	return spawnSync( command, args, {
		cwd: root,
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
		...options,
	} );
}

/**
 * Run a WP-CLI command inside the wp-env tests container.
 * @param {...any} args
 */
function wp( ...args ) {
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
	.filter( ( slug ) => requested.length === 0 || requested.includes( slug ) )
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

console.log( '\nSummary' );
for ( const [ slug, ok ] of outcomes ) {
	console.log( `  ${ ok ? 'PASS' : 'FAIL' }  ${ slug }` );
}
process.exit( outcomes.every( ( [ , ok ] ) => ok ) ? 0 : 1 );
