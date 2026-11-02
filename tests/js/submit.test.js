import { describe, expect, it, vi } from 'vitest';
import {
	buildPayload,
	isStale,
	safeRedirect,
	submitWithRetry,
	tokenHours,
} from '../../src/view/submit';

describe( 'buildPayload', () => {
	it( 'builds sf, lists and controls, and replaces _sf_t', () => {
		const payload = buildPayload(
			[
				[ 'sf[name]', 'Ann' ],
				[ 'sf[tags][]', 'a' ],
				[ 'sf[tags][]', 'b' ],
				[ '_sf_form', 'F' ],
				[ '_sf_t', '123.abc' ],
				[ '_wpnonce', 'n' ],
				[ 'other', 'x' ],
				[ 'sf[file]', { name: 'file' } ],
			],
			1234.6
		);
		expect( payload ).toEqual( {
			sf: { name: 'Ann', tags: [ 'a', 'b' ] },
			_sf_form: 'F',
			_sf_t: 'js.1235',
		} );
	} );

	it( 'never sends a negative elapsed time', () => {
		expect( buildPayload( [], -5 )._sf_t ).toBe( 'js.0' );
	} );
} );

describe( 'token age', () => {
	const now = 500000 * 3600000;
	it( 'reads the hours from a token', () => {
		expect(
			tokenHours(
				'v1.' + ( 500000 ).toString( 36 ) + '.' + 'a'.repeat( 32 )
			)
		).toBe( 500000 );
		expect( tokenHours( 'garbage' ) ).toBe( 0 );
		expect( tokenHours( undefined ) ).toBe( 0 );
	} );
	it( 'is stale after 12 hours or when unknown', () => {
		expect( isStale( 500000, now ) ).toBe( false );
		expect( isStale( 499988, now ) ).toBe( false );
		expect( isStale( 499987, now ) ).toBe( true );
		expect( isStale( 0, now ) ).toBe( true );
		expect( isStale( undefined, now ) ).toBe( true );
	} );
} );

describe( 'safeRedirect', () => {
	it( 'allows http(s) only', () => {
		expect(
			safeRedirect( 'https://example.com/a', 'https://x.test/' )
		).toBe( 'https://example.com/a' );
		expect( safeRedirect( '/thanks', 'https://x.test/p' ) ).toBe(
			'https://x.test/thanks'
		);
		expect( safeRedirect( 'javascript:alert(1)', 'https://x.test/' ) ).toBe(
			''
		);
		expect( safeRedirect( 'data:text/html,hi', 'https://x.test/' ) ).toBe(
			''
		);
	} );
} );

describe( 'submitWithRetry', () => {
	const deps = ( responses, extra = {} ) => {
		const send = vi.fn();
		responses.forEach( ( r ) => {
			if ( r instanceof Error ) {
				send.mockRejectedValueOnce( r );
			} else {
				send.mockResolvedValueOnce( r );
			}
		} );
		return {
			send,
			refreshToken: vi.fn( async () => true ),
			refreshNonce: vi.fn( async () => true ),
			...extra,
		};
	};
	const res = ( status, body ) => ( { status, body } );

	it( 'returns success with the confirmation', async () => {
		const d = deps( [
			res( 200, {
				status: 'success',
				confirmation: { type: 'message', html: '<p>Hi</p>' },
			} ),
		] );
		expect( await submitWithRetry( d ) ).toEqual( {
			type: 'success',
			confirmation: { type: 'message', html: '<p>Hi</p>' },
		} );
	} );

	it( 'returns the field errors', async () => {
		const d = deps( [
			res( 422, { status: 'invalid', errors: { a: 'Bad' } } ),
		] );
		expect( await submitWithRetry( d ) ).toEqual( {
			type: 'invalid',
			errors: { a: 'Bad' },
		} );
	} );

	it( 'refreshes the token on expired and retries once', async () => {
		const d = deps( [
			res( 403, { status: 'expired' } ),
			res( 200, { status: 'success', confirmation: {} } ),
		] );
		expect( ( await submitWithRetry( d ) ).type ).toBe( 'success' );
		expect( d.refreshToken ).toHaveBeenCalledTimes( 1 );
		expect( d.send ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'gives up when the retry is expired too', async () => {
		const d = deps( [
			res( 403, { status: 'expired' } ),
			res( 403, { status: 'expired' } ),
		] );
		expect( await submitWithRetry( d ) ).toEqual( {
			type: 'error',
			kind: 'blocked',
		} );
		expect( d.refreshToken ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'refreshes the nonce on rest_cookie_invalid_nonce and retries once', async () => {
		const d = deps( [
			res( 403, { code: 'rest_cookie_invalid_nonce' } ),
			res( 200, { status: 'success', confirmation: {} } ),
		] );
		expect( ( await submitWithRetry( d ) ).type ).toBe( 'success' );
		expect( d.refreshNonce ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not loop on a second stale nonce', async () => {
		const d = deps( [
			res( 403, { code: 'rest_cookie_invalid_nonce' } ),
			res( 403, { code: 'rest_cookie_invalid_nonce' } ),
		] );
		expect( await submitWithRetry( d ) ).toEqual( {
			type: 'error',
			kind: 'blocked',
		} );
		expect( d.send ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'maps closed, rate_limited, blocked and unknown statuses', async () => {
		expect(
			await submitWithRetry(
				deps( [ res( 403, { status: 'closed' } ) ] )
			)
		).toEqual( { type: 'error', kind: 'closed' } );
		expect(
			await submitWithRetry(
				deps( [ res( 429, { status: 'rate_limited' } ) ] )
			)
		).toEqual( { type: 'error', kind: 'rate_limited' } );
		expect(
			await submitWithRetry(
				deps( [ res( 403, { status: 'blocked' } ) ] )
			)
		).toEqual( { type: 'error', kind: 'blocked' } );
		expect(
			await submitWithRetry(
				deps( [ res( 200, { status: 'surprise' } ) ] )
			)
		).toEqual( { type: 'error', kind: 'blocked' } );
		expect( await submitWithRetry( deps( [ res( 500, null ) ] ) ) ).toEqual(
			{ type: 'error', kind: 'blocked' }
		);
	} );

	it( 'reports a network error', async () => {
		expect(
			await submitWithRetry( deps( [ new Error( 'offline' ) ] ) )
		).toEqual( { type: 'error', kind: 'network' } );
	} );
} );
