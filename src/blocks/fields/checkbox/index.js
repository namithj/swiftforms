import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import './editor.css';

registerBlockType( 'swiftforms/checkbox-field', {
    edit( { attributes, setAttributes } ) {
        const { checkboxLabel, helpText, label, required, slug, value } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--checkbox' } );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Checkbox Field Settings" initialOpen={ true }>
                        <TextControl label="Label" value={ label } onChange={ ( nextValue ) => setAttributes( { label: nextValue } ) } />
                        <TextControl label="Checkbox label" value={ checkboxLabel } onChange={ ( nextValue ) => setAttributes( { checkboxLabel: nextValue } ) } />
                        <TextControl label="Slug" value={ slug } onChange={ ( nextValue ) => setAttributes( { slug: nextValue.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        <TextControl label="Checked value" value={ value } onChange={ ( nextValue ) => setAttributes( { value: nextValue } ) } />
                        <TextControl label="Help text" value={ helpText } onChange={ ( nextValue ) => setAttributes( { helpText: nextValue } ) } />
                        <ToggleControl label="Required" checked={ required } onChange={ ( nextValue ) => setAttributes( { required: nextValue } ) } />
                    </PanelBody>
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ ( nextValue ) => setAttributes( { label: nextValue } ) } placeholder="Checkbox label" />
                <label className="swiftforms-field__choice">
                    <input disabled type="checkbox" value={ value || 'yes' } />
                    <span>{ checkboxLabel || 'I agree to the terms.' }</span>
                </label>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
    save( { attributes } ) {
        const { checkboxLabel, helpText, label, required, slug, value } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-field swiftforms-field--checkbox',
            'data-field-slug': slug || 'consent',
            'data-field-type': 'checkbox',
            'data-swiftforms-field': true,
        } );

        return (
            <div { ...blockProps }>
                { label ? <RichText.Content tagName="span" className="swiftforms-field__label" value={ label } /> : null }
                <label className="swiftforms-field__choice">
                    <input name={ slug || 'consent' } type="checkbox" required={ required } value={ value || 'yes' } />
                    <span>{ checkboxLabel || 'I agree to the terms.' }</span>
                </label>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
} );