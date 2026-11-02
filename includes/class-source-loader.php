<?php
/**
 * Loads the stored blocks of a source.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Source_Loader
 *
 * Turns a source reference (ADR-001) into parsed blocks plus the facts the
 * form index needs to decide whether the source is open to the public.
 *
 * The state array always has "kind" (post, template, template_part, pattern
 * or widget), "title", "active" and "origin"; posts add the rest:
 *
 *   kind      post | template | template_part | pattern | widget
 *   title     Display title (may be empty).
 *   active    Whether the source can be shown at all right now: a post always
 *             (its status is a separate fact), a template or part of the active
 *             theme, a registered pattern, a widget in an active sidebar.
 *   origin    db | file | registry | option | post
 *   post_id, post_type, status, viewable, password, date_gmt   Posts only.
 *             "viewable" is is_post_type_viewable() and is_post_status_viewable().
 *             "password" is true when the post is password-protected.
 *             "date_gmt" is post_date_gmt as stored.
 *
 * Nothing here writes, and nothing trusts a request: it reads stored content.
 */
class Source_Loader {

	/**
	 * Load a source.
	 *
	 * @param string $ref Source reference (see Source_Ref).
	 * @return array{ref:string, blocks:array<int, array<string, mixed>>, state:array<string, mixed>}|null Null when the reference is invalid or the source does not exist (or, for a template, is not the active theme's).
	 */
	public static function load( $ref ) {
		$parsed = Source_Ref::parse( $ref );
		if ( null === $parsed ) {
			return null;
		}

		switch ( $parsed['type'] ) {
			case 'post':
				$post = self::get_post( $parsed['post_id'] );
				if ( null === $post ) {
					return null;
				}
				return self::result( $ref, (string) $post->post_content, self::post_state( $post ) );

			case 'template':
			case 'template_part':
				$template = self::get_template( $parsed );
				if ( null === $template ) {
					return null;
				}
				return self::result( $ref, (string) $template->content, self::template_state( $parsed['type'], $template ) );

			case 'pattern':
				$pattern = self::get_pattern( $parsed['id'] );
				if ( null === $pattern ) {
					return null;
				}
				return self::result(
					$ref,
					(string) $pattern['content'],
					[
						'kind'   => 'pattern',
						'title'  => isset( $pattern['title'] ) ? (string) $pattern['title'] : '',
						'active' => true,
						'origin' => 'registry',
					]
				);

			case 'widget':
				$widget = self::get_widget( $parsed['number'] );
				if ( null === $widget ) {
					return null;
				}
				return self::result( $ref, $widget['content'], $widget['state'] );
		}

		return null;
	}

	/**
	 * Load only the state of a source (no parsing), for liveness checks.
	 *
	 * @param string $ref Source reference.
	 * @return array<string, mixed>|null The state array, or null as load() does.
	 */
	public static function state( $ref ) {
		$parsed = Source_Ref::parse( $ref );
		if ( null === $parsed ) {
			return null;
		}

		switch ( $parsed['type'] ) {
			case 'post':
				$post = self::get_post( $parsed['post_id'] );
				return null === $post ? null : self::post_state( $post );

			case 'template':
			case 'template_part':
				$template = self::get_template( $parsed );
				return null === $template ? null : self::template_state( $parsed['type'], $template );

			case 'pattern':
				return class_exists( 'WP_Block_Patterns_Registry' ) && \WP_Block_Patterns_Registry::get_instance()->is_registered( $parsed['id'] )
					? [
						'kind'   => 'pattern',
						'title'  => '',
						'active' => true,
						'origin' => 'registry',
					]
					: null;

			case 'widget':
				$widget = self::get_widget( $parsed['number'] );
				return null === $widget ? null : $widget['state'];
		}

		return null;
	}

	/**
	 * Whether a template or part reference belongs to the active theme.
	 *
	 * @param string $ref Source reference.
	 * @return bool False for every other kind of reference.
	 */
	public static function is_active_theme_template( $ref ) {
		$parsed = Source_Ref::parse( $ref );
		return null !== $parsed && isset( $parsed['theme'] ) && get_stylesheet() === $parsed['theme'];
	}

