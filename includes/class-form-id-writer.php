<?php
/**
 * Writes effective formIds into saved content.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Id_Writer
 *
 * On wp_insert_post_data, a form block whose effective formId differs from the stored
 * one (a copy, a missing or invalid ID, a duplicate inside the source) gets the
 * effective value written into its comment delimiter (C-2.7). The change is a targeted
 * text replacement inside that one delimiter: the content is not parsed and serialised
 * again, so nothing else in it can change.
 *
 * - `"formId":"<old>"` is replaced when it occurs exactly once in the delimiter. When the
 *   attribute is absent, `"formId":"<new>"` is added as the first attribute (also to
 *   `<!-- wp:swiftforms/form -->` and the self-closing form). Anything else (two matches,
 *   a non-string value, a delimiter that cannot be located) leaves the content untouched
 *   and writes one error row to the log.
 * - An owner's stored ID is never rewritten.
 * - Revisions, autosaves, wp_navigation and posts that have no ID yet are skipped. A new post
 *   is written on its next save; rendering already uses the effective ID.
 * - The filter receives slashed data: unslash, work, slash again.
 */
class Form_Id_Writer {

	/**
	 * Opening delimiter of a form block: optional JSON attributes, optional self-closing slash.
	 */
	const DELIMITER = '/<!--\s+wp:swiftforms\/form(?:\s+(\{(?:(?!-->).)*?\}))?\s*(\/)?-->/s';

	/**
	 * Hook the filter, late so other callbacks have finished with the content.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_insert_post_data', [ self::class, 'filter' ], 99, 2 );
	}

	/**
	 * Rewrite effective formIds in a post that is being saved.
	 *
	 * @param array<string, mixed> $data    Slashed, sanitized post data.
	 * @param array<string, mixed> $postarr Sanitized post array (has the ID, slashed).
	 * @return array<string, mixed>
	 */
	public static function filter( $data, $postarr = [] ) {
		if ( ! is_array( $data ) || ! isset( $data['post_content'] ) || ! is_string( $data['post_content'] ) ) {
			return $data;
		}
		if ( false === strpos( $data['post_content'], '<!-- wp:swiftforms/form' ) ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$type    = (string) ( $data['post_type'] ?? '' );
		$status  = (string) ( $data['post_status'] ?? '' );
		if ( $post_id < 1 || in_array( $type, [ 'revision', 'wp_navigation', 'nav_menu_item' ], true ) || in_array( $status, [ 'inherit', 'auto-draft' ], true ) ) {
			return $data;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $data;
		}
		// Template and part posts are sources under their theme//slug reference.
		$probe            = clone $post;
		$probe->post_name = (string) ( $data['post_name'] ?? $post->post_name );
		$ref              = Form_Index::ref_for_post( $probe );
		if ( null === $ref ) {
			return $data;
		}

		$content = self::rewrite( wp_unslash( $data['post_content'] ), $ref );
		if ( null !== $content ) {
			$data['post_content'] = wp_slash( $content );
		}
		return $data;
	}

	/**
	 * Rewrite the form delimiters of unslashed content.
	 *
	 * @param string $content Stored content (unslashed).
	 * @param string $ref     Source reference the content belongs to.
	 * @return string|null The new content, or null when nothing changed.
	 */
	public static function rewrite( $content, $ref ) {
		$plan = Form_Index::plan( $ref, Block_Walker::forms( parse_blocks( $content ) ) );

		$wanted = [];
		foreach ( $plan as $position => $entry ) {
			if ( $entry['effective'] !== $entry['stored'] ) {
				$wanted[ $position ] = $entry;
			}
		}
		if ( ! $wanted ) {
			return null;
		}

		$found = preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( false === $found || count( $matches ) !== count( $plan ) ) {
			self::fail( $ref, 'The form blocks could not be matched to their delimiters.' );
			return null;
		}

		// Work from the last block backwards so earlier offsets stay valid.
		$result = $content;
		foreach ( array_reverse( array_keys( $wanted ), false ) as $position ) {
			$entry      = $wanted[ $position ];
			$match      = $matches[ $position ];
			$delimiter  = $match[0][0];
			$offset     = $match[0][1];
			$attributes = isset( $match[1] ) && -1 !== $match[1][1] ? $match[1][0] : '';

			$decoded = '' === $attributes ? [] : json_decode( $attributes, true );
			if ( ! is_array( $decoded ) ) {
				self::fail( $ref, 'A form block has attributes that are not valid JSON.' );
				continue;
			}
			// The delimiter must be the block the plan is about.
			$found_id = $decoded['formId'] ?? '';
			if ( ( Uuid::is_valid( $found_id ) ? $found_id : '' ) !== $entry['stored'] ) {
				self::fail( $ref, 'A form block does not match its place in the content.' );
				continue;
			}

			$replacement = self::replace_in_delimiter( $delimiter, $match, $decoded, $entry['effective'] );
			if ( null === $replacement ) {
				self::fail( $ref, 'The formId could not be replaced safely (zero or several matches).' );
				continue;
			}
			$result = substr_replace( $result, $replacement, $offset, strlen( $delimiter ) );
		}

		return $result === $content ? null : $result;
	}

	/**
	 * New text for one opening delimiter.
	 *
	 * @param string $delimiter The delimiter text.
	 * @param array<int, array{0:string, 1:int}> $match preg match with offsets: [0] whole, [1] attributes JSON, [2] self-closing slash.
	 * @param array<string, mixed> $decoded Decoded attributes.
	 * @param string $effective Effective formId to write.
	 * @return string|null Null when the replacement is not safe.
	 */
	private static function replace_in_delimiter( $delimiter, array $match, array $decoded, $effective ) {
		$new_pair = '"formId":"' . $effective . '"';

		if ( array_key_exists( 'formId', $decoded ) ) {
			if ( ! is_string( $decoded['formId'] ) ) {
				return null;
			}
			// Invalid or empty stored IDs are replaced like valid ones, but only by their exact text.
			$old_pair = '"formId":' . wp_json_encode( $decoded['formId'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( 1 !== substr_count( $delimiter, $old_pair ) ) {
				return null;
			}
			return str_replace( $old_pair, $new_pair, $delimiter );
		}

		// No formId: add it as the first attribute.
		$self_closing = isset( $match[2] ) && -1 !== $match[2][1];
		$attributes   = isset( $match[1] ) && -1 !== $match[1][1] ? $match[1][0] : '';

		if ( '' === $attributes ) {
			return '<!-- wp:swiftforms/form {' . $new_pair . '}' . ( $self_closing ? ' /-->' : ' -->' );
		}

		$inner = trim( substr( $attributes, 1, -1 ) );
		$json  = '{' . $new_pair . ( '' === $inner ? '' : ',' . $inner ) . '}';
		return '<!-- wp:swiftforms/form ' . $json . ( $self_closing ? ' /-->' : ' -->' );
	}

	/**
	 * Log a refusal to rewrite. No content is ever included.
	 *
	 * @param string $ref     Source reference.
	 * @param string $message What went wrong.
	 * @return void
	 */
	private static function fail( $ref, $message ) {
		Log::add( 'error', 'error', $message, [ 'source' => $ref ], 'form-id' );
	}
}
