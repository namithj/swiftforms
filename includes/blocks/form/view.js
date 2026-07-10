const getSwiftFormsSettings = () => {
    window.swiftformsSettings = window.swiftformsSettings || {
        action: 'swiftforms_submit',
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: '',
    };
    window.swiftformsSettings.i18n = {
        genericError: 'Submission failed.',
        required: 'This field is required.',
        next: 'Next',
        previous: 'Back',
        stepProgress: 'Step %1$s of %2$s',
        ...( window.swiftformsSettings.i18n || {} ),
    };

    return window.swiftformsSettings;
};

const getFieldValue = ( input ) => {
    if ( input instanceof HTMLInputElement && input.type === 'checkbox' ) {
        return input.checked ? input.value || '1' : '';
    }

    return input.value;
};

/**
 * Resolves the input a field wrapper submits from.
 *
 * Radio groups hold several inputs; the checked one wins (falling back to the
 * first so required/disabled state stays readable while nothing is selected).
 */
const resolveFieldInput = ( fieldNode ) => {
    if ( fieldNode.getAttribute( 'data-field-type' ) === 'radio' ) {
        return fieldNode.querySelector( 'input[type="radio"]:checked' ) || fieldNode.querySelector( 'input[type="radio"]' );
    }

    return fieldNode.querySelector( 'input, textarea, select' );
};

const collectFields = ( root ) => {
    return Array.from( root.querySelectorAll( '[data-swiftforms-field]' ) )
        .map( ( fieldNode ) => {
            const input = resolveFieldInput( fieldNode );
            const slug = fieldNode.getAttribute( 'data-field-slug' ) || '';
            const type = fieldNode.getAttribute( 'data-field-type' ) || 'text';

            // Disabled inputs never submit: conditionally hidden fields are
            // disabled (not just hidden) precisely so they drop out here.
            if ( ! input || ! slug || input.disabled ) {
                return null;
            }

            let value = getFieldValue( input );
            let options = input instanceof HTMLSelectElement ? Array.from( input.options ).map( ( option ) => option.value ).filter( Boolean ) : [];

            if ( type === 'radio' ) {
                value = input.checked ? input.value : '';
                options = Array.from( fieldNode.querySelectorAll( 'input[type="radio"]' ) ).map( ( radio ) => radio.value ).filter( Boolean );
            }

            return {
                files: input instanceof HTMLInputElement && input.type === 'file' ? Array.from( input.files || [] ) : [],
                input,
                max: input.getAttribute( 'max' ) || '',
                min: input.getAttribute( 'min' ) || '',
                options,
                required: input.required,
                slug,
                step: input.getAttribute( 'step' ) || '',
                type,
                value,
            };
        } )
        .filter( Boolean );
};

/**
 * Maximum fixed-point passes when resolving chained conditions.
 *
 * Mirrors SwiftForms_Conditions::MAX_PASSES so the frontend and the server
 * settle circular chains identically.
 */
const CONDITIONS_MAX_PASSES = 10;

const parseConditions = ( fieldNode ) => {
    const raw = fieldNode.getAttribute( 'data-sf-conditions' );

    if ( ! raw ) {
        return null;
    }

    try {
        return JSON.parse( raw );
    } catch ( error ) {
        return null;
    }
};

const getConditionValue = ( fieldNode ) => {
    const input = resolveFieldInput( fieldNode );

    if ( ! input ) {
        return '';
    }

    if ( input instanceof HTMLInputElement && input.type === 'file' ) {
        return input.files && input.files.length > 0 ? '1' : '';
    }

    if ( input instanceof HTMLInputElement && input.type === 'radio' ) {
        return input.checked ? input.value : '';
    }

    return getFieldValue( input );
};

/**
 * Evaluates a single rule against the slug => value map.
 *
 * Semantics mirror SwiftForms_Conditions::evaluate_rule(): equals/not_equals
 * compare trimmed strings case-sensitively, contains is case-insensitive,
 * empty/not_empty compare against ''. A missing slug evaluates as ''.
 */
const evaluateConditionRule = ( rule, values ) => {
    const actual = String( values[ rule.field ] ?? '' ).trim();
    const expected = String( rule.value ?? '' ).trim();

    switch ( rule.operator ) {
        case 'equals':
            return actual === expected;
        case 'not_equals':
            return actual !== expected;
        case 'contains':
            return expected !== '' && actual.toLowerCase().includes( expected.toLowerCase() );
        case 'empty':
            return actual === '';
        case 'not_empty':
            return actual !== '';
        default:
            return false;
    }
};

