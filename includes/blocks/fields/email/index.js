import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import './editor.css';

registerBlockType( 'swiftforms/email-field', {
    edit( { attributes, setAttributes } ) {
        const { helpText, label, placeholder, required, slug } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--email' } );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Email Field Settings" initialOpen={ true }>
                        <TextControl label="Label" value={ label } onChange={ ( value ) => setAttributes( { label: value } ) } />
                        <TextControl label="Slug" value={ slug } onChange={ ( value ) => setAttributes( { slug: value.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        <TextControl label="Placeholder" value={ placeholder } onChange={ ( value ) => setAttributes( { placeholder: value } ) } />
                        <TextControl label="Help text" value={ helpText } onChange={ ( value ) => setAttributes( { helpText: value } ) } />
                        <ToggleControl label="Required" checked={ required } onChange={ ( value ) => setAttributes( { required: value } ) } />
                    </PanelBody>
                </InspectorControls>

                <RichText
                    tagName="span"
                    className="swiftforms-field__label"
                    value={ label }
                    onChange={ ( value ) => setAttributes( { label: value } ) }
                    placeholder="Email label"
                />
                <input type="email" disabled placeholder={ placeholder || 'name@example.com' } />
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },

    save( { attributes } ) {
        const { helpText, label, placeholder, required, slug } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-field swiftforms-field--email',
            'data-field-slug': slug || 'email',
            'data-field-type': 'email',
            'data-swiftforms-field': true,
        } );

        return (
            <div { ...blockProps }>
                <label className="swiftforms-field__control">
                    { label ? <RichText.Content tagName="span" className="swiftforms-field__label" value={ label } /> : null }
                    <input name={ slug || 'email' } placeholder={ placeholder || '' } required={ required } type="email" />
                </label>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
} );