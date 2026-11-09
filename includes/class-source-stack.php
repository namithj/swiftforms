<?php
/**
 * Render-time source stack.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Source_Stack
 *
 * Answers "which stored source is this form being rendered from?" (ADR-001).
 * A form block takes current() as its source, so the same post shown in a blog
 * index and on its single view submits as post:ID in both places.
 *
 * Base (nothing pushed): the current block template (`$_wp_current_template_id`)
 * on block themes, otherwise the loop post, otherwise the queried post.
 *
 * Frames are pushed and popped in pairs:
 *   - pre_render_block / render_block for core/block (post:{ref}), core/template-part
 *     (template_part:{theme}//{slug}), core/pattern (pattern:{slug}) and core/post-content
 *     (post:{the post being rendered}). A block whose reference cannot be read still gets a
 *     frame that repeats the source below it, so push and pop always match.
 *   - the_content, priority 1 / PHP_INT_MAX: the current post (classic themes and shortcodes).
 *   - widget_block_content, priority 1 / PHP_INT_MAX: widget:block-{n}.
 *
 * When some other pre_render_block callback short-circuits a block, core skips render_block
 * for it, so our last pre_render_block callback pops the frame instead. render_block is popped
 * as late as possible, so other render_block filters still see the block's own source. Newer
 * core renders a synced pattern's inner blocks through a second render() call on the same block
 * instance, so render_block fires twice for it: the frame ends with the first firing (all
 * forms inside have rendered by then) and the second one is ignored (remembered by instance).
 *
 * Each frame also counts the forms rendered in it (next_ordinal()).
 */
class Source_Stack {

	/**
	 * Core blocks that start a frame.
	 */
	const BLOCKS = [ 'core/block', 'core/template-part', 'core/pattern', 'core/post-content' ];

	/**
	 * Frame name used by the_content.
	 */
	const FRAME_CONTENT = 'the_content';

	/**
	 * Frame name used by block widgets.
	 */
	const FRAME_WIDGET = 'widget';

	/**
	 * Open frames, innermost last. Each: name, ref (string|null), next (int), ordinals (signature => int).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $frames = [];

	/**
	 * Block instances whose frame already ended (render_block can fire twice for one instance).
	 *
	 * @var \SplObjectStorage<\WP_Block, null>|null
	 */
	private static $ended = null;

	/**
	 * Form counters for forms rendered outside every frame, by base source.
	 *
	 * @var array<string, array{next:int, ordinals:array<string,int>}>
	 */
	private static $base_counters = [];

	/**
	 * Hook the stack.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'pre_render_block', [ self::class, 'on_pre_render_block' ], -1000, 3 );
		add_filter( 'pre_render_block', [ self::class, 'on_pre_render_block_end' ], PHP_INT_MAX, 2 );
		add_filter( 'render_block', [ self::class, 'on_render_block' ], PHP_INT_MAX, 3 );
		add_filter( 'the_content', [ self::class, 'on_content_start' ], 1 );
		add_filter( 'the_content', [ self::class, 'on_content_end' ], PHP_INT_MAX );
		add_filter( 'widget_block_content', [ self::class, 'on_widget_start' ], 1, 3 );
		add_filter( 'widget_block_content', [ self::class, 'on_widget_end' ], PHP_INT_MAX );
	}

	/**
	 * Source reference of the form being rendered right now.
	 *
	 * @return string|null Null when no source can be told (for example outside any request context).
	 */
	public static function current() {
		$count = count( self::$frames );
		if ( $count > 0 ) {
			return self::$frames[ $count - 1 ]['ref'];
		}
		return self::base();
	}