const isConditionVisible = ( conditions, values ) => {
    const groups = Array.isArray( conditions?.groups ) ? conditions.groups : [];

    if ( groups.length === 0 ) {
        return true;
    }

    const matched = groups.some(
        ( group ) => Array.isArray( group ) && group.length > 0 && group.every( ( rule ) => evaluateConditionRule( rule, values ) )
    );

    return conditions.action === 'hide' ? ! matched : matched;
};

const setConditionHidden = ( fieldNode, hidden ) => {
    fieldNode.hidden = hidden;

    if ( hidden ) {
        fieldNode.setAttribute( 'data-sf-condition-hidden', '1' );
    } else {
        fieldNode.removeAttribute( 'data-sf-condition-hidden' );
    }

    fieldNode.querySelectorAll( 'input, textarea, select' ).forEach( ( input ) => {
        input.disabled = hidden;
    } );
};

/**
 * Applies conditional visibility across a form until it stabilizes.
 *
 * A hidden field contributes '' to the next pass, so chained conditions
 * resolve; the pass cap keeps circular chains from cycling forever.
 */
const applyConditions = ( form ) => {
    const fieldNodes = Array.from( form.querySelectorAll( '[data-swiftforms-field]' ) );
    const conditioned = fieldNodes
        .map( ( fieldNode ) => ( { conditions: parseConditions( fieldNode ), fieldNode } ) )
        .filter( ( entry ) => entry.conditions );

    if ( conditioned.length === 0 ) {
        return;
    }

    const hiddenBySlug = {};

    for ( let pass = 0; pass < CONDITIONS_MAX_PASSES; pass++ ) {
        const values = {};

        fieldNodes.forEach( ( fieldNode ) => {
            const slug = fieldNode.getAttribute( 'data-field-slug' ) || '';

            if ( slug ) {
                values[ slug ] = hiddenBySlug[ slug ] ? '' : getConditionValue( fieldNode );
            }
        } );

        let changed = false;

        conditioned.forEach( ( { conditions, fieldNode } ) => {
            const slug = fieldNode.getAttribute( 'data-field-slug' ) || '';
            const hidden = ! isConditionVisible( conditions, values );

            if ( hidden !== Boolean( hiddenBySlug[ slug ] ) ) {
                hiddenBySlug[ slug ] = hidden;
                changed = true;
            }
        } );

        if ( ! changed ) {
            break;
        }
    }

    conditioned.forEach( ( { fieldNode } ) => {
        const slug = fieldNode.getAttribute( 'data-field-slug' ) || '';
        setConditionHidden( fieldNode, Boolean( hiddenBySlug[ slug ] ) );
    } );
};

const setStatus = ( form, message, isSuccess ) => {
    const statusNode = form.querySelector( '[data-swiftforms-status]' );

    if ( ! statusNode ) {
        return;
    }

    statusNode.textContent = message;
    statusNode.dataset.state = isSuccess ? 'success' : 'error';

    if ( typeof statusNode.scrollIntoView === 'function' ) {
        statusNode.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
    }
};

const clearFieldErrors = ( form ) => {
    form.querySelectorAll( '.swiftforms-field__error' ).forEach( ( node ) => node.remove() );
    form.querySelectorAll( '[data-swiftforms-field] [aria-invalid]' ).forEach( ( input ) => {
        input.removeAttribute( 'aria-invalid' );
        input.removeAttribute( 'aria-describedby' );
    } );
};

const renderFieldErrors = ( form, errors ) => {
    const formId = form.getAttribute( 'data-form-id' ) || '0';
    let firstInvalidInput = null;

    Object.entries( errors ).forEach( ( [ slug, message ] ) => {
        // Server slugs are sanitize_key()-restricted, so they're safe to
        // interpolate into a selector.
        const fieldNode = form.querySelector( `[data-swiftforms-field][data-field-slug="${ slug }"]` );

        if ( ! fieldNode ) {
            return;
        }

        const input = fieldNode.querySelector( 'input, textarea, select' );
        const errorId = `swiftforms-${ formId }-error-${ slug }`;
        const errorNode = document.createElement( 'p' );

        errorNode.className = 'swiftforms-field__error';
        errorNode.id = errorId;
        errorNode.textContent = message;
        fieldNode.appendChild( errorNode );

        if ( input ) {
            input.setAttribute( 'aria-invalid', 'true' );
            input.setAttribute( 'aria-describedby', errorId );

            if ( ! firstInvalidInput ) {
                firstInvalidInput = input;
            }
        }
    } );

    if ( firstInvalidInput ) {
        firstInvalidInput.focus();
    }
};

const isEmptyFieldValue = ( field ) => {
    if ( field.type === 'file' ) {
        return field.files.length === 0;
    }

    return field.value.trim() === '';
};

