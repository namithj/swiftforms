const getSwiftFormsSettings = () => {
    return window.swiftformsSettings || {
        action: 'swiftforms_submit',
        ajaxUrl: '/wp-admin/admin-ajax.php',
        nonce: '',
    };
};

const getFieldValue = ( input ) => {
    if ( input instanceof HTMLInputElement && input.type === 'checkbox' ) {
        return input.checked ? input.value || '1' : '';
    }

    return input.value;
};

const collectFields = ( form ) => {
    return Array.from( form.querySelectorAll( '[data-swiftforms-field]' ) )
        .map( ( fieldNode ) => {
            const input = fieldNode.querySelector( 'input, textarea, select' );
            const slug = fieldNode.getAttribute( 'data-field-slug' ) || '';
            const type = fieldNode.getAttribute( 'data-field-type' ) || 'text';

            if ( ! input || ! slug ) {
                return null;
            }

            return {
                files: input instanceof HTMLInputElement && input.type === 'file' ? Array.from( input.files || [] ) : [],
                max: input.getAttribute( 'max' ) || '',
                min: input.getAttribute( 'min' ) || '',
                options: input instanceof HTMLSelectElement ? Array.from( input.options ).map( ( option ) => option.value ).filter( Boolean ) : [],
                required: input.required,
                slug,
                step: input.getAttribute( 'step' ) || '',
                type,
                value: getFieldValue( input ),
            };
        } )
        .filter( Boolean );
};

const setStatus = ( form, message, isSuccess ) => {
    const statusNode = form.querySelector( '[data-swiftforms-status]' );

    if ( ! statusNode ) {
        return;
    }

    statusNode.textContent = message;
    statusNode.dataset.state = isSuccess ? 'success' : 'error';
};

const submitSwiftForm = async ( form ) => {
    const settings = getSwiftFormsSettings();
    const formData = new FormData();
    const fields = collectFields( form );
    const honeypot = form.querySelector( '[data-swiftforms-honeypot]' );
    const submitButton = form.querySelector( '[type="submit"]' );

    formData.append( 'action', settings.action );
    formData.append( 'nonce', settings.nonce );
    formData.append( 'honeypot', honeypot ? honeypot.value : '' );
    formData.append( 'form_id', form.getAttribute( 'data-form-id' ) || '0' );
    formData.append( 'notifications[adminRecipients]', form.getAttribute( 'data-admin-recipients' ) || '' );
    formData.append( 'notifications[adminSubject]', form.getAttribute( 'data-admin-subject' ) || '' );
    formData.append( 'notifications[adminTemplate]', form.getAttribute( 'data-admin-template' ) || '' );
    formData.append( 'notifications[autoresponderSubject]', form.getAttribute( 'data-autoresponder-subject' ) || '' );
    formData.append( 'notifications[autoresponderTemplate]', form.getAttribute( 'data-autoresponder-template' ) || '' );

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
    }

    try {
        const response = await fetch( settings.ajaxUrl, {
            body: formData,
            credentials: 'same-origin',
            method: 'POST',
        } );
        const payload = await response.json();

        if ( payload.success ) {
            setStatus( form, form.getAttribute( 'data-success-message' ) || payload.message, true );
            form.reset();
            return;
        }

        const errorMessage = payload.message || Object.values( payload.errors || {} )[0] || 'Submission failed.';
        setStatus( form, errorMessage, false );
    } catch ( error ) {
        setStatus( form, 'Submission failed.', false );
        // eslint-disable-next-line no-console
        console.error( 'SwiftForms submission error', error );
    } finally {
        if ( submitButton ) {
            submitButton.disabled = false;
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