#!/usr/bin/env node
/**
 * Front-end performance budget: gzip every file under build/ and compare it
 * with the budgets in this file (view module <= 12 KB gz, front-end
 * CSS <= 4 KB gz). Exits 1 when a file is over budget or a required file is
 * missing. Run `npm run build` first.
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
 * editor.css (about 389 bytes gzipped) is enqueued on every front-end page
 * once the builder has started; it lives in vendor-prefixed/, is not counted
 * here, and R01 must add it to the front-end CSS figure or filter it out.
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
		pattern: /^(?!editor).*\.css$/,
		maxGzipBytes: 4 * 1024,
		required: false,
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

	if ( ! ok ) {
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
