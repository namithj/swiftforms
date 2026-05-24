import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, InnerBlocks, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import './editor.css';

const ALLOWED_BLOCKS = [ 'swiftforms/text-field', 'swiftforms/email-field', 'swiftforms/textarea-field', 'swiftforms/url-field', 'swiftforms/file-field' ];
const TEMPLATE = [ [ 'swiftforms/text-field' ], [ 'swiftforms/email-field' ], [ 'swiftforms/textarea-field' ] ];

registerBlockType( 'swiftforms/form', {
    edit( { attributes, setAttributes } ) {
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
        const blockProps = useBlockProps( { className: 'swiftforms-form-editor' } );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Form Settings" initialOpen={ true }>
                        <TextControl
                            label="Form ID"
                            type="number"
                            value={ formId }
                            onChange={ ( value ) => setAttributes( { formId: Number( value ) || 0 } ) }
                        />
                        <TextControl
                            label="Submit label"
                            value={ submitLabel }
                            onChange={ ( value ) => setAttributes( { submitLabel: value } ) }
                        />
                        <TextControl
                            label="Success message"
                            value={ successMessage }
                            onChange={ ( value ) => setAttributes( { successMessage: value } ) }
                        />
                        <ToggleControl
                            label="Enable math captcha"
                            checked={ enableCaptcha }
                            onChange={ ( value ) => setAttributes( { enableCaptcha: value } ) }
                        />
                    </PanelBody>
                    <PanelBody title="Notifications" initialOpen={ false }>
                        <TextControl
                            label="Admin recipients"
                            help="Separate multiple emails with commas or new lines."
                            value={ adminRecipients }
                            onChange={ ( value ) => setAttributes( { adminRecipients: value } ) }
                        />
                        <TextControl
                            label="Admin subject"
                            value={ adminSubject }
                            onChange={ ( value ) => setAttributes( { adminSubject: value } ) }
                        />
                        <TextControl
                            label="Admin template"
                            help="Supports {submission_id}, {form_id}, {fields}, and {field:slug}."
                            value={ adminTemplate }
                            onChange={ ( value ) => setAttributes( { adminTemplate: value } ) }
                        />
                        <TextControl
                            label="Autoresponder subject"
                            value={ autoresponderSubject }
                            onChange={ ( value ) => setAttributes( { autoresponderSubject: value } ) }
                        />
                        <TextControl
                            label="Autoresponder template"
                            value={ autoresponderTemplate }
                            onChange={ ( value ) => setAttributes( { autoresponderTemplate: value } ) }
                        />
                    </PanelBody>
                </InspectorControls>

                <RichText
                    tagName="p"
                    className="swiftforms-form-editor__description"
                    value={ description }
                    onChange={ ( value ) => setAttributes( { description: value } ) }
                    placeholder="Add a short introduction for this form"
                />

                <div className="swiftforms-form-editor__fields">
                    <InnerBlocks allowedBlocks={ ALLOWED_BLOCKS } template={ TEMPLATE } />
                </div>

                <button type="button" className="swiftforms-form-editor__submit button button-primary">
                    { submitLabel || 'Send message' }
                </button>
            </div>
        );
    },

    save( { attributes } ) {
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
    },
} );