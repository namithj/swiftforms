import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, TextareaControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { maybeDeriveSlug, parseOptionPairs, useDuplicateSlug } from '../field-utils';
import { ConditionsPanel } from '../conditions-panel';
import './editor.css';

const DEFAULT_SLUG = 'select_field';

registerBlockType( 'swiftforms/select-field', {
    edit( { attributes, setAttributes, clientId } ) {
        const { helpText, label, options, required, slug } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--select' } );
        const parsedOptions = parseOptionPairs( options );
        const isDuplicateSlug = useDuplicateSlug( clientId, slug );

        const onLabelChange = ( value ) => {
            const nextSlug = maybeDeriveSlug( label, value, slug, DEFAULT_SLUG );
            setAttributes( nextSlug === null ? { label: value } : { label: value, slug: nextSlug } );
        };

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title={ __( 'Select Field Settings', 'swiftforms' ) } initialOpen={ true }>
                        <TextareaControl label={ __( 'Options', 'swiftforms' ) } help={ __( 'One option per line. Use Label|value to store a different value.', 'swiftforms' ) } value={ options } onChange={ ( value ) => setAttributes( { options: value } ) } />
                        <TextareaControl label={ __( 'Help text', 'swiftforms' ) } value={ helpText } onChange={ ( value ) => setAttributes( { helpText: value } ) } />
                        <TextareaControl label={ __( 'Label', 'swiftforms' ) } value={ label } onChange={ onLabelChange } />
                        <TextareaControl label={ __( 'Slug', 'swiftforms' ) } value={ slug } onChange={ ( value ) => setAttributes( { slug: value.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        { isDuplicateSlug && (
                            <Notice status="warning" isDismissible={ false }>
                                { __( 'Another field in this form uses this slug; their values will overwrite each other.', 'swiftforms' ) }
                            </Notice>
                        ) }
                        <ToggleControl label={ __( 'Required', 'swiftforms' ) } checked={ required } onChange={ ( value ) => setAttributes( { required: value } ) } />
                    </PanelBody>
                    <ConditionsPanel clientId={ clientId } conditions={ attributes.conditions } setAttributes={ setAttributes } />
                </InspectorControls>
                <RichText tagName="span" className="swiftforms-field__label" value={ label } onChange={ onLabelChange } placeholder={ __( 'Select label', 'swiftforms' ) } />
                <select disabled>
                    { parsedOptions.map( ( option ) => (
                        <option key={ option.value } value={ option.value }>{ option.label }</option>
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
        const parsedOptions = parseOptionPairs( options );

        return (
            <div { ...blockProps }>
                <label className="swiftforms-field__control">
                    { label ? <RichText.Content tagName="span" className="swiftforms-field__label" value={ label } /> : null }
                    <select name={ slug || 'select_field' } required={ required }>
                        <option value="">Select an option</option>
                        { parsedOptions.map( ( option ) => (
                            <option key={ option.value } value={ option.value }>{ option.label }</option>
                        ) ) }
                    </select>
                </label>
                { helpText ? <p className="swiftforms-field__help">{ helpText }</p> : null }
            </div>
        );
    },
} );
