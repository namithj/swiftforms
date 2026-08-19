import '../editor-shared.css';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

registerFieldBlock( {
	type: 'file',
	metadata,
	renderPreview: () => (
		<input type="file" disabled className="swf-field__control" />
	),
} );
