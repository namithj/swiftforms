import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, InnerBlocks, RichText, useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { Notice, PanelBody, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import './editor.css';

const LEGACY_ATTRIBUTES = {
    adminRecipients: {
        default: '',
        type: 'string',
    },
    adminSubject: {
        default: 'SwiftForms submission #{submission_id}',
        type: 'string',
    },
    adminTemplate: {
        default: '',
        type: 'string',
    },
    autoresponderSubject: {
        default: 'We received your submission',
        type: 'string',
    },
    autoresponderTemplate: {
        default: '',
        type: 'string',
    },
    description: {
        default: '',
        type: 'string',
    },
    enableCaptcha: {
        default: false,
        type: 'boolean',
    },
    formId: {
        default: 0,
        type: 'number',
    },
    submitLabel: {
        default: 'Send message',
        type: 'string',
    },
    successMessage: {
        default: 'Form submitted successfully.',
        type: 'string',
    },
};

const getFormLabel = ( form ) => {
    if ( form?.title?.raw ) {
        return form.title.raw;
    }

    if ( form?.title?.rendered ) {
        return form.title.rendered.replace( /<[^>]+>/g, '' );
    }

    /* translators: %d: form post ID. */
    return form?.id ? sprintf( __( 'Form #%d', 'swiftforms' ), form.id ) : __( 'Untitled form', 'swiftforms' );
};

const legacySave = ( { attributes } ) => {
    const {
        adminRecipients,
        adminSubject,
        adminTemplate,
        autoresponderSubject,
        autoresponderTemplate,
        description,
        enableCaptcha,
        formId,
        submitLabel,
        successMessage,
    } = attributes;
    const blockProps = useBlockProps.save( { className: 'swiftforms-form' } );

    return (
        <form
            { ...blockProps }
            data-admin-recipients={ adminRecipients || '' }
            data-admin-subject={ adminSubject || '' }
            data-admin-template={ adminTemplate || '' }
            data-autoresponder-subject={ autoresponderSubject || '' }
            data-autoresponder-template={ autoresponderTemplate || '' }
            data-enable-captcha={ enableCaptcha ? '1' : '0' }
            data-form-id={ formId || 0 }
            data-success-message={ successMessage || 'Form submitted successfully.' }
            data-swiftforms-form
            noValidate
        >
            { description ? (
                <RichText.Content
                    tagName="p"
                    className="swiftforms-form__description"
                    value={ description }
                />
            ) : null }
            <div className="swiftforms-form__status" data-swiftforms-status aria-live="polite"></div>
            <div className="swiftforms-form__fields">
                <InnerBlocks.Content />
            </div>
            <input
                aria-hidden="true"
                autoComplete="off"
                className="swiftforms-form__honeypot"
                data-swiftforms-honeypot
                name="swiftforms_hp"
                style={ { display: 'none' } }
                tabIndex="-1"
                type="text"
            />
            <button type="submit" className="swiftforms-form__submit">
                { submitLabel || 'Send message' }
            </button>
        </form>
    );
};

registerBlockType( 'swiftforms/form', {
    edit( { attributes, setAttributes } ) {
        const { formId } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-form-editor' } );
        const forms = useSelect(
            ( select ) => select( 'core' ).getEntityRecords( 'postType', 'swiftforms_form', {
                order: 'asc',
                orderby: 'title',
                per_page: -1,
            } ),
            []
        );
        const formOptions = [
            { label: __( 'Select a saved form', 'swiftforms' ), value: 0 },
            ...( forms || [] ).map( ( form ) => ( {
                label: getFormLabel( form ),
                value: form.id,
            } ) ),
        ];
        const selectedForm = ( forms || [] ).find( ( form ) => form.id === formId );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title={ __( 'Form Source', 'swiftforms' ) } initialOpen={ true }>
                        <SelectControl
                            label={ __( 'Saved form', 'swiftforms' ) }
                            value={ formId }
                            options={ formOptions }
                            onChange={ ( value ) => setAttributes( { formId: Number( value ) || 0 } ) }
                        />
                    </PanelBody>
                </InspectorControls>

                { ! forms?.length ? (
                    <Notice status="warning" isDismissible={ false }>
                        { __( 'Create a form in SwiftForms first, then select it here.', 'swiftforms' ) }
                    </Notice>
                ) : null }

                <p className="swiftforms-form-editor__description">
                    { selectedForm
                        ? /* translators: %s: form title. */
                          sprintf( __( 'Embedding %s.', 'swiftforms' ), getFormLabel( selectedForm ) )
                        : __( 'Select a saved form to embed on this page.', 'swiftforms' ) }
                </p>

                <div className="swiftforms-form-editor__preview">
                    { selectedForm
                        ? __( 'This block renders the selected form post on the frontend. Edit fields in the canvas and adjust form settings from the document sidebar.', 'swiftforms' )
                        : __( 'No form selected yet.', 'swiftforms' ) }
                </div>
            </div>
        );
    },

    save() {
        return null;
    },

    deprecated: [
        {
            attributes: LEGACY_ATTRIBUTES,
            save: legacySave,
        },
    ],
} );