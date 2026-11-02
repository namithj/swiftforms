/**
 * Pure helpers of the view module: payload, token age and the response handling (C-3.4).
 */

/** Hours after which the page's token is refreshed before a submit (the server accepts 168). */
export const STALE_HOURS = 12;

const HOUR_MS = 3600000;

/**
 * Hours since the epoch from a render token (`v1.{hours base 36}.{mac}`).
 *
 * @param {string} token Token.
 * @return {number} Hours, or 0 when the token is not in a known shape.
 */
export function tokenHours( token ) {
	const m = /^[a-z][a-z0-9]{0,7}\.([0-9a-z]{1,8})\.[0-9a-f]+$/.exec(
		String( token || '' )
	);
	return m ? parseInt( m[ 1 ], 36 ) || 0 : 0;
}

/**
 * Whether the token must be refreshed before a submit. An unknown age counts as stale.
 *
 * @param {number} issued Hours since the epoch when it was issued (0 or NaN when unknown).
 * @param {number} nowMs  Date.now().
 * @return {boolean} True when older than STALE_HOURS or unknown.
 */
export function isStale( issued, nowMs ) {
	const hours = Number( issued );
	return (
		! ( hours > 0 ) || Math.floor( nowMs / HOUR_MS ) - hours > STALE_HOURS
	);
}

/**
 * Build the JSON payload from the form's entries. `sf[x]` becomes sf.x, `sf[x][]` becomes a list, and the
 * `_sf_*` controls are copied; `_sf_t` is replaced by the time measured in the browser (`js.{ms}`).
 *
 * @param {Iterable<[string, *]>} entries   FormData entries.
 * @param {number}                elapsedMs Milliseconds since the view module hydrated the form.
 * @return {Object} Payload.
 */
export function buildPayload( entries, elapsedMs ) {
	const payload = { sf: {} };
	for ( const [ key, value ] of entries ) {
		if ( typeof value !== 'string' ) {
			continue;
		}
		const m = /^sf\[([^\]]+)\](\[\])?$/.exec( key );
		if ( m ) {
			if ( m[ 2 ] ) {
				payload.sf[ m[ 1 ] ] = ( payload.sf[ m[ 1 ] ] || [] ).concat(
					value
				);
			} else {
				payload.sf[ m[ 1 ] ] = value;
			}
		} else if ( /^_sf_/.test( key ) ) {
			payload[ key ] = value;
		}
	}
	payload._sf_t = 'js.' + Math.max( 0, Math.round( elapsedMs ) );
	return payload;
}

/**
 * Whether a URL is an absolute http(s) URL (the only kind a redirect may use).
 *
 * @param {string} url  URL.
 * @param {string} base Base for relative URLs.
 * @return {string} The normalised URL, or '' when not allowed.
 */
export function safeRedirect( url, base ) {
	try {
		const parsed = new URL( String( url ), base );
		return parsed.protocol === 'http:' || parsed.protocol === 'https:'
			? parsed.href
			: '';
	} catch {
		return '';
	}
}

/**
 * Send a submission and interpret the answer, with at most one token refresh and one nonce refresh.
 *
 * @param {Object}   deps
 * @param {Function} deps.send         () => Promise<{status:number, body:Object}>; rejects on a network error.
 * @param {Function} deps.refreshToken () => Promise<boolean>; fetches a fresh token and updates the form.
 * @param {Function} deps.refreshNonce () => Promise<boolean>; fetches a fresh REST nonce.
 * @return {Promise<Object>} `{type:'success', confirmation}`, `{type:'invalid', errors}` or `{type:'error', kind}` (closed, rate_limited, blocked or network).
 */
export async function submitWithRetry( { send, refreshToken, refreshNonce } ) {
	let retriedToken = false;
	let retriedNonce = false;

	for (;;) {
		let response;
		try {
			response = await send();
		} catch {
			return { type: 'error', kind: 'network' };
		}
		const body =
			response && response.body && typeof response.body === 'object'
				? response.body
				: {};

		if (
			response &&
			response.status === 403 &&
			body.code === 'rest_cookie_invalid_nonce'
		) {
			if ( ! retriedNonce ) {
				retriedNonce = true;
				if ( await refreshNonce() ) {
					continue;
				}
			}
			return { type: 'error', kind: 'blocked' };
		}

		switch ( body.status ) {
			case 'success':
				return {
					type: 'success',
					confirmation:
						body.confirmation &&
						typeof body.confirmation === 'object'
							? body.confirmation
							: {},
				};
			case 'invalid':
				return {
					type: 'invalid',
					errors:
						body.errors && typeof body.errors === 'object'
							? body.errors
							: {},
				};
			case 'expired':
				if ( ! retriedToken ) {
					retriedToken = true;
					if ( await refreshToken() ) {
						continue;
					}
				}
				return { type: 'error', kind: 'blocked' };
			case 'closed':
				return { type: 'error', kind: 'closed' };
			case 'rate_limited':
				return { type: 'error', kind: 'rate_limited' };
		}
		return { type: 'error', kind: 'blocked' }; // blocked and anything unknown.
	}
}
