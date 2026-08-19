import { registerBlockType } from '@wordpress/blocks';
import {
	InspectorControls,
	InnerBlocks,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './editor.scss';

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( { className: 'swf-editor-step' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Step', 'swiftforms' ) }>
					<TextControl
						label={ __( 'Step title', 'swiftforms' ) }
						value={ attributes.title }
						onChange={ ( title ) => setAttributes( { title } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="swf-editor-step__header">
					{ attributes.title }
				</div>
				<InnerBlocks templateLock={ false } />
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
