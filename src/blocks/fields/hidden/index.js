import '../editor-shared.css';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

function HiddenControls( { attributes, setAttributes } ) {
	return (
		<TextControl
			label={ __( 'Value', 'swiftforms' ) }
			help={ __(
				'Do not put secrets here — this value is visible in the page source.',
				'swiftforms'
			) }
			value={ attributes.value }
			onChange={ ( value ) => setAttributes( { value } ) }
		/>
	);
}

registerFieldBlock( {
	type: 'hidden',
	metadata,
	hasLabel: false,
	hasRequired: false,
	hasHelp: false,
	hasConditions: false,
	renderExtraControls: HiddenControls,
	renderPreview: ( { slug, value } ) => (
		<em>
			{ __( 'Hidden field:', 'swiftforms' ) } { slug } = &quot;
			{ value }&quot;
		</em>
	),
} );
