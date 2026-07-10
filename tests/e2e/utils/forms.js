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
 * @param {Object}  [field.conditions] Conditional logic attribute (block comment only —
 *                                     the frontend data attribute is injected server-side).
 * @return {string} Serialized block.
 */
function textField( { label, slug, required = false, conditions } ) {
	return `<!-- wp:swiftforms/text-field ${ attrs( conditions ? { label, slug, required, conditions } : { label, slug, required } ) } -->
<div class="wp-block-swiftforms-text-field swiftforms-field swiftforms-field--text" data-field-slug="${ slug }" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">${ label }</span><input name="${ slug }" placeholder="" ${ required ? 'required ' : '' }type="text"/></label></div>
<!-- /wp:swiftforms/text-field -->`;
}

/**
 * Serialized markup for a select field block.
 *
 * Mirrors the select block's save() output byte-for-byte.
 *
 * @param {Object}   field
 * @param {string}   field.label
 * @param {string}   field.slug
 * @param {string[]} field.options
 * @param {boolean}  [field.required]
 * @return {string} Serialized block.
 */
function selectField( { label, slug, options, required = false } ) {
	const optionMarkup = options
		.map( ( line ) => {
			const pipeIndex = line.indexOf( '|' );
			const optionLabel = ( pipeIndex === -1 ? line : line.slice( 0, pipeIndex ) ).trim();
			const optionValue = ( pipeIndex === -1 ? '' : line.slice( pipeIndex + 1 ) ).trim() || optionLabel;

			return `<option value="${ optionValue }">${ optionLabel }</option>`;
		} )
		.join( '' );

	return `<!-- wp:swiftforms/select-field ${ attrs( { label, slug, options: options.join( '\n' ), required } ) } -->
<div class="wp-block-swiftforms-select-field swiftforms-field swiftforms-field--select" data-field-slug="${ slug }" data-field-type="select" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">${ label }</span><select name="${ slug }"${ required ? ' required' : '' }><option value="">Select an option</option>${ optionMarkup }</select></label></div>
<!-- /wp:swiftforms/select-field -->`;
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
 * Serialized markup for a radio field block.
 *
 * @param {Object}   field
 * @param {string}   field.label
 * @param {string}   field.slug
 * @param {string[]} field.options  Option lines (may use `Label|value`).
 * @param {boolean}  [field.required]
 * @return {string} Serialized block.
 */
function radioField( { label, slug, options, required = false } ) {
	const choices = options
		.map( ( line ) => {
			const pipeIndex = line.indexOf( '|' );
			const optionLabel = ( pipeIndex === -1 ? line : line.slice( 0, pipeIndex ) ).trim();
			const optionValue = ( pipeIndex === -1 ? '' : line.slice( pipeIndex + 1 ) ).trim() || optionLabel;

			return `<label class="swiftforms-field__choice"><input name="${ slug }" ${ required ? 'required ' : '' }type="radio" value="${ optionValue }"/><span>${ optionLabel }</span></label>`;
		} )
		.join( '' );

	return `<!-- wp:swiftforms/radio-field ${ attrs( { label, slug, options: options.join( '\n' ), required } ) } -->
<div class="wp-block-swiftforms-radio-field swiftforms-field swiftforms-field--radio" data-field-slug="${ slug }" data-field-type="radio" data-swiftforms-field="true"><fieldset class="swiftforms-field__fieldset"><legend class="swiftforms-field__label">${ label }</legend>${ choices }</fieldset></div>
<!-- /wp:swiftforms/radio-field -->`;
}

/**
 * Serialized markup for a date field block.
 *
 * @param {Object}  field
 * @param {string}  field.label
 * @param {string}  field.slug
 * @param {string}  [field.min]
 * @param {string}  [field.max]
 * @param {boolean} [field.required]
 * @return {string} Serialized block.
 */
function dateField( { label, slug, min = '', max = '', required = false } ) {
	return `<!-- wp:swiftforms/date-field ${ attrs( { label, slug, min, max, required } ) } -->
<div class="wp-block-swiftforms-date-field swiftforms-field swiftforms-field--date" data-field-slug="${ slug }" data-field-type="date" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">${ label }</span><input ${ max ? `max="${ max }" ` : '' }${ min ? `min="${ min }" ` : '' }name="${ slug }" ${ required ? 'required ' : '' }type="date"/></label></div>
<!-- /wp:swiftforms/date-field -->`;
}

/**
 * Serialized markup for a hidden field block.
 *
 * @param {Object} field
 * @param {string} field.slug
 * @param {string} field.value
 * @return {string} Serialized block.
 */
function hiddenField( { slug, value } ) {
	return `<!-- wp:swiftforms/hidden-field ${ attrs( { slug, value } ) } -->
<div class="wp-block-swiftforms-hidden-field swiftforms-field swiftforms-field--hidden" data-field-slug="${ slug }" data-field-type="hidden" data-swiftforms-field="true" hidden><input name="${ slug }" type="hidden" value="${ value }"/></div>
<!-- /wp:swiftforms/hidden-field -->`;
}

/**
 * Wraps serialized field blocks in a step container block.
 *
 * @param {string}   title       Step title.
 * @param {string[]} innerBlocks Serialized inner blocks.
 * @return {string} Serialized block.
 */
function step( title, innerBlocks ) {
	return `<!-- wp:swiftforms/step ${ attrs( { title } ) } -->
<div class="wp-block-swiftforms-step swiftforms-step" data-swiftforms-step="true" data-step-title="${ title }">${ innerBlocks.join( '\n' ) }</div>
<!-- /wp:swiftforms/step -->`;
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

module.exports = { textField, emailField, selectField, radioField, dateField, hiddenField, step, createForm, createFormPage };
