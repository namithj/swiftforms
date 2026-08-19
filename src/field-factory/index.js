/**
 * Field-block factory: registers one `smartlogix-swiftforms/field-*` block from a small
 * per-type config module. See edit-factory.js for the shared editor UI.
 */

import { registerBlockType } from '@wordpress/blocks';
import { createFieldEdit } from './edit-factory';

const fieldConfig = window.smartlogixSwiftFormsFieldConfig || {};

/**
 * @param {Object}   options
 * @param {string}   options.type                  Field type key (matches the PHP FieldRegistry key).
 * @param {Object}   options.metadata              Imported block.json.
 * @param {Function} options.renderPreview         ( attributes ) => JSX canvas preview.
 * @param {Function} [options.renderExtraControls] ( { attributes, setAttributes } ) => JSX.
 * @param {boolean}  [options.hasLabel]            Whether this type has a label/slug pair.
 * @param {boolean}  [options.hasRequired]         Whether this type has a Required toggle.
 * @param {boolean}  [options.hasHelp]             Whether this type has a help-text control.
 * @param {boolean}  [options.hasConditions]       Whether this type supports conditional visibility.
 */
export function registerFieldBlock( {
	type,
	metadata,
	renderPreview,
	renderExtraControls,
	hasLabel,
	hasRequired,
	hasHelp,
	hasConditions,
} ) {
	const typeConfig = fieldConfig[ type ] || {};
	const attributes = typeConfig.attributes || {};
	const typeLabel = typeConfig.label || metadata.title;
	const defaultSlug = attributes.slug ? attributes.slug.default : '';

	registerBlockType( metadata.name, {
		...metadata,
		attributes,
		edit: createFieldEdit( {
			typeLabel,
			defaultSlug,
			renderPreview,
			renderExtraControls,
			hasLabel,
			hasRequired,
			hasHelp,
			hasConditions,
		} ),
		save: () => null,
	} );
}
