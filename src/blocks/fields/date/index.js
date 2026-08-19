import '../editor-shared.css';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

function DateControls( { attributes, setAttributes } ) {
	return (
		<>
			<TextControl
				label={ __( 'Earliest date (YYYY-MM-DD)', 'swiftforms' ) }
				value={ attributes.min }
				onChange={ ( min ) => setAttributes( { min } ) }
			/>
			<TextControl
				label={ __( 'Latest date (YYYY-MM-DD)', 'swiftforms' ) }
				value={ attributes.max }
				onChange={ ( max ) => setAttributes( { max } ) }
			/>
		</>
	);
}

registerFieldBlock( {
	type: 'date',
	metadata,
	renderExtraControls: DateControls,
	renderPreview: () => (
		<input type="date" disabled className="swf-field__control" />
	),
} );
