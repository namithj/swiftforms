import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { maybeDeriveSlug, useDuplicateSlug } from '../field-utils';
import { ConditionsPanel } from '../conditions-panel';
import './editor.css';

const DEFAULT_SLUG = 'consent';

registerBlockType( 'swiftforms/checkbox-field', {
    edit( { attributes, setAttributes, clientId } ) {
        const { checkboxLabel, helpText, label, required, slug, value } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--checkbox' } );
        const isDuplicateSlug = useDuplicateSlug( clientId, slug );

        const onLabelChange = ( nextValue ) => {
            const nextSlug = maybeDeriveSlug( label, nextValue, slug, DEFAULT_SLUG );
            setAttributes( nextSlug === null ? { label: nextValue } : { label: nextValue, slug: nextSlug } );
        };

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title={ __( 'Checkbox Field Settings', 'swiftforms' ) } initialOpen={ true }>
                        <TextControl label={ __( 'Label', 'swiftforms' ) } value={ label } onChange={ onLabelChange } />
                        <TextControl label={ __( 'Checkbox label', 'swiftforms' ) } value={ checkboxLabel } onChange={ ( nextValue ) => setAttributes( { checkboxLabel: nextValue } ) } />
                        <TextControl label={ __( 'Slug', 'swiftforms' ) } value={ slug } onChange={ ( nextValue ) => setAttributes( { slug: nextValue.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        { isDuplicateSlug && (
                            <Notice status="warning" isDismissible={ false }>
                                { __( 'Another field in this form uses this slug; their values will overwrite each other.', 'swiftforms' ) }
                            </Notice>
                        ) }
                        <TextControl label={ __( 'Checked value', 'swiftforms' ) } value={ value } onChange={ ( nextValue ) => setAttributes( { value: nextValue } ) } />
                        <TextControl label={ __( 'Help text', 'swiftforms' ) } value={ helpText } onChange={ ( nextValue ) => setAttributes( { helpText: nextValue } ) } />
                        <ToggleControl label={ __( 'Required', 'swiftforms' ) } checked={ required } onChange={ ( nextValue ) => setAttributes( { required: nextValue } ) } />
                    </PanelBody>
                    <ConditionsPanel clientId={ clientId } conditions={ attributes.conditions } setAttributes={ setAttributes } />
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ onLabelChange } placeholder={ __( 'Checkbox label', 'swiftforms' ) } />
                <label className="swiftforms-field__choice">
                    <input disabled type="checkbox" value={ value || 'yes' } />
                    <span>{ checkboxLabel || __( 'I agree to the terms.', 'swiftforms' ) }</span>
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
