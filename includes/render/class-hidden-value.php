<?php
/**
 * Value of a hidden field.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Hidden_Value
 *
 * A hidden field is computed on the server from its "value" attribute and the
 * source of the form (C-3.10): a posted value is ignored. The value is static
 * text, or text with one or more of the four v1 tokens:
 *
 * - {post:id}, {post:title}, {post:url}: the post the form belongs to. Only a form
 *   whose source is a post has one (a form in a template, part, pattern or widget has
 *   none, because the page that shows it is not part of what the server can verify at
 *   submit time); the token is then empty.
 * - {user:id}: the ID of the logged-in user, empty for a visitor.
 *
 * Replacement is a single pass over the text, so a title that itself contains a token
 * is not expanded again. Any other brace text (for example "{field:email}") is kept as
 * written. The result is plain text: the caller escapes it for the place it prints it.
 * The renderer and the submission handler (P4) both call resolve().
 */
class Hidden_Value {

	/**
	 * The v1 tokens.
	 */
	const TOKENS = [ '{post:id}', '{post:title}', '{post:url}', '{user:id}' ];

	/**
	 * Resolve a hidden field's value.
	 *
	 * @param string $value      The "value" attribute.
	 * @param string $source_ref Source reference of the form (Source_Ref format).
	 * @param int    $user_id    ID of the user the value is computed for; 0 for none.
	 * @return string
	 */
	public static function resolve( $value, $source_ref, $user_id = 0 ) {
		$value = (string) $value;
		if ( false === strpos( $value, '{' ) ) {
			return $value;
		}

		$replacements = [];
		foreach ( self::TOKENS as $token ) {
			if ( false !== strpos( $value, $token ) ) {
				$replacements[ $token ] = self::token_value( $token, (string) $source_ref, (int) $user_id );
			}
		}

		return [] === $replacements ? $value : strtr( $value, $replacements );
	}

	/**
	 * Value of one token.
	 *
	 * @param string $token      One of TOKENS.
	 * @param string $source_ref Source reference.
	 * @param int    $user_id    User ID.
	 * @return string
	 */
	private static function token_value( $token, $source_ref, $user_id ) {
		if ( '{user:id}' === $token ) {
			return $user_id > 0 ? (string) $user_id : '';
		}

		$parsed = Source_Ref::parse( $source_ref );
		if ( null === $parsed || 'post' !== $parsed['type'] ) {
			return '';
		}
		$post = get_post( $parsed['post_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		switch ( $token ) {
			case '{post:id}':
				return (string) $post->ID;
			case '{post:title}':
				return trim( wp_strip_all_tags( $post->post_title ) );
			case '{post:url}':
				$url = get_permalink( $post );
				return is_string( $url ) ? $url : '';
		}
		return '';
	}
}
