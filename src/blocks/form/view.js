/**
 * Frontend submission logic for `swf/form`: field collection, client-side
 * pre-validation, conditional visibility, multi-step navigation, submit,
 * and response handling (including a silent one-time nonce refresh+retry
 * for forms sitting on a cached page).
 *
 * Loaded as a classic (non-module) viewScript, so it uses plain DOM APIs
 * and event delegation at the document level — dynamically inserted forms
 * (AJAX-loaded content, etc.) work without any re-init step.
 *
 * Uses static ES import/export — webpack bundles this into one plain
 * script at build time, so the runtime never needs actual ESM support.
 */

import { resolveVisibility } from '../../shared/conditions';

const settings = window.swfFormSettings || {
	restUrl: '',
	i18n: {
		genericError: 'Something went wrong. Please try again.',
		required: 'This field is required.',
		next: 'Next',
		previous: 'Previous',
		stepProgress: 'Step %1$d of %2$d',
	},
};

/**
 * Every `[data-swf-field]` element inside a form, with its resolved input.
 *
 * @param {HTMLFormElement} form
 */
function collectFields( form ) {
	return Array.from( form.querySelectorAll( '[data-swf-field]' ) ).map(
		( wrapper ) => {
			const type = wrapper.dataset.fieldType;
			const slug = wrapper.dataset.fieldSlug;
			const required = wrapper.dataset.fieldRequired === '1';
			const input = resolveFieldInput( wrapper, type );

			return { wrapper, type, slug, required, input };
		}
	);
}

/**
 * Resolves the "primary" input for a field wrapper (the checked radio, the
 * select element, etc.) so validation/collection has one thing to look at.
 *
 * @param {HTMLElement} wrapper The field's `[data-swf-field]` element.
 * @param {string}      type    Field type key.
 */
function resolveFieldInput( wrapper, type ) {
	if ( 'radio' === type || 'rating' === type ) {
		return (
			wrapper.querySelector( 'input[type="radio"]:checked' ) ||
			wrapper.querySelector( 'input[type="radio"]' )
		);
	}

	if ( 'checkbox' === type || 'consent' === type ) {
		return wrapper.querySelector( 'input[type="checkbox"]' );
	}

	return wrapper.querySelector( 'input, select, textarea' );
}

/**
 * The current value of a field for conditional-logic evaluation (always a
 * string; file inputs never drive conditions).
 *
 * @param {Object} field An entry from collectFields().
 */
function fieldValueForConditions( field ) {
	if ( ! field.input ) {
		return '';
	}

	if ( 'checkbox' === field.type || 'consent' === field.type ) {
		return field.input.checked ? field.input.value : '';
	}

	if ( 'radio' === field.type || 'rating' === field.type ) {
		const checked = field.wrapper.querySelector(
			'input[type="radio"]:checked'
		);
		return checked ? checked.value : '';
	}

	if ( 'file' === field.type ) {
		return '';
	}

	return field.input.value || '';
}

/**
 * Re-evaluates every field's conditional visibility and toggles `hidden` +
 * disables the inputs of hidden fields (disabled inputs are excluded from
 * FormData automatically, which is exactly how a hidden field's value
 * drops out of the submission).
 *
 * @param {HTMLFormElement} form
 */
function applyConditions( form ) {
	const fields = collectFields( form );
	const conditionsBySlug = {};
	const values = {};

	fields.forEach( ( field ) => {
		values[ field.slug ] = fieldValueForConditions( field );

		const raw = field.wrapper.dataset.sfConditions;
		if ( raw ) {
			try {
				conditionsBySlug[ field.slug ] = {
					conditions: JSON.parse( raw ),
				};
			} catch ( e ) {
				conditionsBySlug[ field.slug ] = { conditions: {} };
			}
		} else {
			conditionsBySlug[ field.slug ] = { conditions: {} };
		}
	} );

	const visibility = resolveVisibility( conditionsBySlug, values );

	fields.forEach( ( field ) => {
		const visible = visibility[ field.slug ] !== false;

		field.wrapper.hidden = ! visible;
		field.wrapper
			.querySelectorAll( 'input, select, textarea' )
			.forEach( ( el ) => {
				el.disabled = ! visible;
			} );
	} );
}

/**
 * Required-field + native constraint validation (email/url/number ranges,
 * etc. via `checkValidity()`), rendering inline errors. Returns whether the
 * given fields are all valid.
 *
 * @param {HTMLFormElement} form   The form being validated.
 * @param {Array}           fields Fields to validate (a subset for step validation, or all for final submit).
 */
function validateClientSide( form, fields ) {
	clearFieldErrors( form );

	let firstInvalid = null;

	fields.forEach( ( field ) => {
		if ( ! field.input || field.wrapper.hidden ) {
			return;
		}

		let message = '';

		if ( field.required && ! fieldHasValue( field ) ) {
			message = settings.i18n.required;
		} else if (
			field.input.checkValidity &&
			! field.input.checkValidity()
		) {
			message = field.input.validationMessage;
		}

		if ( message ) {
			showFieldError( field, message );
			firstInvalid = firstInvalid || field;
		}
	} );

	if ( firstInvalid ) {
		firstInvalid.input.focus();
		return false;
	}

	return true;
}

