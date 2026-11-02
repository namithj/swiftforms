<?php
/**
 * WP-CLI helper for bin/run-e2e-configs.mjs: prints what a user config stored, as JSON between two marker lines
 * (wp-env adds its own log lines to the output, so the runner extracts the block between the markers).
 *
 * One entry per location the browser specs visit: every post, page, synced pattern, template and template part saved to the
 * database that the block editor can open (trashed ones cannot be edited), and every template and part that lives only in the
 * theme's files and holds a form. Fields: kind (post, template, template_part), source (db or theme), id (a post ID, or
 * "theme//slug" for a template), type, restBase, title, status, password (whether a password is set), link (the public address of
 * a page or post, empty otherwise), hasBlocks, hasForms, forms (number of form blocks).
 *
 * Run through: wp eval-file wp-content/plugins/swiftforms/tests/e2e/dump-posts.php
 *
 * @package SwiftForms
 */

function swiftforms_e2e_dump_posts() {
	$count_forms = static function ( $content ) {
		return substr_count( (string) $content, '<!-- wp:swiftforms/form ' ) + substr_count( (string) $content, '<!-- wp:swiftforms/form -->' );
	};

	$posts = get_posts(
		[
			'post_type'      => 'any',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		]
	);

	$rows = [];
	$seen = [];
	foreach ( $posts as $post ) {
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->show_in_rest || ! post_type_supports( $post->post_type, 'editor' ) ) {
			continue;
		}
		$is_template = in_array( $post->post_type, [ 'wp_template', 'wp_template_part' ], true );
		$id          = (int) $post->ID;
		if ( $is_template ) {
			$terms                                = get_the_terms( $post, 'wp_theme' );
			$theme                                = $terms && ! is_wp_error( $terms ) ? $terms[0]->name : get_stylesheet();
			$id                                   = $theme . '//' . $post->post_name;
			$seen[ $post->post_type . ':' . $id ] = true;
		}
		$rows[] = [
			'kind'      => 'wp_template' === $post->post_type ? 'template' : ( 'wp_template_part' === $post->post_type ? 'template_part' : 'post' ),
			'source'    => 'db',
			'id'        => $id,
			'type'      => $post->post_type,
			'restBase'  => $type->rest_base ? $type->rest_base : $post->post_type,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'password'  => '' !== (string) $post->post_password,
			'link'      => is_post_type_viewable( $post->post_type ) && ! $is_template ? (string) get_permalink( $post ) : '',
			'hasBlocks' => has_blocks( $post ),
			'hasForms'  => false !== strpos( $post->post_content, '<!-- wp:swiftforms/' ),
			'forms'     => $count_forms( $post->post_content ),
		];
	}

	// Templates and parts that only exist in the theme's files (never saved): opened, never saved.
	foreach ( [
		'wp_template'      => 'template',
		'wp_template_part' => 'template_part',
	] as $type => $kind ) {
		foreach ( (array) get_block_templates( [], $type ) as $template ) {
			if ( ! $template instanceof WP_Block_Template || 'theme' !== $template->source || isset( $seen[ $type . ':' . $template->id ] ) ) {
				continue;
			}
			if ( false === strpos( (string) $template->content, '<!-- wp:swiftforms/' ) ) {
				continue;
			}
			$rows[] = [
				'kind'      => $kind,
				'source'    => 'theme',
				'id'        => $template->id,
				'type'      => $type,
				'restBase'  => 'wp_template' === $type ? 'templates' : 'template-parts',
				'title'     => is_string( $template->title ) ? $template->title : $template->slug,
				'status'    => 'publish',
				'password'  => false,
				'link'      => '',
				'hasBlocks' => true,
				'hasForms'  => true,
				'forms'     => $count_forms( $template->content ),
			];
		}
	}

	echo "\nSF_STATE_BEGIN\n" . wp_json_encode( $rows ) . "\nSF_STATE_END\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

swiftforms_e2e_dump_posts();
