/**
 * Editor-only state of the SwiftForms blocks: which form blocks currently
 * preview their confirmation. Nothing here is saved with the post.
 */
import { createReduxStore, register } from '@wordpress/data';

export const STORE_NAME = 'swiftforms/editor';

export const DEFAULT_STATE = { previewing: {} };

/**
 * Reducer.
 *
 * @param {Object} state  Current state.
 * @param {Object} action Action.
 * @return {Object} Next state.
 */
export function reducer( state = DEFAULT_STATE, action ) {
	if ( 'TOGGLE_CONFIRMATION_PREVIEW' === action.type ) {
		return {
			...state,
			previewing: {
				...state.previewing,
				[ action.clientId ]: ! state.previewing[ action.clientId ],
			},
		};
	}
	return state;
}

export const actions = {
	toggleConfirmationPreview( clientId ) {
		return { type: 'TOGGLE_CONFIRMATION_PREVIEW', clientId };
	},
};

export const selectors = {
	isPreviewingConfirmation( state, clientId ) {
		return true === state.previewing[ clientId ];
	},
};

/**
 * Register the store once.
 */
export function registerEditorStore() {
	register( createReduxStore( STORE_NAME, { reducer, actions, selectors } ) );
}
