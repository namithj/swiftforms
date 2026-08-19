import '../editor-shared.css';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

registerFieldBlock( {
	type: 'email',
	metadata,
	renderPreview: ( { placeholder } ) => (
		<input
			type="email"
			disabled
			placeholder={ placeholder }
			className="swf-field__control"
		/>
	),
} );