	/**
	 * Ordinal for the next form block rendered in the current frame (1-based).
	 *
	 * The form index keys missing and duplicated IDs on the form's position among the
	 * source's own form blocks (C-2.7). A form inside a query loop renders once per post
	 * in the same frame, so pass a signature (for example md5( serialize_block( $block ) )):
	 * a signature already seen in this frame returns the ordinal it got the first time.
	 * Two form blocks with identical markup in one frame therefore share an ordinal.
	 *
	 * @param string $signature Identifies the block; an empty string always counts up.
	 * @return int
	 */
	public static function next_ordinal( $signature = '' ) {
		$count = count( self::$frames );
		if ( $count > 0 ) {
			$counter = &self::$frames[ $count - 1 ];
		} else {
			$base = (string) self::base();
			if ( ! isset( self::$base_counters[ $base ] ) ) {
				self::$base_counters[ $base ] = [
					'next'     => 0,
					'ordinals' => [],
				];
			}
			$counter = &self::$base_counters[ $base ];
		}

		$signature = (string) $signature;
		if ( '' !== $signature && isset( $counter['ordinals'][ $signature ] ) ) {
			return $counter['ordinals'][ $signature ];
		}

		++$counter['next'];
		if ( '' !== $signature ) {
			$counter['ordinals'][ $signature ] = $counter['next'];
		}
		return $counter['next'];
	}

	/**
	 * Forget every frame and counter. For tests and for code that caught an exception mid-render.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$frames        = [];
		self::$base_counters = [];
		self::$ended         = null;
	}

	/**
	 * Number of open frames (tests assert it is back to zero).
	 *
	 * @return int
	 */
	public static function depth() {
		return count( self::$frames );
	}

	/**
	 * Start a frame for a block that changes the source.
	 *
	 * @param mixed         $pre_render   Pre-render value, passed through.
	 * @param array<string, mixed> $parsed_block Parsed block about to render.
	 * @param \WP_Block|null $parent_block Parent block when nested.
	 * @return mixed
	 */
	public static function on_pre_render_block( $pre_render, $parsed_block = [], $parent_block = null ) {
		$name = is_array( $parsed_block ) ? ( $parsed_block['blockName'] ?? null ) : null;
		if ( in_array( $name, self::BLOCKS, true ) ) {
			$ref = self::block_ref( $name, isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : [], $parent_block );
			self::push( $name, $ref );
		}
		return $pre_render;
	}

	/**
	 * Undo the frame when a later callback short-circuited the block (render_block will not run for it).
	 *
	 * @param mixed $pre_render   Pre-render value after every other callback.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return mixed
	 */
	public static function on_pre_render_block_end( $pre_render, $parsed_block = [] ) {
		if ( null !== $pre_render ) {
			$name = is_array( $parsed_block ) ? ( $parsed_block['blockName'] ?? null ) : null;
			if ( in_array( $name, self::BLOCKS, true ) ) {
				self::pop( $name );
			}
		}
		return $pre_render;
	}

	/**
	 * End the frame of a block that finished rendering.
	 *
	 * @param string         $content      Block output, passed through.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @param \WP_Block|null $instance     The block instance (core passes it).
	 * @return string
	 */
	public static function on_render_block( $content, $parsed_block = [], $instance = null ) {
		$name = is_array( $parsed_block ) ? ( $parsed_block['blockName'] ?? null ) : null;
		if ( ! in_array( $name, self::BLOCKS, true ) ) {
			return $content;
		}

		if ( ! $instance instanceof \WP_Block ) {
			self::pop( $name );
			return $content;
		}

		if ( null === self::$ended ) {
			self::$ended = new \SplObjectStorage();
		}
		if ( ! self::$ended->offsetExists( $instance ) && self::pop( $name ) ) {
			self::$ended->offsetSet( $instance, null );
		}
		return $content;
	}

	/**
	 * Start the post-content frame.
	 *
	 * @param string $content Post content, passed through.
	 * @return string
	 */
	public static function on_content_start( $content ) {
		self::push( self::FRAME_CONTENT, Source_Ref::post( (int) get_the_ID() ) );
		return $content;
	}

	/**
	 * End the post-content frame.
	 *
	 * @param string $content Post content, passed through.
	 * @return string
	 */
	public static function on_content_end( $content ) {
		self::pop( self::FRAME_CONTENT );
		return $content;
	}

