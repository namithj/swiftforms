#!/usr/bin/env node
/**
 * Front-end performance budget: gzip every file under build/ and compare it
 * with the budgets in this file (view module <= 12 KB gz, front-end
 * CSS <= 4 KB gz) and refuse a jQuery dependency in any front-end .asset.php
 * (the view module and the front-end stylesheet; the editor and admin apps are
 * not front-end). Exits 1 when a file is over budget, a required file is
 * missing or a front-end asset needs jQuery. Run `npm run build` first.
 *
 *   node bin/check-perf-budget.mjs [build-dir]
 */
/* eslint-disable no-console */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';

/**
 * Budgets in gzipped bytes. `pattern` is matched against the path relative to
 * build/ (forward slashes). `required` budgets fail when no file matches.
 *
 * Only SwiftForms' own build/ files are counted. The block builder's own
 * editor.css (about 389 bytes gzipped) lives in vendor-prefixed/ and is not
 * counted here: Builder_Adapter removes it from the front end, so it never adds
 * to a visitor's page weight.
 */
export const BUDGETS = [
	{
		name: 'view module (JS)',
		pattern: /^view\/.*\.js$/,
		maxGzipBytes: 12 * 1024,
		required: true,
	},
	{
		name: 'front-end CSS',
		// style/form.css and its RTL twin; the editor-only files (style/editor*, editor/*) are not front-end weight.
		pattern: /^(?!editor\/|style\/editor).*\.css$/,
		maxGzipBytes: 4 * 1024,
		required: true,
	},
];

/**
 * Check measured file sizes against budgets.
 *
 * @param {Array<{path: string, gzipBytes: number}>} files   Measured files, paths relative to build/.
 * @param {Array}                                    budgets Budget map (defaults to BUDGETS).
 * @return {{ok: boolean, results: Array<{name: string, status: string, path?: string, gzipBytes?: number, maxGzipBytes: number}>}} Result.
 */
export function checkBudgets( files, budgets = BUDGETS ) {
	const results = [];

	for ( const budget of budgets ) {
		const matched = files.filter( ( file ) =>
			budget.pattern.test( file.path )
		);

		if ( matched.length === 0 ) {
			results.push( {
				name: budget.name,
				status: budget.required ? 'missing' : 'skipped',
				maxGzipBytes: budget.maxGzipBytes,
			} );
			continue;
		}

		for ( const file of matched ) {
			results.push( {
				name: budget.name,
				status: file.gzipBytes > budget.maxGzipBytes ? 'over' : 'ok',
				path: file.path,
				gzipBytes: file.gzipBytes,
				maxGzipBytes: budget.maxGzipBytes,
			} );
		}
	}

	return {
		ok: results.every(
			( r ) => r.status === 'ok' || r.status === 'skipped'
		),
		results,
	};
}

/**
 * Front-end asset files: everything except the editor and admin builds.
 */
const FRONT_END_ASSET = /^(?!editor\/|admin\/|style\/editor).*\.asset\.php$/;

/**
 * Front-end .asset.php files that list jQuery as a dependency.
 *
 * @param {Array<{path: string, source: string}>} assets Every .asset.php under build/ with its text.
 * @return {string[]} Paths of the offending files.
 */
export function findJqueryDependencies( assets ) {
	return assets
		.filter( ( asset ) => FRONT_END_ASSET.test( asset.path ) )
		.filter( ( asset ) =>
			/['"]jquery(?:-[a-z]+)?['"]/i.test( asset.source )
		)
		.map( ( asset ) => asset.path );
}

/**
 * List every file below a directory, as paths relative to it.
 *
 * @param {string} dir  Directory to walk.
 * @param {string} base Directory the returned paths are relative to.
 * @return {string[]} Relative file paths using forward slashes.
 */
function walk( dir, base = dir ) {
	if ( ! fs.existsSync( dir ) ) {
		return [];
	}
	return fs
		.readdirSync( dir, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const full = path.join( dir, entry.name );
			return entry.isDirectory()
				? walk( full, base )
				: [ path.relative( base, full ).split( path.sep ).join( '/' ) ];
		} );
}

function main() {
	const root = path.resolve(
		path.dirname( fileURLToPath( import.meta.url ) ),
		'..'
	);
	const buildDir = path.resolve(
		process.argv[ 2 ] || path.join( root, 'build' )
	);

	const files = walk( buildDir )
		.filter( ( rel ) => /\.(js|css)$/.test( rel ) )
		.map( ( rel ) => ( {
			path: rel,
			gzipBytes: zlib.gzipSync(
				fs.readFileSync( path.join( buildDir, rel ) ),
				{ level: 9 }
			).length,
		} ) );

	const { ok, results } = checkBudgets( files, BUDGETS );

	for ( const r of results ) {
		const size = r.gzipBytes === undefined ? '-' : `${ r.gzipBytes } B`;
		console.log(
			`${ r.status.toUpperCase().padEnd( 7 ) } ${ r.name }: ${ r.path || '(no file)' } ${ size } / ${ r.maxGzipBytes } B gz`
		);
	}

	const assets = walk( buildDir )
		.filter( ( rel ) => rel.endsWith( '.asset.php' ) )
		.map( ( rel ) => ( {
			path: rel,
			source: fs.readFileSync( path.join( buildDir, rel ), 'utf8' ),
		} ) );
	const jquery = findJqueryDependencies( assets );
	for ( const rel of jquery ) {
		console.log( `JQUERY  ${ rel } depends on jQuery` );
	}
	const frontAssets = assets.filter( ( a ) =>
		FRONT_END_ASSET.test( a.path )
	);
	console.log(
		`${ jquery.length ? 'FAIL   ' : 'OK     ' } no jQuery in ${ frontAssets.length } front-end .asset.php file(s)`
	);

	if ( ! ok || jquery.length > 0 ) {
		console.error( 'Performance budget failed.' );
		process.exit( 1 );
	}
	console.log( 'Performance budget OK.' );
}

if (
	process.argv[ 1 ] &&
	path.resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url )
) {
	main();
}
