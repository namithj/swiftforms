<?php
/**
 * Source references.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Source_Ref
 *
 * The ADR-001 references that say where a form lives:
 *
 *   post:123                      any post type, including wp_block
 *   template:{theme}//{slug}      block template (database or theme file)
 *   template_part:{theme}//{slug} block template part
 *   pattern:{name}                registered (file) pattern
 *   widget:block-{n}              block widget
 *
 * Parsing is strict and canonical: format( parse( $ref ) ) gives the same
 * string back, and anything else (leading zeros, spaces, extra parts, longer
 * than the 191 characters of the source column) is invalid and ends up as
 * "closed" at the public routes.
 */
class Source_Ref {

	/**
	 * Longest valid reference (the width of the source columns).
	 */
	const MAX_LENGTH = 191;

	/**
	 * Parse a reference.
	 *
	 * @param mixed $ref Candidate reference.
	 * @return array{type:string, id:string, theme?:string, slug?:string, post_id?:int, number?:int}|null
	 *         "type" is post, template, template_part, pattern or widget. "id" is the part after the colon.
	 *         Posts add "post_id", templates and parts add "theme" and "slug", widgets add "number".
	 *         Null when the reference is not valid.
	 */
	public static function parse( $ref ) {
		if ( ! is_string( $ref ) || '' === $ref || strlen( $ref ) > self::MAX_LENGTH ) {
			return null;
		}

		if ( 1 === preg_match( '/^post:([1-9][0-9]{0,17})$/D', $ref, $m ) ) {
			return [
				'type'    => 'post',
				'id'      => $m[1],
				'post_id' => (int) $m[1],
			];
		}

		if ( 1 === preg_match( '/^(template|template_part):([A-Za-z0-9._-]+)\/\/([A-Za-z0-9._-]+)$/D', $ref, $m ) ) {
			return [
				'type'  => $m[1],
				'id'    => $m[2] . '//' . $m[3],
				'theme' => $m[2],
				'slug'  => $m[3],
			];
		}

		if ( 1 === preg_match( '/^pattern:([A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*)$/D', $ref, $m ) ) {
			return [
				'type' => 'pattern',
				'id'   => $m[1],
			];
		}

		if ( 1 === preg_match( '/^widget:block-([1-9][0-9]{0,9})$/D', $ref, $m ) ) {
			return [
				'type'   => 'widget',
				'id'     => 'block-' . $m[1],
				'number' => (int) $m[1],
			];
		}

		return null;
	}

	/**
	 * Whether a value is a valid reference.
	 *
	 * @param mixed $ref Candidate reference.
	 * @return bool
	 */
	public static function is_valid( $ref ) {
		return null !== self::parse( $ref );
	}

	/**
	 * Reference of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Null for an ID below 1.
	 */
	public static function post( $post_id ) {
		$post_id = (int) $post_id;
		return $post_id > 0 ? 'post:' . $post_id : null;
	}

	/**
	 * Reference of a block template.
	 *
	 * @param string $theme Theme stylesheet.
	 * @param string $slug  Template slug.
	 * @return string|null Null when the parts are not valid.
	 */
	public static function template( $theme, $slug ) {
		return self::checked( 'template:' . $theme . '//' . $slug );
	}

	/**
	 * Reference of a block template part.
	 *
	 * @param string $theme Theme stylesheet.
	 * @param string $slug  Template part slug.
	 * @return string|null Null when the parts are not valid.
	 */
	public static function template_part( $theme, $slug ) {
		return self::checked( 'template_part:' . $theme . '//' . $slug );
	}

	/**
	 * Reference of a registered pattern.
	 *
	 * @param string $name Pattern name (slug).
	 * @return string|null Null when the name is not valid.
	 */
	public static function pattern( $name ) {
		return self::checked( 'pattern:' . $name );
	}

	/**
	 * Reference of a block widget.
	 *
	 * @param int $number Widget number (the n of "block-n").
	 * @return string|null Null for a number below 1.
	 */
	public static function widget( $number ) {
		$number = (int) $number;
		return self::checked( 'widget:block-' . $number );
	}

	/**
	 * Reference of a template or part from the "theme//slug" id WordPress uses.
	 *
	 * @param string $type Either "template" or "template_part".
	 * @param string $id   Template id, for example "twentytwentyfive//single".
	 * @return string|null
	 */
	public static function from_template_id( $type, $id ) {
		if ( ! in_array( $type, [ 'template', 'template_part' ], true ) ) {
			return null;
		}
		return self::checked( $type . ':' . $id );
	}

	/**
	 * Return the reference when it is valid, otherwise null.
	 *
	 * @param string $ref Candidate.
	 * @return string|null
	 */
	private static function checked( $ref ) {
		return self::is_valid( $ref ) ? $ref : null;
	}
}