	/**
	 * Start the frame of a block widget.
	 *
	 * @param string $content  Widget content, passed through.
	 * @param array<string, mixed> $instance Widget instance (unused).
	 * @param mixed  $widget   The WP_Widget_Block object.
	 * @return string
	 */
	public static function on_widget_start( $content, $instance = [], $widget = null ) {
		$number = ( is_object( $widget ) && isset( $widget->number ) ) ? (int) $widget->number : 0;
		self::push( self::FRAME_WIDGET, $number > 0 ? Source_Ref::widget( $number ) : null );
		return $content;
	}

	/**
	 * End the frame of a block widget.
	 *
	 * @param string $content Widget content, passed through.
	 * @return string
	 */
	public static function on_widget_end( $content ) {
		self::pop( self::FRAME_WIDGET );
		return $content;
	}

	/**
	 * Push a frame. A null reference repeats the source below.
	 *
	 * @param string      $name Frame name.
	 * @param string|null $ref  Source reference, or null to inherit.
	 * @return void
	 */
	private static function push( $name, $ref ) {
		if ( null === $ref ) {
			$ref = self::current();
		}
		self::$frames[] = [
			'name'     => $name,
			'ref'      => $ref,
			'next'     => 0,
			'ordinals' => [],
		];
	}

	/**
	 * Pop the innermost frame when it is the one named. Anything else is left alone.
	 *
	 * @param string $name Frame name.
	 * @return bool Whether a frame was popped.
	 */
	private static function pop( $name ) {
		$count = count( self::$frames );
		if ( $count > 0 && self::$frames[ $count - 1 ]['name'] === $name ) {
			array_pop( self::$frames );
			return true;
		}
		return false;
	}

	/**
	 * Source a block's own content comes from.
	 *
	 * @param string         $name         Block name.
	 * @param array<string, mixed> $attrs Block attributes.
	 * @param \WP_Block|null $parent_block Parent block when nested.
	 * @return string|null
	 */
	private static function block_ref( $name, array $attrs, $parent_block ) {
		switch ( $name ) {
			case 'core/block':
				return isset( $attrs['ref'] ) && ( is_int( $attrs['ref'] ) || ( is_string( $attrs['ref'] ) && ctype_digit( $attrs['ref'] ) ) )
					? Source_Ref::post( (int) $attrs['ref'] )
					: null;

			case 'core/template-part':
				if ( ! isset( $attrs['slug'] ) || ! is_string( $attrs['slug'] ) ) {
					return null;
				}
				$theme = isset( $attrs['theme'] ) && is_string( $attrs['theme'] ) && '' !== $attrs['theme'] ? $attrs['theme'] : get_stylesheet();
				return get_stylesheet() === $theme ? Source_Ref::template_part( $theme, $attrs['slug'] ) : null;

			case 'core/pattern':
				return isset( $attrs['slug'] ) && is_string( $attrs['slug'] ) ? Source_Ref::pattern( $attrs['slug'] ) : null;

			case 'core/post-content':
				// The loop post (the_post() sets it for query loops too); a parent that uses the postId context says so explicitly.
				$post_id = ( $parent_block instanceof \WP_Block && ! empty( $parent_block->context['postId'] ) ) ? (int) $parent_block->context['postId'] : 0;
				return Source_Ref::post( $post_id > 0 ? $post_id : (int) get_the_ID() );
		}

		return null;
	}

	/**
	 * Source when no frame is open.
	 *
	 * @return string|null
	 */
	private static function base() {
		global $_wp_current_template_id;

		if ( is_string( $_wp_current_template_id ) && '' !== $_wp_current_template_id ) {
			$ref = Source_Ref::from_template_id( 'template', $_wp_current_template_id );
			if ( null !== $ref ) {
				return $ref;
			}
		}

		$post_id = (int) get_the_ID();
		if ( $post_id < 1 && did_action( 'wp' ) && is_singular() ) {
			$post_id = (int) get_queried_object_id();
		}
		return Source_Ref::post( $post_id );
	}
}
