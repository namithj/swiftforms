/**
 * "Label|value" options textarea, shared by select and radio fields.
 */

import { TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function OptionsControl( { value, onChange } ) {
	return (
		<TextareaControl
			label={ __( 'Options', 'swiftforms' ) }
			help={ __(
				'One per line. Use "Label|value" to store a different value than the label shown.',
				'swiftforms'
			) }
			value={ value }
			onChange={ onChange }
			rows={ 5 }
		/>
	);
}
