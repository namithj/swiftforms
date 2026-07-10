import { useSelect } from '@wordpress/data';

/**
 * Converts a human label into a field slug.
 *
 * @param {string} value Raw label text (may contain RichText markup).
 * @return {string} Sanitized slug.
 */
export const slugify = ( value ) =>
    ( value || '' )
        .replace( /<[^>]+>/g, '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '_' )
        .replace( /^_+|_+$/g, '' );

/**
 * Derives the next slug when the label changes, unless the author customised it.
 *
 * The slug counts as auto-managed while it's empty, still the block default,
 * or still tracking the current label. Once the author types their own slug it
 * stops following the label.
 *
 * @param {string} label       Current label attribute (before the change).
 * @param {string} newLabel    Incoming label value.
 * @param {string} slug        Current slug attribute.
 * @param {string} defaultSlug The block's default slug.
 * @return {?string} New slug, or null to leave the slug untouched.
 */
export const maybeDeriveSlug = ( label, newLabel, slug, defaultSlug ) => {
    if ( slug && slug !== defaultSlug && slug !== slugify( label ) ) {
        return null;
    }

    return slugify( newLabel ) || defaultSlug;
};

/**
 * Parses a newline-delimited options string into label/value pairs.
 *
 * Each line is `Label|value`, split on the first pipe only. A line without a
 * pipe (or with an empty value half) stores its label as the value, which
 * keeps legacy label-only options byte-identical in the saved markup. Lines
 * with an empty label are skipped. PHP mirrors these exact rules in
 * SwiftForms_Submissions::parse_option_pairs().
 *
 * @param {string} raw Raw options attribute.
 * @return {Array<{label: string, value: string}>} Parsed options.
 */
export const parseOptionPairs = ( raw ) =>
    ( raw || '' )
        .split( /\r?\n/ )
        .map( ( line ) => {
            const pipeIndex = line.indexOf( '|' );
            const label = ( pipeIndex === -1 ? line : line.slice( 0, pipeIndex ) ).trim();
            const value = ( pipeIndex === -1 ? '' : line.slice( pipeIndex + 1 ) ).trim();

            return { label, value: value || label };
        } )
        .filter( ( option ) => option.label !== '' );

/**
 * Reports whether another SwiftForms field block in the editor uses this slug.
 *
 * Duplicate slugs silently overwrite each other's stored values, so field
 * blocks surface a warning when they collide.
 *
 * @param {string} clientId This block's client id.
 * @param {string} slug     Slug to check.
 * @return {boolean} True when another field block uses the same slug.
 */
export const useDuplicateSlug = ( clientId, slug ) =>
    useSelect(
        ( select ) => {
            if ( ! slug ) {
                return false;
            }

            const { getBlocks } = select( 'core/block-editor' );
            const flatten = ( blocks ) =>
                blocks.flatMap( ( block ) => [ block, ...flatten( block.innerBlocks || [] ) ] );

            return flatten( getBlocks() ).some(
                ( block ) =>
                    block.clientId !== clientId &&
                    block.name.startsWith( 'swiftforms/' ) &&
                    block.attributes?.slug === slug
            );
        },
        [ clientId, slug ]
    );