/**
 * Validates the collected fields locally before any network round-trip.
 *
 * The server remains authoritative — this only catches the obvious cases
 * (missing required values, type mismatches the browser can detect) to give
 * instant feedback.
 */
const validateClientSide = ( fields, i18n ) => {
    const errors = {};

    fields.forEach( ( field ) => {
        if ( field.required && isEmptyFieldValue( field ) ) {
            errors[ field.slug ] = i18n.required;
            return;
        }

        // The form is novalidate, so browser validation never blocks submit —
        // but per-input constraint checking still works and provides
        // localized messages for type mismatches (email, url, number ranges).
        if ( ! isEmptyFieldValue( field ) && ! field.input.checkValidity() ) {
            errors[ field.slug ] = field.input.validationMessage || i18n.genericError;
        }
    } );

    return errors;
};

const submitSwiftForm = async ( form, isRetry = false ) => {
    const settings = getSwiftFormsSettings();
    const formData = new FormData();
    const fields = collectFields( form );
    const honeypot = form.querySelector( '[data-swiftforms-honeypot]' );
    const submitButton = form.querySelector( '[type="submit"]' );

    const captchaAnswer = form.querySelector( '[data-swiftforms-captcha-answer]' );
    const captchaToken = form.querySelector( '[data-swiftforms-captcha-token]' );

    clearFieldErrors( form );

    const clientErrors = validateClientSide( fields, settings.i18n );
    if ( Object.keys( clientErrors ).length > 0 ) {
        renderFieldErrors( form, clientErrors );
        setStatus( form, Object.values( clientErrors )[ 0 ], false );
        return;
    }

    formData.append( 'action', settings.action );
    formData.append( 'nonce', settings.nonce );
    formData.append( 'honeypot', honeypot ? honeypot.value : '' );
    formData.append( 'form_id', form.getAttribute( 'data-form-id' ) || '0' );

    if ( captchaToken ) {
        formData.append( 'captcha_token', captchaToken.value );
        formData.append( 'captcha_answer', captchaAnswer ? captchaAnswer.value : '' );
    }

    const renderTimestamp = form.querySelector( '[data-swiftforms-render-ts]' );
    formData.append( 'render_ts', renderTimestamp ? renderTimestamp.value : '' );

    // Turnstile injects its own hidden input once the visitor passes the check.
    const turnstileResponse = form.querySelector( '[name="cf-turnstile-response"]' );
    if ( turnstileResponse ) {
        formData.append( 'cf_turnstile_response', turnstileResponse.value );
    }

    fields.forEach( ( field, index ) => {
        formData.append( `fields[${ index }][slug]`, field.slug );
        formData.append( `fields[${ index }][type]`, field.type );
        formData.append( `fields[${ index }][required]`, field.required ? '1' : '0' );

        if ( field.min ) {
            formData.append( `fields[${ index }][min]`, field.min );
        }

        if ( field.max ) {
            formData.append( `fields[${ index }][max]`, field.max );
        }

        if ( field.step ) {
            formData.append( `fields[${ index }][step]`, field.step );
        }

        if ( field.options.length ) {
            formData.append( `fields[${ index }][options]`, field.options.join( '\n' ) );
        }

        if ( field.type === 'file' && field.files.length ) {
            const file = field.files[ 0 ];

            formData.append( `fields[${ index }][value][name]`, file.name );
            formData.append( `fields[${ index }][value][size]`, String( file.size ) );
            formData.append( `swiftforms_files[${ index }]`, file, file.name );
            return;
        }

        formData.append( `fields[${ index }][value]`, field.value );
    } );

    if ( submitButton ) {
        submitButton.disabled = true;
        submitButton.setAttribute( 'aria-busy', 'true' );
        submitButton.classList.add( 'is-submitting' );
    }

    try {
        // admin-ajax is the browser path: its cookie handling verifies the
        // nonce for logged-in and anonymous visitors alike. The REST route
        // (settings.restUrl) exists for programmatic/headless clients, which
        // manage their own authentication.
        const response = await fetch( settings.ajaxUrl || settings.restUrl, {
            body: formData,
            credentials: 'same-origin',
            method: 'POST',
        } );
        const payload = await response.json();

        if ( payload.success ) {
            const redirectUrl = form.getAttribute( 'data-redirect-url' );

            if ( redirectUrl ) {
                window.location.assign( redirectUrl );
                return;
            }

            setStatus( form, form.getAttribute( 'data-success-message' ) || payload.message, true );
            form.reset();
            applyConditions( form );
            return;
        }

        // A cached page can serve a nonce that's already rotated server-side,
        // producing a spurious "session expired" for an otherwise legitimate
        // visitor. The server sends a fresh nonce alongside that error so we
        // can retry transparently once instead of forcing a page reload.
        if ( payload.code === 'invalid_nonce' && payload.nonce && ! isRetry ) {
            settings.nonce = payload.nonce;
            await submitSwiftForm( form, true );
            return;
        }

        if ( payload.errors ) {
            renderFieldErrors( form, payload.errors );
        }

        // Turnstile tokens are single-use; reset the widget so a corrected
        // resubmission gets a fresh token.
        if ( window.turnstile && form.querySelector( '.cf-turnstile' ) ) {
            window.turnstile.reset();
        }

        const errorMessage = payload.message || Object.values( payload.errors || {} )[ 0 ] || settings.i18n.genericError;
        setStatus( form, errorMessage, false );
    } catch ( error ) {
        setStatus( form, settings.i18n.genericError, false );
        // eslint-disable-next-line no-console
        console.error( 'SwiftForms submission error', error );
    } finally {
        if ( submitButton ) {
            submitButton.disabled = false;
            submitButton.removeAttribute( 'aria-busy' );
            submitButton.classList.remove( 'is-submitting' );
        }
    }
};

