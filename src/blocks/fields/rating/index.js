import '../editor-shared.css';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { registerFieldBlock } from '../../../field-factory';
import metadata from './block.json';

function RatingControls( { attributes, setAttributes } ) {
	return (
		<TextControl
			label={ __( 'Maximum rating', 'swiftforms' ) }
			type="number"
			min={ 2 }
			max={ 10 }
			value={ attributes.maxRating }
			onChange={ ( maxRating ) =>
				setAttributes( { maxRating: Number( maxRating ) || 5 } )
			}
		/>
	);
}

registerFieldBlock( {
	type: 'rating',
	metadata,
	renderExtraControls: RatingControls,
	renderPreview: ( { maxRating } ) => (
		<div className="swf-field__rating" aria-hidden="true">
			{ Array.from( { length: maxRating || 5 } ).map( ( _, index ) => (
				<span key={ index } className="swf-field__star">
					★
				</span>
			) ) }
		</div>
	),
} );
