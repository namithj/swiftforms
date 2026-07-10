import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useDuplicateSlug } from '../field-utils';
import './editor.css';


registerBlockType( 'swiftforms/hidden-field', {
    edit( { attributes, setAttributes, clientId } ) {
        const { slug, value } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-field swiftforms-field--hidden' } );
        const isDuplicateSlug = useDuplicateSlug( clientId, slug );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title={ __( 'Hidden Field Settings', 'swiftforms' ) } initialOpen={ true }>
                        <TextControl label={ __( 'Slug', 'swiftforms' ) } value={ slug } onChange={ ( nextValue ) => setAttributes( { slug: nextValue.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) } />
                        { isDuplicateSlug && (
                            <Notice status="warning" isDismissible={ false }>
                                { __( 'Another field in this form uses this slug; their values will overwrite each other.', 'swiftforms' ) }
                            </Notice>
                        ) }
                        <TextControl
                            label={ __( 'Value', 'swiftforms' ) }
                            help={ __( 'Stored with each submission. Visible in the page source and editable by visitors — do not put secrets here.', 'swiftforms' ) }
                            value={ value }
                            onChange={ ( nextValue ) => setAttributes( { value: nextValue } ) }
                        />
                    </PanelBody>
                </InspectorControls>
                <span className="swiftforms-field__hidden-chip">
                    { __( 'Hidden field:', 'swiftforms' ) } { slug || 'hidden_field' } = { value || '""' }
                </span>
            </div>
        );
    },

    save( { attributes } ) {
        const { slug, value } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-field swiftforms-field--hidden',
            'data-field-slug': slug || 'hidden_field',
            'data-field-type': 'hidden',
            'data-swiftforms-field': true,
            hidden: true,
        } );

        return (
            <div { ...blockProps }>
                <input name={ slug || 'hidden_field' } type="hidden" value={ value || '' } />
            </div>
        );
    },
} );
