import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import './editor.css';

registerBlockType( 'swiftforms/step', {
    edit( { attributes, setAttributes } ) {
        const { title } = attributes;
        const blockProps = useBlockProps( { className: 'swiftforms-step' } );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title={ __( 'Step Settings', 'swiftforms' ) } initialOpen={ true }>
                        <TextControl
                            label={ __( 'Step title', 'swiftforms' ) }
                            help={ __( 'Announced in the progress indicator on the frontend.', 'swiftforms' ) }
                            value={ title }
                            onChange={ ( value ) => setAttributes( { title: value } ) }
                        />
                    </PanelBody>
                </InspectorControls>
                <div className="swiftforms-step__editor-header">{ title || __( 'Step', 'swiftforms' ) }</div>
                <InnerBlocks />
            </div>
        );
    },

    save( { attributes } ) {
        const { title } = attributes;
        const blockProps = useBlockProps.save( {
            className: 'swiftforms-step',
            'data-swiftforms-step': true,
            'data-step-title': title || '',
        } );

        return (
            <div { ...blockProps }>
                <InnerBlocks.Content />
            </div>
        );
    },
} );