function fieldHasValue( field ) {
	if ( 'checkbox' === field.type || 'consent' === field.type ) {
		return !! field.input.checked;
	}

	if ( 'radio' === field.type || 'rating' === field.type ) {
		return !! field.wrapper.querySelector( 'input[type="radio"]:checked' );
	}

	if ( 'file' === field.type ) {
		return !! ( field.input.files && field.input.files.length );
	}

	return '' !== ( field.input.value || '' ).trim();
}

function showFieldError( field, message ) {
	const errorEl = field.wrapper.querySelector( '[data-swf-field-error]' );

	if ( errorEl ) {
		errorEl.textContent = message;
		errorEl.id = errorEl.id || `swf-error-${ field.slug }`;
		field.input.setAttribute( 'aria-invalid', 'true' );
		field.input.setAttribute( 'aria-describedby', errorEl.id );
	}
}

function clearFieldErrors( form ) {
	form.querySelectorAll( '[data-swf-field-error]' ).forEach( ( el ) => {
		el.textContent = '';
	} );
	form.querySelectorAll( '[aria-invalid="true"]' ).forEach( ( el ) => {
		el.removeAttribute( 'aria-invalid' );
	} );
}

function setStatus( form, message, isError ) {
	const status = form.querySelector( '[data-swf-status]' );

	if ( ! status ) {
		return;
	}

	status.textContent = message;
	status.classList.toggle( 'is-error', !! isError );
	status.classList.toggle( 'is-success', ! isError );
	status.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
}

/**
 * Builds the multipart FormData payload for one submission.
 *
 * @param {HTMLFormElement} form   The form being submitted.
 * @param {Array}           fields Fields from collectFields().
 */
function buildFormData( form, fields ) {
	const data = new FormData();

	data.append( 'form_id', form.dataset.formId || '' );
	data.append(
		'nonce',
		form.querySelector( 'input[name="nonce"]' )?.value || ''
	);
	data.append(
		'render_ts',
		form.querySelector( 'input[name="render_ts"]' )?.value || ''
	);
	data.append(
		'honeypot',
		form.querySelector( '[data-swf-honeypot]' )?.value || ''
	);

	const captchaToken = form.querySelector( 'input[name="captcha_token"]' );
	if ( captchaToken ) {
		data.append( 'captcha_token', captchaToken.value );
		data.append(
			'captcha_answer',
			form.querySelector( 'input[name="captcha_answer"]' )?.value || ''
		);
	}

	const turnstileResponse = form.querySelector(
		'input[name="cf-turnstile-response"]'
	);
	if ( turnstileResponse ) {
		data.append( 'cf_turnstile_response', turnstileResponse.value );
	}

	let index = 0;
	fields.forEach( ( field ) => {
		if ( field.wrapper.hidden || ! field.input ) {
			return;
		}

		if ( 'file' === field.type ) {
			if ( field.input.files && field.input.files[ 0 ] ) {
				data.append( field.slug, field.input.files[ 0 ] );
				data.append( `fields[${ index }][slug]`, field.slug );
				data.append( `fields[${ index }][value]`, '' );
				index++;
			}
			return;
		}

		data.append( `fields[${ index }][slug]`, field.slug );
		data.append(
			`fields[${ index }][value]`,
			fieldValueForConditions( field )
		);
		index++;
	} );

	return data;
}

async function submitForm( form, isRetry = false ) {
	const fields = collectFields( form );

	if ( ! validateClientSide( form, fields ) ) {
		return;
	}

	const submitButton = form.querySelector( '.swf-form__submit' );

	if ( submitButton ) {
		submitButton.disabled = true;
		submitButton.setAttribute( 'aria-busy', 'true' );
	}

	try {
		const response = await fetch( settings.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: buildFormData( form, fields ),
		} );

		const payload = await response.json();

		if ( payload.success ) {
			if ( form.dataset.redirectUrl ) {
				window.location.assign( form.dataset.redirectUrl );
				return;
			}

			setStatus(
				form,
				form.dataset.successMessage || payload.message,
				false
			);
			form.reset();
			applyConditions( form );
			resetToFirstStep( form );
			return;
		}

		if (
			'swf_invalid_nonce' === payload.code &&
			payload.nonce &&
			! isRetry
		) {
			const nonceInput = form.querySelector( 'input[name="nonce"]' );
			if ( nonceInput ) {
				nonceInput.value = payload.nonce;
			}
			await submitForm( form, true );
			return;
		}

		if ( payload.errors ) {
			applyServerErrors( form, fields, payload.errors );
		} else {
			setStatus(
				form,
				payload.message || settings.i18n.genericError,
				true
			);
		}

		resetTurnstile( form );
	} catch ( error ) {
		setStatus( form, settings.i18n.genericError, true );
	} finally {
		if ( submitButton ) {
			submitButton.disabled = false;
			submitButton.removeAttribute( 'aria-busy' );
		}
	}
}

