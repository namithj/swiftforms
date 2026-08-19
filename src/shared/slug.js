/**
 * Slug helpers shared by every field block's editor controls.
 */

/**
 * Turns a label into a safe field slug: lowercase, ASCII word characters
 * and underscores only.
 *
 * @param {string} value Raw label text.
 */
export function slugify( value ) {
	return String( value || '' )
		.trim()
		.toLowerCase()
		.replace( /[^a-z0-9_]+/g, '_' )
		.replace( /^_+|_+$/g, '' );
}

/**
 * Auto-derives a slug from a label, but only while the author hasn't
 * customized the slug themselves (i.e. the current slug still matches what
 * the previous label would have produced, or is still the block default).
 *
 * @param {string} nextLabel     The new label value.
 * @param {string} previousLabel The label value before this change.
 * @param {string} currentSlug   The slug currently stored on the block.
 * @param {string} defaultSlug   The block type's default slug attribute.
 */
export function maybeDeriveSlug(
	nextLabel,
	previousLabel,
	currentSlug,
	defaultSlug
) {
	const wasAutoDerived =
		currentSlug === slugify( previousLabel ) ||
		currentSlug === defaultSlug ||
		! currentSlug;

	return wasAutoDerived ? slugify( nextLabel ) : currentSlug;
}
