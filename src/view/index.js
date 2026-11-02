/**
 * SwiftForms front-end view module.
 *
 * Progressive enhancement of the server-rendered form (Interactivity API, no jQuery): client validation
 * that mirrors the server, an AJAX submit to swiftforms/v1/submit, and the token and nonce refresh paths
 * for pages that sit in a cache for days (ADR-002, C-3.7, C-7.4). Every context key is optional.
 *
 * The only HTML this module inserts is `confirmation.html` from the server. Everything else is
 * set with textContent.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';
import { validateWrapper } from './validation';
import {
	buildPayload,
	isStale,
	safeRedirect,
	submitWithRetry,
	tokenHours,
} from './submit';

const started = performance.now();
const hydrated = new WeakMap();
const sending = new WeakSet();
const refreshing = new WeakMap();

const { state } = store( 'swiftforms', {
	actions: {
		/**
		 * Submit handler: validate, then POST JSON. Without ajax the browser posts natively.
		 *
		 * @param {SubmitEvent} event Submit event.
		 */
		submit( event ) {
			const ctx = getContext();
			const form = event.currentTarget || event.target;
			if ( ctx.ajax === false || ! form || sending.has( form ) ) {
				if ( form && sending.has( form ) ) {
					event.preventDefault();
				}
				return undefined;
			}
			event.preventDefault();
			return send( form, ctx );
		},

		/**
		 * Live re-check of a field that is already marked invalid.
		 *
		 * @param {Event} event Input or focusout event.
		 */
		input( event ) {
			recheck( event.target );
		},

		focusout( event ) {
			recheck( event.target );
		},
	},
	callbacks: {
		/** Mark the form as managed by this module and start the clock for `_sf_t`. */
		init() {
			const { ref } = getElement();
			const ctx = getContext();
			hydrated.set( ref, performance.now() );
			if ( ctx.ajax !== false ) {
				ref.setAttribute( 'novalidate', '' );
			}
		},

		/** A tab that comes back to the front after hours gets a fresh token. */
		visible() {
			if ( document.visibilityState !== 'visible' ) {
				return;
			}
			const { ref } = getElement();
			const ctx = getContext();
			if ( ctx.ajax !== false && ! sending.has( ref ) && stale( ctx ) ) {
				refreshToken( ref, ctx );
			}
		},
	},
} );

// ---------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------

const field = ( form, name ) => form.querySelector( '[name="' + name + '"]' );
const value = ( form, name ) => ( field( form, name ) || {} ).value || '';
const text = ( key, fallback ) =>
	( state.i18n && state.i18n[ key ] ) || fallback || '';
const urlWith = ( url, params ) =>
	url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + params;

// Markup without `issued` (an older release, C-7.4) has an unknown token age: refresh before the first submit.
const stale = ( ctx ) => isStale( ctx.issued, Date.now() );

/**
 * Fetch a fresh token and signed time and put them into the form.
 *
 * @param {HTMLFormElement} form Form.
 * @param {Object}          ctx  Form context.
 * @return {Promise<boolean>} Whether the form now holds a fresh token.
 */
function refreshToken( form, ctx ) {
	if ( refreshing.has( form ) ) {
		return refreshing.get( form );
	}
	const base =
		state.tokenUrl || ( state.restUrl || '' ) + 'swiftforms/v1/token';
	const query =
		'formId=' +
		encodeURIComponent( ctx.formId || value( form, '_sf_form' ) ) +
		'&source=' +
		encodeURIComponent( ctx.source || value( form, '_sf_source' ) );
	const promise = fetch( urlWith( base, query ), {
		cache: 'no-store',
		credentials: 'same-origin',
		headers: { Accept: 'application/json' },
	} )
		.then( ( response ) => response.json() )
		.then( ( body ) => {
			if ( ! body || body.status !== 'success' || ! body.token ) {
				return false;
			}
			const token = field( form, '_sf_token' );
			const time = field( form, '_sf_t' );
			if ( token ) {
				token.value = body.token;
			}
			if ( time && body.t ) {
				time.value = body.t;
			}
			ctx.issued =
				tokenHours( body.token ) || Math.floor( Date.now() / 3600000 );
			return true;
		} )
		.catch( () => false )
		.then( ( ok ) => {
			refreshing.delete( form );
			return ok;
		} );
	refreshing.set( form, promise );
	return promise;
}

/**
 * Fetch a fresh REST nonce (logged-in visitors).
 *
 * @param {HTMLFormElement} form Form.
 * @return {Promise<boolean>} Whether a nonce came back.
 */