	/**
	 * Build the load() result.
	 *
	 * @param string               $ref     Source reference.
	 * @param string               $content Stored block markup.
	 * @param array<string, mixed> $state   Source state.
	 * @return array{ref:string, blocks:array<int, array<string, mixed>>, state:array<string, mixed>}
	 */
	private static function result( $ref, $content, array $state ) {
		return [
			'ref'    => $ref,
			'blocks' => parse_blocks( $content ),
			'state'  => $state,
		];
	}

	/**
	 * Fetch a post, leaving out the kinds that are never a source.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|null
	 */
	private static function get_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post || in_array( $post->post_type, [ 'revision', 'nav_menu_item' ], true ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * State of a post source.
	 *
	 * @param \WP_Post $post The post.
	 * @return array<string, mixed>
	 */
	private static function post_state( \WP_Post $post ) {
		return [
			'kind'      => 'post',
			'title'     => (string) $post->post_title,
			'active'    => true,
			'origin'    => 'post',
			'post_id'   => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'status'    => (string) $post->post_status,
			'viewable'  => is_post_type_viewable( $post->post_type ) && is_post_status_viewable( $post->post_status ),
			'password'  => '' !== (string) $post->post_password,
			'date_gmt'  => (string) $post->post_date_gmt,
		];
	}

	/**
	 * Fetch a template or part of the active theme, from the database or from a theme file.
	 *
	 * @param array<string, mixed> $parsed Result of Source_Ref::parse().
	 * @return \WP_Block_Template|null
	 */
	private static function get_template( array $parsed ) {
		if ( get_stylesheet() !== $parsed['theme'] ) {
			return null;
		}
		$template = get_block_template( $parsed['id'], 'template' === $parsed['type'] ? 'wp_template' : 'wp_template_part' );
		return $template instanceof \WP_Block_Template ? $template : null;
	}

	/**
	 * State of a template or part.
	 *
	 * @param string             $kind     template or template_part.
	 * @param \WP_Block_Template $template The template.
	 * @return array<string, mixed>
	 */
	private static function template_state( $kind, \WP_Block_Template $template ) {
		return [
			'kind'   => $kind,
			'title'  => is_string( $template->title ) ? $template->title : '',
			'active' => 'publish' === $template->status,
			'origin' => 'theme' === $template->source ? 'file' : 'db',
		];
	}

	/**
	 * Fetch a registered pattern.
	 *
	 * @param string $name Pattern name.
	 * @return array<string, mixed>|null
	 */
	private static function get_pattern( $name ) {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return null;
		}
		$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $name );
		return ( is_array( $pattern ) && isset( $pattern['content'] ) && is_string( $pattern['content'] ) ) ? $pattern : null;
	}

	/**
	 * Fetch a block widget.
	 *
	 * "active" is true only when the widget sits in a registered sidebar that is
	 * not the inactive-widgets area.
	 *
	 * @param int $number Widget number.
	 * @return array{content:string, state:array<string, mixed>}|null
	 */
	private static function get_widget( $number ) {
		$widgets = get_option( 'widget_block' );
		if ( ! is_array( $widgets ) || ! isset( $widgets[ $number ] ) || ! is_array( $widgets[ $number ] ) ) {
			return null;
		}

		return [
			'content' => isset( $widgets[ $number ]['content'] ) ? (string) $widgets[ $number ]['content'] : '',
			'state'   => [
				'kind'   => 'widget',
				'title'  => '',
				'active' => self::widget_is_active( 'block-' . $number ),
				'origin' => 'option',
			],
		];
	}

	/**
	 * Whether a widget ID sits in a registered, non-inactive sidebar.
	 *
	 * @param string $widget_id For example "block-3".
	 * @return bool
	 */
	private static function widget_is_active( $widget_id ) {
		global $wp_registered_sidebars;

		$sidebars = (array) get_option( 'sidebars_widgets', [] ); // Not wp_get_sidebars_widgets(): Plugin Check forbids it, and Assets reads the same option.
		foreach ( $sidebars as $sidebar => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar || 'array_version' === $sidebar || ! is_array( $widget_ids ) ) {
				continue;
			}
			if ( ! isset( $wp_registered_sidebars[ $sidebar ] ) ) {
				continue;
			}
			if ( in_array( $widget_id, $widget_ids, true ) ) {
				return true;
			}
		}
		return false;
	}
}
