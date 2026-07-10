import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { maybeDeriveSlug, useDuplicateSlug } from '../field-utils';
import { ConditionsPanel } from '../conditions-panel';
import './editor.css';

const DEFAULT_SLUG = 'phone';

registerBlockType( 'swiftforms/tel-field', {
    edit( { attributes, setAttributes, clientId } ) {
        const { helpText, label, placeholder, required, slug } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--tel' } );
        const isDuplicateSlug = useDuplicateSlug( clientId, slug );

        const onLabelChange = ( value ) => {
            const nextSlug = maybeDeriveSlug( label, value, slug, DEFAULT_SLUG );
            setAttributes( nextSlug === null ? { label: value } : { label: value, slug: nextSlug } );
        };

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title={ __( 'Phone Field Settings', 'swiftforms' ) } initialOpen={ true }>
                        <TextControl label={ __( 'Label', 'swiftforms' ) } value={ label } onChange={ onLabelChange } />
                        <TextControl label={ __( 'Slug', 'swiftforms' ) } value={ slug } onChange={ ( value ) => setAttributes( { slug: value.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        { isDuplicateSlug && (
                            <Notice status="warning" isDismissible={ false }>
                                { __( 'Another field in this form uses this slug; their values will overwrite each other.', 'swiftforms' ) }
                            </Notice>
                        ) }
                        <TextControl label={ __( 'Placeholder', 'swiftforms' ) } value={ placeholder } onChange={ ( value ) => setAttributes( { placeholder: value } ) } />
                        <TextControl label={ __( 'Help text', 'swiftforms' ) } value={ helpText } onChange={ ( value ) => setAttributes( { helpText: value } ) } />
                        <ToggleControl label={ __( 'Required', 'swiftforms' ) } checked={ required } onChange={ ( value ) => setAttributes( { required: value } ) } />
                    </PanelBody>
                    <ConditionsPanel clientId={ clientId } conditions={ attributes.conditions } setAttributes={ setAttributes } />
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ onLabelChange } placeholder={ __( 'Phone label', 'swiftforms' ) } />
                <input disabled type="tel" placeholder={ placeholder || '+1 555 555 5555' } />
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
    save( { attributes } ) {
        const { helpText, label, placeholder, required, slug } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-field swiftforms-field--tel',
            'data-field-slug': slug || 'phone',
            'data-field-type': 'tel',
            'data-swiftforms-field': true,
        } );

        return (
            <div { ...blockProps }>
                <label className="swiftforms-field__control">
                    { label ? <RichText.Content tagName="span" className="swiftforms-field__label" value={ label } /> : null }
                    <input name={ slug || 'phone' } type="tel" placeholder={ placeholder || '' } required={ required } />
                </label>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
} );