function refreshNonce( form ) {
	return fetch(
		urlWith( state.ajaxUrl || '', 'action=swiftforms_rest_nonce' ),
		{
			cache: 'no-store',
			credentials: 'same-origin',
		}
	)
		.then( ( response ) => response.json() )
		.then( ( body ) => {
			if ( ! body || typeof body.nonce !== 'string' || ! body.nonce ) {
				return false;
			}
			state.nonce = body.nonce;
			const input = field( form, '_wpnonce' );
			if ( input ) {
				input.value = body.nonce;
			}
			return true;
		} )
		.catch( () => false );
}

// ---------------------------------------------------------------------
// Errors.
// ---------------------------------------------------------------------

const wrappers = ( form ) =>
	Array.from( form.querySelectorAll( '.swiftforms-field' ) );

const controlsOf = ( wrapper ) =>
	Array.from( wrapper.querySelectorAll( 'input, select, textarea' ) );

const invalidWrapper = ( wrapper ) =>
	controlsOf( wrapper ).some(
		( c ) => c.getAttribute( 'aria-invalid' ) === 'true'
	);

function setFieldError( wrapper, message, control ) {
	const target = wrapper.querySelector( '.swiftforms-error' );
	controlsOf( wrapper ).forEach( ( c ) => {
		if ( message ) {
			c.setAttribute( 'aria-invalid', 'true' );
		} else {
			c.removeAttribute( 'aria-invalid' );
		}
	} );
	if ( control && control.setCustomValidity ) {
		control.setCustomValidity( message || '' );
	}
	if ( target ) {
		target.textContent = message || '';
		target.hidden = ! message;
	}
}

function summaryOf( form ) {
	let summary = form.querySelector( '.swiftforms-errors-summary' );
	if ( ! summary ) {
		summary = document.createElement( 'div' );
		summary.className = 'swiftforms-errors-summary';
		summary.hidden = true;
		form.insertBefore( summary, form.firstChild );
	}
	summary.setAttribute( 'tabindex', '-1' );
	return summary;
}

/**
 * Fill the summary. Items are `{text, href?}`; all text is set with textContent.
 *
 * @param {HTMLFormElement} form  Form.
 * @param {Array}           items Items.
 * @param {boolean}         focus Whether to move focus to the summary.
 */
function showSummary( form, items, focus ) {
	const summary = summaryOf( form );
	summary.replaceChildren();
	if ( items.length === 0 ) {
		summary.hidden = true;
		return;
	}
	const list = document.createElement( 'ul' );
	const lead = document.createElement( 'p' );
	const links = items.filter( ( item ) => item.href );
	if ( links.length > 0 ) {
		lead.textContent = text( 'summary', 'Please fix the following:' );
		summary.append( lead );
	}
	items.forEach( ( item ) => {
		if ( ! item.href ) {
			const p = document.createElement( 'p' );
			p.textContent = item.text;
			summary.append( p );
			return;
		}
		const li = document.createElement( 'li' );
		const a = document.createElement( 'a' );
		a.href = item.href;
		a.textContent = item.text;
		li.append( a );
		list.append( li );
	} );
	if ( links.length > 0 ) {
		summary.append( list );
	}
	summary.hidden = false;
	if ( focus ) {
		summary.focus();
	}
}

/**
 * Summary items for every field that is marked invalid.
 *
 * @param {HTMLFormElement} form Form.
 * @return {Array} Items.
 */
function currentItems( form ) {
	return wrappers( form )
		.filter( invalidWrapper )
		.map( ( wrapper ) => {
			const error = wrapper.querySelector( '.swiftforms-error' );
			const first = controlsOf( wrapper )[ 0 ];
			return {
				text: error ? error.textContent : '',
				href: first && first.id ? '#' + first.id : '',
			};
		} )
		.filter( ( item ) => item.text );
}

function validateAll( form ) {
	let firstProblem = null;
	wrappers( form ).forEach( ( wrapper ) => {
		const problem = validateWrapper( wrapper, state.messages );
		if ( problem || invalidWrapper( wrapper ) ) {
			setFieldError(
				wrapper,
				problem ? problem.message : '',
				problem && problem.control
			);
		}
		firstProblem = firstProblem || problem;
	} );
	return firstProblem;
}

