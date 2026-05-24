import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import './editor.css';

registerBlockType( 'swiftforms/file-field', {
    edit( { attributes, setAttributes } ) {
        const { helpText, label, required, slug } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--file' } );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="File Field Settings" initialOpen={ true }>
                        <TextControl label="Label" value={ label } onChange={ ( value ) => setAttributes( { label: value } ) } />
                        <TextControl label="Slug" value={ slug } onChange={ ( value ) => setAttributes( { slug: value.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        <TextControl label="Help text" value={ helpText } onChange={ ( value ) => setAttributes( { helpText: value } ) } />
                        <ToggleControl label="Required" checked={ required } onChange={ ( value ) => setAttributes( { required: value } ) } />
                    </PanelBody>
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ ( value ) => setAttributes( { label: value } ) } placeholder="File label" />
                <input type="file" disabled />
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
    save( { attributes } ) {
        const { helpText, label, required, slug } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-field swiftforms-field--file',
            'data-field-slug': slug || 'attachment',
            'data-field-type': 'file',
            'data-swiftforms-field': true,
        } );
        return (
            <div { ...blockProps }>
                <label className="swiftforms-field__control">
                    { label ? <RichText.Content tagName="span" className="swiftforms-field__label" value={ label } /> : null }
                    <input name={ slug || 'attachment' } required={ required } type="file" />
                </label>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
} );