import { dispatch, select, subscribe } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { validateFormBlocks } from './validate-form';

const LOCK = 'smartlogix-swiftforms-invalid-form';
const AUTORESPONDER_META = '_smartlogix_swiftforms_setting_autoresponderField';

let lastErrors = '';
subscribe( () => {
	const blocks = select( blockEditorStore ).getBlocks();
	const meta = select( editorStore ).getEditedPostAttribute( 'meta' ) || {};
	const errors = validateFormBlocks(
		blocks,
		meta[ AUTORESPONDER_META ] || ''
	);
	const signature = errors.join( ',' );
	if ( signature === lastErrors ) {
		return;
	}
	lastErrors = signature;

	if ( errors.length ) {
		dispatch( editorStore ).lockPostSaving( LOCK );
		dispatch( 'core/notices' ).createNotice(
			'error',
			__(
				'Fix field names, options, conditional rules, and autoresponder selections before publishing this form.',
				'swiftforms'
			),
			{ id: LOCK, isDismissible: false }
		);
		return;
	}

	dispatch( editorStore ).unlockPostSaving( LOCK );
	dispatch( 'core/notices' ).removeNotice( LOCK );
} );