document.addEventListener( 'submit', ( event ) => {
    const form = event.target.closest( '[data-swiftforms-form]' );

    if ( ! form ) {
        return;
    }

    event.preventDefault();
    submitSwiftForm( form );
} );

/**
 * Turns a form containing two or more step containers into a paged form.
 *
 * The navigation is injected client-side (no PHP markup change): Back/Next
 * buttons plus an aria-live progress line. Inputs in inactive steps stay
 * enabled — unlike condition-hidden fields — so their values still submit
 * from the last step.
 */
const initSteps = ( form ) => {
    const steps = Array.from( form.querySelectorAll( '[data-swiftforms-step]' ) );

    if ( steps.length < 2 ) {
        return;
    }

    const { i18n } = getSwiftFormsSettings();
    const submitButton = form.querySelector( '[type="submit"]' );
    let currentIndex = 0;

    const progress = document.createElement( 'p' );
    progress.className = 'swiftforms-step-progress';
    progress.setAttribute( 'aria-live', 'polite' );

    const nav = document.createElement( 'div' );
    nav.className = 'swiftforms-step-nav';

    const previousButton = document.createElement( 'button' );
    previousButton.type = 'button';
    previousButton.className = 'swiftforms-step-nav__previous';
    previousButton.textContent = i18n.previous;

    const nextButton = document.createElement( 'button' );
    nextButton.type = 'button';
    nextButton.className = 'swiftforms-step-nav__next';
    nextButton.textContent = i18n.next;

    nav.append( previousButton, nextButton );

    if ( submitButton ) {
        submitButton.before( progress, nav );
    } else {
        form.append( progress, nav );
    }

    const render = () => {
        steps.forEach( ( step, index ) => {
            step.hidden = index !== currentIndex;
        } );

        previousButton.hidden = currentIndex === 0;
        nextButton.hidden = currentIndex === steps.length - 1;

        if ( submitButton ) {
            submitButton.hidden = currentIndex !== steps.length - 1;
        }

        const title = steps[ currentIndex ].getAttribute( 'data-step-title' ) || '';
        const position = i18n.stepProgress
            .replace( '%1$s', String( currentIndex + 1 ) )
            .replace( '%2$s', String( steps.length ) );
        progress.textContent = title ? `${ position } — ${ title }` : position;
    };

    nextButton.addEventListener( 'click', () => {
        clearFieldErrors( form );

        const errors = validateClientSide( collectFields( steps[ currentIndex ] ), i18n );

        if ( Object.keys( errors ).length > 0 ) {
            renderFieldErrors( form, errors );
            return;
        }

        currentIndex = Math.min( currentIndex + 1, steps.length - 1 );
        render();
    } );

    previousButton.addEventListener( 'click', () => {
        clearFieldErrors( form );
        currentIndex = Math.max( currentIndex - 1, 0 );
        render();
    } );

    render();
};

const reEvaluateConditions = ( event ) => {
    const form = event.target.closest ? event.target.closest( '[data-swiftforms-form]' ) : null;

    if ( form ) {
        applyConditions( form );
    }
};

document.addEventListener( 'input', reEvaluateConditions );
document.addEventListener( 'change', reEvaluateConditions );

const initSwiftForms = () => {
    document.querySelectorAll( '[data-swiftforms-form]' ).forEach( ( form ) => {
        applyConditions( form );
        initSteps( form );
    } );
};

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', initSwiftForms );
} else {
    initSwiftForms();
}
