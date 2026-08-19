import '../editor-shared.css';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

registerFieldBlock( {
	type: 'textarea',
	metadata,
	renderPreview: ( { placeholder } ) => (
		<textarea
			disabled
			placeholder={ placeholder }
			className="swf-field__control"
			rows={ 3 }
		/>
	),
} );
