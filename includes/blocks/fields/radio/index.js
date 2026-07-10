import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, TextControl, TextareaControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { maybeDeriveSlug, parseOptionPairs, useDuplicateSlug } from '../field-utils';
import { ConditionsPanel } from '../conditions-panel';
import './editor.css';

const DEFAULT_SLUG = 'radio_field';

registerBlockType( 'swiftforms/radio-field', {
    edit( { attributes, setAttributes, clientId } ) {
        const { helpText, label, options, required, slug } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--radio' } );
        const parsedOptions = parseOptionPairs( options );
        const isDuplicateSlug = useDuplicateSlug( clientId, slug );

        const onLabelChange = ( value ) => {
            const nextSlug = maybeDeriveSlug( label, value, slug, DEFAULT_SLUG );
            setAttributes( nextSlug === null ? { label: value } : { label: value, slug: nextSlug } );
        };

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title={ __( 'Radio Field Settings', 'swiftforms' ) } initialOpen={ true }>
                        <TextControl label={ __( 'Label', 'swiftforms' ) } value={ label } onChange={ onLabelChange } />
                        <TextControl label={ __( 'Slug', 'swiftforms' ) } value={ slug } onChange={ ( value ) => setAttributes( { slug: value.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        { isDuplicateSlug && (
                            <Notice status="warning" isDismissible={ false }>
                                { __( 'Another field in this form uses this slug; their values will overwrite each other.', 'swiftforms' ) }
                            </Notice>
                        ) }
                        <TextareaControl label={ __( 'Options', 'swiftforms' ) } help={ __( 'One option per line. Use Label|value to store a different value.', 'swiftforms' ) } value={ options } onChange={ ( value ) => setAttributes( { options: value } ) } />
                        <TextControl label={ __( 'Help text', 'swiftforms' ) } value={ helpText } onChange={ ( value ) => setAttributes( { helpText: value } ) } />
                        <ToggleControl label={ __( 'Required', 'swiftforms' ) } checked={ required } onChange={ ( value ) => setAttributes( { required: value } ) } />
                    </PanelBody>
                    <ConditionsPanel clientId={ clientId } conditions={ attributes.conditions } setAttributes={ setAttributes } />
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ onLabelChange } placeholder={ __( 'Radio label', 'swiftforms' ) } />
                { parsedOptions.map( ( option ) => (
                    <label key={ option.value } className="swiftforms-field__choice">
                        <input disabled type="radio" value={ option.value } />
                        <span>{ option.label }</span>
                    </label>
                ) ) }
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
    save( { attributes } ) {
        const { helpText, label, options, required, slug } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-field swiftforms-field--radio',
            'data-field-slug': slug || 'radio_field',
            'data-field-type': 'radio',
            'data-swiftforms-field': true,
        } );
        const parsedOptions = parseOptionPairs( options );

        return (
            <div { ...blockProps }>
                <fieldset className="swiftforms-field__fieldset">
                    { label ? <RichText.Content tagName="legend" className="swiftforms-field__label" value={ label } /> : null }
                    { parsedOptions.map( ( option ) => (
                        <label key={ option.value } className="swiftforms-field__choice">
                            <input name={ slug || 'radio_field' } required={ required } type="radio" value={ option.value } />
                            <span>{ option.label }</span>
                        </label>
                    ) ) }
                </fieldset>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
} );
