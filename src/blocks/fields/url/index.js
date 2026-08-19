import '../editor-shared.css';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

registerFieldBlock( {
	type: 'url',
	metadata,
	renderPreview: ( { placeholder } ) => (
		<input
			type="url"
			disabled
			placeholder={ placeholder }
			className="swf-field__control"
		/>
	),
} );
