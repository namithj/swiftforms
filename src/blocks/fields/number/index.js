import '../editor-shared.css';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

function NumberControls( { attributes, setAttributes } ) {
	return (
		<>
			<TextControl
				label={ __( 'Minimum', 'swiftforms' ) }
				value={ attributes.min }
				onChange={ ( min ) => setAttributes( { min } ) }
			/>
			<TextControl
				label={ __( 'Maximum', 'swiftforms' ) }
				value={ attributes.max }
				onChange={ ( max ) => setAttributes( { max } ) }
			/>
			<TextControl
				label={ __( 'Step', 'swiftforms' ) }
				value={ attributes.step }
				onChange={ ( step ) => setAttributes( { step } ) }
			/>
		</>
	);
}

registerFieldBlock( {
	type: 'number',
	metadata,
	renderExtraControls: NumberControls,
	renderPreview: ( { placeholder } ) => (
		<input
			type="number"
			disabled
			placeholder={ placeholder }
			className="swf-field__control"
		/>
	),
} );
