import '../editor-shared.css';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

registerFieldBlock( {
	type: 'text',
	metadata,
	renderPreview: ( { placeholder } ) => (
		<input
			type="text"
			disabled
			placeholder={ placeholder }
			className="swf-field__control"
		/>
	),
} );
