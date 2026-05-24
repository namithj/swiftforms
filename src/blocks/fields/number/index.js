import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import './editor.css';

registerBlockType( 'swiftforms/number-field', {
    edit( { attributes, setAttributes } ) {
        const { helpText, label, max, min, placeholder, required, slug, step } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--number' } );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Number Field Settings" initialOpen={ true }>
                        <TextControl label="Label" value={ label } onChange={ ( value ) => setAttributes( { label: value } ) } />
                        <TextControl label="Slug" value={ slug } onChange={ ( value ) => setAttributes( { slug: value.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        <TextControl label="Placeholder" value={ placeholder } onChange={ ( value ) => setAttributes( { placeholder: value } ) } />
                        <TextControl label="Minimum" type="number" value={ min } onChange={ ( value ) => setAttributes( { min: value } ) } />
                        <TextControl label="Maximum" type="number" value={ max } onChange={ ( value ) => setAttributes( { max: value } ) } />
                        <TextControl label="Step" type="number" value={ step } onChange={ ( value ) => setAttributes( { step: value || '1' } ) } />
                        <TextControl label="Help text" value={ helpText } onChange={ ( value ) => setAttributes( { helpText: value } ) } />
                        <ToggleControl label="Required" checked={ required } onChange={ ( value ) => setAttributes( { required: value } ) } />
                    </PanelBody>
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ ( value ) => setAttributes( { label: value } ) } placeholder="Number label" />
                <input disabled type="number" placeholder={ placeholder || '0' } min={ min || undefined } max={ max || undefined } step={ step || undefined } />
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
    save( { attributes } ) {
        const { helpText, label, max, min, placeholder, required, slug, step } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-field swiftforms-field--number',
            'data-field-slug': slug || 'number_field',
            'data-field-type': 'number',
            'data-swiftforms-field': true,
        } );

        return (
            <div { ...blockProps }>
                <label className="swiftforms-field__control">
                    { label ? <RichText.Content tagName="span" className="swiftforms-field__label" value={ label } /> : null }
                    <input name={ slug || 'number_field' } type="number" placeholder={ placeholder || '' } required={ required } min={ min || undefined } max={ max || undefined } step={ step || undefined } />
                </label>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
} );