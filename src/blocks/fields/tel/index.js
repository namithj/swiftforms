import '../editor-shared.css';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

registerFieldBlock( {
	type: 'tel',
	metadata,
	renderPreview: ( { placeholder } ) => (
		<input
			type="tel"
			disabled
			placeholder={ placeholder }
			className="swf-field__control"
		/>
	),
} );
