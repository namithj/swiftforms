import '../editor-shared.css';
import OptionsControl from '../../../field-factory/controls/OptionsControl';
import { parseOptionPairs } from '../../../shared/options';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

function RadioControls( { attributes, setAttributes } ) {
	return (
		<OptionsControl
			value={ attributes.options }
			onChange={ ( options ) => setAttributes( { options } ) }
		/>
	);
}

registerFieldBlock( {
	type: 'radio',
	metadata,
	renderExtraControls: RadioControls,
	renderPreview: ( { options } ) => (
		<div className="swf-field__options">
			{ parseOptionPairs( options ).map( ( option ) => (
				<span key={ option.value } className="swf-field__option">
					<input type="radio" disabled /> { option.label }
				</span>
			) ) }
		</div>
	),
} );
