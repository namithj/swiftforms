import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextareaControl, ToggleControl } from '@wordpress/components';
import './editor.css';

const parseOptions = ( rawOptions ) => rawOptions
    .split( /\r?\n/ )
    .map( ( option ) => option.trim() )
    .filter( Boolean );

registerBlockType( 'swiftforms/select-field', {
    edit( { attributes, setAttributes } ) {
        const { helpText, label, options, required, slug } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--select' } );
        const parsedOptions = parseOptions( options || '' );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Select Field Settings" initialOpen={ true }>
                        <TextareaControl label="Options" help="One option per line." value={ options } onChange={ ( value ) => setAttributes( { options: value } ) } />
                        <TextareaControl label="Help text" value={ helpText } onChange={ ( value ) => setAttributes( { helpText: value } ) } />
                        <TextareaControl label="Label" value={ label } onChange={ ( value ) => setAttributes( { label: value } ) } />
                        <TextareaControl label="Slug" value={ slug } onChange={ ( value ) => setAttributes( { slug: value.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        <ToggleControl label="Required" checked={ required } onChange={ ( value ) => setAttributes( { required: value } ) } />
                    </PanelBody>
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ ( value ) => setAttributes( { label: value } ) } placeholder="Select label" />
                <select disabled>
                    { parsedOptions.map( ( option ) => (
                        <option key={ option } value={ option }>{ option }</option>
                    ) ) }
                </select>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
    save( { attributes } ) {
        const { helpText, label, options, required, slug } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-field swiftforms-field--select',
            'data-field-slug': slug || 'select_field',
            'data-field-type': 'select',
            'data-swiftforms-field': true,
        } );
        const parsedOptions = parseOptions( options || '' );

        return (
            <div { ...blockProps }>
                <label className="swiftforms-field__control">
                    { label ? <RichText.Content tagName="span" className="swiftforms-field__label" value={ label } /> : null }
                    <select name={ slug || 'select_field' } required={ required }>
                        <option value="">Select an option</option>
                        { parsedOptions.map( ( option ) => (
                            <option key={ option } value={ option }>{ option }</option>
                        ) ) }
                    </select>
                </label>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
} );