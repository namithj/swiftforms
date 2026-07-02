/**
 * Shared helpers for SwiftForms E2E specs.
 *
 * Field blocks render on the frontend from their saved markup (the PHP
 * render_callback only passes that markup through a filter), so we can build a
 * form deterministically over the REST API instead of driving the block editor
 * for every scenario. Only the `data-swiftforms-field` wrapper, its data
 * attributes, and the inner control matter for submission behaviour.
 */

const attrs = ( value ) => JSON.stringify( value );

/**
 * Serialized markup for a text field block.
 *
 * @param {Object}  field
 * @param {string}  field.label
 * @param {string}  field.slug
 * @param {boolean} [field.required]
 * @return {string} Serialized block.
 */
function textField( { label, slug, required = false } ) {
	return `<!-- wp:swiftforms/text-field ${ attrs( { label, slug, required } ) } -->
<div class="wp-block-swiftforms-text-field swiftforms-field swiftforms-field--text" data-field-slug="${ slug }" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">${ label }</span><input name="${ slug }" placeholder="" ${ required ? 'required ' : '' }type="text"/></label></div>
<!-- /wp:swiftforms/text-field -->`;
}

/**
 * Serialized markup for an email field block.
 *
 * @param {Object}  field
 * @param {string}  field.label
 * @param {string}  field.slug
 * @param {boolean} [field.required]
 * @return {string} Serialized block.
 */
function emailField( { label, slug, required = false } ) {
	return `<!-- wp:swiftforms/email-field ${ attrs( { label, slug, required } ) } -->
<div class="wp-block-swiftforms-email-field swiftforms-field swiftforms-field--email" data-field-slug="${ slug }" data-field-type="email" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">${ label }</span><input name="${ slug }" placeholder="" ${ required ? 'required ' : '' }type="email"/></label></div>
<!-- /wp:swiftforms/email-field -->`;
}

/**
 * Creates a published SwiftForms form and returns its post ID.
 *
 * @param {Object}   requestUtils     e2e requestUtils fixture.
 * @param {Object}   options
 * @param {string}   [options.title]
 * @param {string[]} [options.fields]   Serialized field blocks.
 * @param {Object}   [options.settings] `_sf_settings` overrides.
 * @return {Promise<number>} Created form ID.
 */
async function createForm( requestUtils, { title = 'E2E Form', fields = [], settings = {} } = {} ) {
	const form = await requestUtils.rest( {
		path: '/wp/v2/swiftforms_form',
		method: 'POST',
		data: {
			title,
			status: 'publish',
			content: fields.join( '\n\n' ),
		},
	} );

	if ( Object.keys( settings ).length > 0 ) {
		// SwiftForms_CPTs::sanitize_form_settings() fills any key missing from
		// the payload with its hardcoded default, not the currently saved
		// value — a partial PATCH would silently reset the rest of the
		// settings. The real editor sidebar avoids this by always spreading
		// the full current settings before saving (settings-panel.js), so we
		// mirror that here rather than send a partial object.
		await requestUtils.rest( {
			path: `/wp/v2/swiftforms_form/${ form.id }`,
			method: 'POST',
			data: {
				meta: {
					_sf_settings: {
						...( form.meta?._sf_settings || {} ),
						...settings,
					},
				},
			},
		} );
	}

	return form.id;
}

/**
 * Creates a published page that embeds a form and returns the page object.
 *
 * @param {Object} requestUtils e2e requestUtils fixture.
 * @param {number} formId       Form post ID to embed.
 * @param {string} [title]
 * @return {Promise<Object>} Created page (includes `link`).
 */
async function createFormPage( requestUtils, formId, title = 'E2E Form Page' ) {
	return requestUtils.rest( {
		path: '/wp/v2/pages',
		method: 'POST',
		data: {
			title,
			status: 'publish',
			content: `<!-- wp:swiftforms/form {"formId":${ formId }} /-->`,
		},
	} );
}

module.exports = { textField, emailField, createForm, createFormPage };