function applyServerErrors( form, fields, errors ) {
	clearFieldErrors( form );

	let firstInvalid = null;

	Object.keys( errors ).forEach( ( slug ) => {
		const field = fields.find( ( f ) => f.slug === slug );
		if ( field ) {
			showFieldError( field, errors[ slug ] );
			firstInvalid = firstInvalid || field;
		}
	} );

	if ( firstInvalid && firstInvalid.input ) {
		firstInvalid.input.focus();
	}
}

function resetTurnstile( form ) {
	const widget = form.querySelector( '.cf-turnstile' );
	if ( widget && window.turnstile ) {
		window.turnstile.reset( widget );
	}
}

/* -------------------------------------------------------------------- */
/* Multi-step navigation                                                 */
/* -------------------------------------------------------------------- */

function initSteps( form ) {
	const steps = Array.from( form.querySelectorAll( '[data-swf-step]' ) );

	if ( steps.length < 2 || form.dataset.swfStepsInit ) {
		return;
	}

	form.dataset.swfStepsInit = '1';

	let current = 0;

	const actions = form.querySelector( '.swf-form__actions' );
	const submitButton = form.querySelector( '.swf-form__submit' );

	const progress = document.createElement( 'p' );
	progress.className = 'swf-step-progress';
	progress.setAttribute( 'aria-live', 'polite' );
	form.querySelector( '.swf-form__fields' )?.insertAdjacentElement(
		'beforebegin',
		progress
	);

	const prevButton = document.createElement( 'button' );
	prevButton.type = 'button';
	prevButton.className = 'swf-step-nav__button swf-step-nav__previous';
	prevButton.textContent = settings.i18n.previous;

	const nextButton = document.createElement( 'button' );
	nextButton.type = 'button';
	nextButton.className = 'swf-step-nav__button swf-step-nav__next';
	nextButton.textContent = settings.i18n.next;

	actions?.insertAdjacentElement( 'afterbegin', prevButton );
	actions?.insertBefore( nextButton, submitButton );

	function render() {
		steps.forEach( ( step, index ) => {
			step.hidden = index !== current;
		} );

		prevButton.hidden = 0 === current;
		nextButton.hidden = current === steps.length - 1;
		if ( submitButton ) {
			submitButton.hidden = current !== steps.length - 1;
		}

		progress.textContent = settings.i18n.stepProgress
			.replace( '%1$d', String( current + 1 ) )
			.replace( '%2$d', String( steps.length ) );
	}

	prevButton.addEventListener( 'click', () => {
		current = Math.max( 0, current - 1 );
		render();
	} );

	nextButton.addEventListener( 'click', () => {
		const stepFields = collectFields( form ).filter( ( field ) =>
			steps[ current ].contains( field.wrapper )
		);

		if ( ! validateClientSide( form, stepFields ) ) {
			return;
		}

		current = Math.min( steps.length - 1, current + 1 );
		render();
	} );

	render();
}

function resetToFirstStep( form ) {
	const steps = form.querySelectorAll( '[data-swf-step]' );
	if ( ! steps.length ) {
		return;
	}

	steps.forEach( ( step, index ) => {
		step.hidden = index !== 0;
	} );

	const prev = form.querySelector( '.swf-step-nav__previous' );
	const next = form.querySelector( '.swf-step-nav__next' );
	const submitButton = form.querySelector( '.swf-form__submit' );

	if ( prev ) {
		prev.hidden = true;
	}
	if ( next ) {
		next.hidden = steps.length < 2;
	}
	if ( submitButton ) {
		submitButton.hidden = steps.length >= 2;
	}

	const progress = form.querySelector( '.swf-step-progress' );
	if ( progress ) {
		progress.textContent = settings.i18n.stepProgress
			.replace( '%1$d', '1' )
			.replace( '%2$d', String( steps.length ) );
	}
}

/* -------------------------------------------------------------------- */
/* Event delegation — works for forms present at load and inserted later */
/* -------------------------------------------------------------------- */

document.addEventListener( 'submit', ( event ) => {
	const form = event.target.closest( '[data-swf-form]' );
	if ( form ) {
		event.preventDefault();
		submitForm( form );
	}
} );

document.addEventListener( 'input', ( event ) => {
	const form = event.target.closest( '[data-swf-form]' );
	if ( form ) {
		applyConditions( form );
	}
} );

document.addEventListener( 'change', ( event ) => {
	const form = event.target.closest( '[data-swf-form]' );
	if ( form ) {
		applyConditions( form );
	}
} );

document.querySelectorAll( '[data-swf-form]' ).forEach( ( form ) => {
	applyConditions( form );
	initSteps( form );
} );