function recheck( target ) {
	const wrapper =
		target && target.closest && target.closest( '.swiftforms-field' );
	const form = wrapper && wrapper.closest( 'form' );
	if ( ! wrapper || ! form || ! invalidWrapper( wrapper ) ) {
		return;
	}
	const problem = validateWrapper( wrapper, state.messages );
	setFieldError(
		wrapper,
		problem ? problem.message : '',
		problem && problem.control
	);
	if ( ! summaryOf( form ).hidden ) {
		showSummary( form, currentItems( form ), false );
	}
}

function showServerErrors( form, errors ) {
	const loose = [];
	Object.keys( errors ).forEach( ( name ) => {
		const message =
			typeof errors[ name ] === 'string' ? errors[ name ] : '';
		const control =
			message &&
			form.querySelector(
				'[name="sf[' + name + ']"], [name="sf[' + name + '][]"]'
			);
		const wrapper = control && control.closest( '.swiftforms-field' );
		if ( wrapper ) {
			setFieldError( wrapper, message, null );
		} else if ( message ) {
			loose.push( { text: message, href: '' } );
		}
	} );
	const items = currentItems( form ).concat( loose );
	showSummary(
		form,
		items.length ? items : [ { text: text( 'blocked' ), href: '' } ],
		true
	);
}

function showMessage( form, message ) {
	showSummary( form, [ { text: message, href: '' } ], true );
}

function showConfirmation( form, confirmation ) {
	if ( confirmation.type === 'redirect' ) {
		const url = safeRedirect( confirmation.url, window.location.href );
		if ( url ) {
			window.location.assign( url );
			return true;
		}
		return false;
	}
	if ( typeof confirmation.html !== 'string' ) {
		return false;
	}
	const box = document.createElement( 'div' );
	box.className = 'swiftforms-confirmation';
	box.setAttribute( 'role', 'status' );
	box.setAttribute( 'tabindex', '-1' );
	box.innerHTML = confirmation.html; // The one piece of HTML we insert: the server's confirmation.
	form.replaceChildren( box );
	form.classList.add( 'swiftforms-form--submitted' );
	form.removeAttribute( 'aria-busy' );
	box.focus();
	return true;
}

// ---------------------------------------------------------------------
// Submit.
// ---------------------------------------------------------------------

async function send( form, ctx ) {
	const problem = validateAll( form );
	if ( problem ) {
		showSummary( form, currentItems( form ), true );
		return;
	}
	showSummary( form, [], false );

	const buttons = Array.from( form.querySelectorAll( '[type="submit"]' ) );
	sending.add( form );
	form.setAttribute( 'aria-busy', 'true' );
	buttons.forEach( ( button ) => {
		button.disabled = true;
	} );

	try {
		if ( stale( ctx ) ) {
			await refreshToken( form, ctx );
		}

		const started0 = hydrated.has( form ) ? hydrated.get( form ) : started;
		const url =
			state.submitUrl || ( state.restUrl || '' ) + 'swiftforms/v1/submit';
		const result = await submitWithRetry( {
			send: async () => {
				const headers = {
					'Content-Type': 'application/json',
					Accept: 'application/json',
				};
				if ( state.nonce ) {
					headers[ 'X-WP-Nonce' ] = state.nonce;
				}
				const response = await fetch( url, {
					method: 'POST',
					credentials: 'same-origin',
					headers,
					body: JSON.stringify(
						buildPayload(
							new FormData( form ),
							performance.now() - started0
						)
					),
				} );
				let body = {};
				try {
					body = await response.json();
				} catch {
					// Not JSON: treated as blocked.
				}
				return { status: response.status, body };
			},
			refreshToken: () => refreshToken( form, ctx ),
			refreshNonce: () => refreshNonce( form ),
		} );

		sending.delete( form );
		if (
			result.type === 'success' &&
			showConfirmation( form, result.confirmation )
		) {
			return;
		}
		form.removeAttribute( 'aria-busy' );
		buttons.forEach( ( button ) => {
			button.disabled = false;
		} );
		if ( result.type === 'invalid' ) {
			showServerErrors( form, result.errors );
		} else {
			const kind = result.type === 'error' ? result.kind : 'blocked';
			const keys = {
				network: 'network',
				closed: 'closed',
				rate_limited: 'rateLimited',
			};
			showMessage( form, text( keys[ kind ] || 'blocked' ) );
		}
	} finally {
		if ( sending.has( form ) ) {
			sending.delete( form );
			form.removeAttribute( 'aria-busy' );
			buttons.forEach( ( button ) => {
				button.disabled = false;
			} );
		}
	}
}
