import '../editor-shared.css';
import { TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

function ConsentControls( { attributes, setAttributes } ) {
	return (
		<TextareaControl
			label={ __( 'Consent statement', 'swiftforms' ) }
			value={ attributes.statementText }
			onChange={ ( statementText ) => setAttributes( { statementText } ) }
		/>
	);
}

registerFieldBlock( {
	type: 'consent',
	metadata,
	hasRequired: false,
	renderExtraControls: ConsentControls,
	renderPreview: ( { statementText } ) => (
		<span className="swf-field__checkbox-label">
			<input type="checkbox" disabled /> { statementText }
		</span>
	),
} );
