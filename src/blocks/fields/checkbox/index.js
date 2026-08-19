import '../editor-shared.css';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

function CheckboxControls( { attributes, setAttributes } ) {
	return (
		<>
			<TextControl
				label={ __( 'Checkbox label', 'swiftforms' ) }
				value={ attributes.checkboxLabel }
				onChange={ ( checkboxLabel ) =>
					setAttributes( { checkboxLabel } )
				}
			/>
			<TextControl
				label={ __( 'Checked value', 'swiftforms' ) }
				value={ attributes.value }
				onChange={ ( value ) => setAttributes( { value } ) }
			/>
		</>
	);
}

registerFieldBlock( {
	type: 'checkbox',
	metadata,
	renderExtraControls: CheckboxControls,
	renderPreview: ( { checkboxLabel } ) => (
		<span className="swf-field__checkbox-label">
			<input type="checkbox" disabled /> { checkboxLabel }
		</span>
	),
} );
