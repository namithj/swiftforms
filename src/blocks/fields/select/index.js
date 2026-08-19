import '../editor-shared.css';
import OptionsControl from '../../../field-factory/controls/OptionsControl';
import { parseOptionPairs } from '../../../shared/options';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

function SelectControls( { attributes, setAttributes } ) {
	return (
		<OptionsControl
			value={ attributes.options }
			onChange={ ( options ) => setAttributes( { options } ) }
		/>
	);
}

registerFieldBlock( {
	type: 'select',
	metadata,
	renderExtraControls: SelectControls,
	renderPreview: ( { options } ) => (
		<select disabled className="swf-field__control">
			{ parseOptionPairs( options ).map( ( option ) => (
				<option key={ option.value }>{ option.label }</option>
			) ) }
		</select>
	),
} );
