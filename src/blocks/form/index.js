import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, InnerBlocks, RichText, useBlockProps } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { Notice, PanelBody, SelectControl } from '@wordpress/components';
import './editor.css';

const getFormLabel = ( form ) => {
    if ( form?.title?.raw ) {
        return form.title.raw;
    }

    if ( form?.title?.rendered ) {
        return form.title.rendered.replace( /<[^>]+>/g, '' );
    }

    return form?.id ? `Form #${ form.id }` : 'Untitled form';
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
            { label: 'Select a saved form', value: 0 },
            ...( forms || [] ).map( ( form ) => ( {
                label: getFormLabel( form ),
                value: form.id,
            } ) ),
        ];
        const selectedForm = ( forms || [] ).find( ( form ) => form.id === formId );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Form Source" initialOpen={ true }>
                        <SelectControl
                            label="Saved form"
                            value={ formId }
                            options={ formOptions }
                            onChange={ ( value ) => setAttributes( { formId: Number( value ) || 0 } ) }
                        />
                    </PanelBody>
                </InspectorControls>

                { ! forms?.length ? (
                    <Notice status="warning" isDismissible={ false }>
                        Create a form in SwiftForms first, then select it here.
                    </Notice>
                ) : null }

                <p className="swiftforms-form-editor__description">
                    { selectedForm
                        ? `Embedding ${ getFormLabel( selectedForm ) }. Manage fields and allowed content in the Forms post type.`
                        : 'Select a saved form to embed on this page.' }
                </p>

                <div className="swiftforms-form-editor__preview">
                    { selectedForm
                        ? 'This block renders the selected form post on the frontend. Edit fields, notifications, captcha, and submit messaging on the form itself.'
                        : 'No form selected yet.' }
                </div>
            </div>
        );
    },

    save() {
        return null;
    },

    deprecated: [
        {
            save: legacySave,
        },
    ],
} );