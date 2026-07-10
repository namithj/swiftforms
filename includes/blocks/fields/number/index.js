import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { maybeDeriveSlug, useDuplicateSlug } from '../field-utils';
import { ConditionsPanel } from '../conditions-panel';
import './editor.css';

const DEFAULT_SLUG = 'number_field';

registerBlockType( 'swiftforms/number-field', {
    edit( { attributes, setAttributes, clientId } ) {
        const { helpText, label, max, min, placeholder, required, slug, step } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--number' } );
        const isDuplicateSlug = useDuplicateSlug( clientId, slug );

        const onLabelChange = ( value ) => {
            const nextSlug = maybeDeriveSlug( label, value, slug, DEFAULT_SLUG );
            setAttributes( nextSlug === null ? { label: value } : { label: value, slug: nextSlug } );
        };

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title={ __( 'Number Field Settings', 'swiftforms' ) } initialOpen={ true }>
                        <TextControl label={ __( 'Label', 'swiftforms' ) } value={ label } onChange={ onLabelChange } />
                        <TextControl label={ __( 'Slug', 'swiftforms' ) } value={ slug } onChange={ ( value ) => setAttributes( { slug: value.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        { isDuplicateSlug && (
                            <Notice status="warning" isDismissible={ false }>
                                { __( 'Another field in this form uses this slug; their values will overwrite each other.', 'swiftforms' ) }
                            </Notice>
                        ) }
                        <TextControl label={ __( 'Placeholder', 'swiftforms' ) } value={ placeholder } onChange={ ( value ) => setAttributes( { placeholder: value } ) } />
                        <TextControl label={ __( 'Minimum', 'swiftforms' ) } type="number" value={ min } onChange={ ( value ) => setAttributes( { min: value } ) } />
                        <TextControl label={ __( 'Maximum', 'swiftforms' ) } type="number" value={ max } onChange={ ( value ) => setAttributes( { max: value } ) } />
                        <TextControl label={ __( 'Step', 'swiftforms' ) } type="number" value={ step } onChange={ ( value ) => setAttributes( { step: value || '1' } ) } />
                        <TextControl label={ __( 'Help text', 'swiftforms' ) } value={ helpText } onChange={ ( value ) => setAttributes( { helpText: value } ) } />
                        <ToggleControl label={ __( 'Required', 'swiftforms' ) } checked={ required } onChange={ ( value ) => setAttributes( { required: value } ) } />
                    </PanelBody>
                    <ConditionsPanel clientId={ clientId } conditions={ attributes.conditions } setAttributes={ setAttributes } />
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ onLabelChange } placeholder={ __( 'Number label', 'swiftforms' ) } />
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
