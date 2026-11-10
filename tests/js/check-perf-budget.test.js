import { describe, expect, it } from 'vitest';
import {
	BUDGETS,
	checkBudgets,
	findJqueryDependencies,
} from '../../bin/check-perf-budget.mjs';

describe( 'checkBudgets', () => {
	it( 'passes when the view module is within budget', () => {
		const { ok, results } = checkBudgets(
			[
				{ path: 'view/index.js', gzipBytes: 100 },
				{ path: 'style/form.css', gzipBytes: 100 },
			],
			BUDGETS
		);
		expect( ok ).toBe( true );
		expect( results.find( ( r ) => r.status === 'ok' ).path ).toBe(
			'view/index.js'
		);
	} );

	it( 'fails when the view module is over 12 KB gzipped', () => {
		const { ok, results } = checkBudgets(
			[ { path: 'view/index.js', gzipBytes: 12 * 1024 + 1 } ],
			BUDGETS
		);
		expect( ok ).toBe( false );
		expect( results.some( ( r ) => r.status === 'over' ) ).toBe( true );
	} );

	it( 'accepts exactly the budget', () => {
		expect(
			checkBudgets(
				[
					{ path: 'view/index.js', gzipBytes: 12 * 1024 },
					{ path: 'style/form.css', gzipBytes: 1 },
				],
				BUDGETS
			).ok
		).toBe( true );
	} );

	it( 'fails when a required file is missing', () => {
		const { ok, results } = checkBudgets( [], BUDGETS );
		expect( ok ).toBe( false );
		expect( results[ 0 ].status ).toBe( 'missing' );
	} );

	it( 'fails when the front-end stylesheet is missing', () => {
		const { ok, results } = checkBudgets(
			[ { path: 'view/index.js', gzipBytes: 1 } ],
			BUDGETS
		);
		expect( ok ).toBe( false );
		expect(
			results.find( ( r ) => r.name === 'front-end CSS' ).status
		).toBe( 'missing' );
	} );

	it( 'fails when front-end CSS is over 4 KB gzipped, but ignores editor CSS', () => {
		const files = [
			{ path: 'view/index.js', gzipBytes: 1 },
			{ path: 'view/style-index.css', gzipBytes: 4 * 1024 + 1 },
			{ path: 'editor/editor.css', gzipBytes: 99999 },
			{ path: 'style/editor.css', gzipBytes: 99999 },
		];
		const { ok, results } = checkBudgets( files, BUDGETS );
		expect( ok ).toBe( false );
		expect( results.filter( ( r ) => r.status === 'over' ) ).toHaveLength(
			1
		);
	} );
} );

describe( 'findJqueryDependencies', () => {
	const asset = ( dep ) =>
		`<?php return array( 'dependencies' => array( '${ dep }' ), 'version' => 'x' );`;

	it( 'flags a front-end asset that needs jQuery', () => {
		expect(
			findJqueryDependencies( [
				{ path: 'view/index.asset.php', source: asset( 'jquery' ) },
				{ path: 'style/form.asset.php', source: asset( 'wp-i18n' ) },
			] )
		).toEqual( [ 'view/index.asset.php' ] );
	} );

	it( 'does not look at the editor and admin builds', () => {
		expect(
			findJqueryDependencies( [
				{ path: 'editor/index.asset.php', source: asset( 'jquery' ) },
				{
					path: 'admin/settings/index.asset.php',
					source: asset( 'jquery' ),
				},
				{ path: 'style/editor.asset.php', source: asset( 'jquery' ) },
			] )
		).toEqual( [] );
	} );
} );
