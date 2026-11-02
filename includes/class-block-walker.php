<?php
/**
 * Walks parsed blocks.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Walker
 *
 * Finds form blocks in parsed blocks (parse_blocks() output) and follows the
 * blocks that pull in other sources.
 *
 * - Every inner block is visited, including blocks whose name this version
 *   does not know (C-2.8): a form inside a newer wrapper block is still found.
 * - A form's ordinal is its 1-based position among the form blocks in the
 *   source's own content, in document order (outer before inner). Content
 *   pulled in from other sources does not count; it has its own ordinals.
 * - With expansion, core/block (ref), core/template-part (slug, theme of the
 *   active theme) and core/pattern (slug) are followed up to DEPTH_LIMIT
 *   levels below the starting source. A form found there belongs to the
 *   nested source ("a form inside core/block 7 belongs to post:7"). A source
 *   that is already on the way down is not entered again (cycles), and a
 *   wp_block is entered only when it is published, as core renders it.
 * - core/post-content is never followed: it shows the page being rendered,
 *   which the render-time source stack handles.
 */
class Block_Walker {

	/**
	 * How many sources deep expansion goes below the starting source (ADR-001).
	 */
	const DEPTH_LIMIT = 5;

	/**
	 * Form blocks in the given blocks, in document order, with their ordinals.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array{ordinal:int, block:array<string, mixed>}>
	 */
	public static function forms( array $blocks ) {
		$found = [];
		self::each_block(
			$blocks,
			static function ( array $block ) use ( &$found ) {
				if ( Field_Types::FORM_BLOCK === ( $block['blockName'] ?? null ) ) {
					$found[] = [
						'ordinal' => count( $found ) + 1,
						'block'   => $block,
					];
				}
			}
		);
		return $found;
	}

	/**
	 * Call a function for every block, outer before inner, in document order, whatever its name.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Parsed blocks.
	 * @param callable $callback Receives each block array.
	 * @return void
	 */
	public static function each_block( array $blocks, callable $callback ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$callback( $block );
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::each_block( $block['innerBlocks'], $callback );
			}
		}
	}

	/**
	 * Source reference a block pulls in, if it is one of the three kinds that do.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string|null Reference, or null when the block pulls nothing in or the attributes are not valid.
	 */
	public static function reference_of( array $block ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

		switch ( $block['blockName'] ?? null ) {
			case 'core/block':
				return isset( $attrs['ref'] ) && ( is_int( $attrs['ref'] ) || ( is_string( $attrs['ref'] ) && ctype_digit( $attrs['ref'] ) ) )
					? Source_Ref::post( (int) $attrs['ref'] )
					: null;

			case 'core/template-part':
				if ( ! isset( $attrs['slug'] ) || ! is_string( $attrs['slug'] ) ) {
					return null;
				}
				$theme = isset( $attrs['theme'] ) && is_string( $attrs['theme'] ) && '' !== $attrs['theme'] ? $attrs['theme'] : get_stylesheet();
				return Source_Ref::template_part( $theme, $attrs['slug'] );

			case 'core/pattern':
				return isset( $attrs['slug'] ) && is_string( $attrs['slug'] ) ? Source_Ref::pattern( $attrs['slug'] ) : null;
		}

		return null;
	}

	/**
	 * Walk a source: its own forms and, with expansion, the forms of everything it pulls in.
	 *
	 * Result keys:
	 *   forms     Every form found: source (the source it belongs to), ordinal (within that source), block. No duplicates.
	 *   edges     [from, to] pairs: "from" contains a block that pulls in "to". Every valid reference is listed, whether or not
	 *             it could be loaded.
	 *   expanded  Sources whose own content was read (the starting source and every nested source that was entered).
	 *             Their own forms and edges are complete. A reference that was not entered (depth limit, cycle, not
	 *             loadable) is in "edges" but not here.
	 *
	 * @param string $ref    Reference of the source the blocks belong to.
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks of that source.
	 * @param bool   $expand Whether to follow core/block, core/template-part and core/pattern.
	 * @return array{forms:array<int, array{source:string, ordinal:int, block:array<string, mixed>}>, edges:array<int, array{0:string, 1:string}>, expanded:string[]}
	 */
	public static function walk( $ref, array $blocks, $expand = true ) {
		$result = [
			'forms'    => [],
			'edges'    => [],
			'expanded' => [],
		];
		$seen   = [
			'forms'  => [],
			'edges'  => [],
			'depths' => [],
		];

		self::visit( $ref, $blocks, 0, [ $ref ], (bool) $expand, $result, $seen );

		$result['expanded'] = array_keys( $seen['depths'] );
		return $result;
	}

	/**
	 * Read one source and enter the sources it pulls in.
	 *
	 * @param string $ref    Source reference.
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param int    $depth  Levels below the starting source.
	 * @param string[] $path References from the starting source down to this one.
	 * @param bool   $expand Whether to follow references.
	 * @param array<string, mixed> $result Result being built.
	 * @param array<string, mixed> $seen   Bookkeeping to avoid duplicates and repeated work.
	 * @return void
	 */
	private static function visit( $ref, array $blocks, $depth, array $path, $expand, array &$result, array &$seen ) {
		$seen['depths'][ $ref ] = $depth;

		foreach ( self::forms( $blocks ) as $form ) {
			$key = $ref . '|' . $form['ordinal'];
			if ( isset( $seen['forms'][ $key ] ) ) {
				continue;
			}
			$seen['forms'][ $key ] = true;
			$result['forms'][]     = [
				'source'  => $ref,
				'ordinal' => $form['ordinal'],
				'block'   => $form['block'],
			];
		}

		$targets = [];
		self::each_block(
			$blocks,
			static function ( array $block ) use ( &$targets ) {
				$target = self::reference_of( $block );
				if ( null !== $target ) {
					$targets[ $target ] = true;
				}
			}
		);

		foreach ( array_keys( $targets ) as $target ) {
			$edge = $ref . ' ' . $target;
			if ( ! isset( $seen['edges'][ $edge ] ) ) {
				$seen['edges'][ $edge ] = true;
				$result['edges'][]      = [ $ref, $target ];
			}

			if ( ! $expand || in_array( $target, $path, true ) || $depth + 1 > self::DEPTH_LIMIT ) {
				continue;
			}
			// Already read at this depth or higher up: nothing new below it.
			if ( isset( $seen['depths'][ $target ] ) && $seen['depths'][ $target ] <= $depth + 1 ) {
				continue;
			}

			$loaded = Source_Loader::load( $target );
			if ( null === $loaded || ! self::may_enter( $loaded['state'] ) ) {
				continue;
			}
			self::visit( $target, $loaded['blocks'], $depth + 1, array_merge( $path, [ $target ] ), $expand, $result, $seen );
		}
	}

	/**
	 * Whether the content of a pulled-in source is shown at all.
	 *
	 * Core renders a wp_block only when it is a published wp_block; a template
	 * part, pattern or post source is entered when it loaded.
	 *
	 * @param array<string, mixed> $state Source state.
	 * @return bool
	 */
	private static function may_enter( array $state ) {
		if ( 'post' === $state['kind'] ) {
			return 'wp_block' === $state['post_type'] && 'publish' === $state['status'];
		}
		return true;
	}
}
