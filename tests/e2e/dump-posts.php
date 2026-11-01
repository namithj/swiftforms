<?php
/**
 * WP-CLI helper for bin/run-e2e-configs.mjs: prints the posts a user config
 * created, as JSON between two marker lines (wp-env adds its own log lines to
 * the output, so the runner extracts the block between the markers).
 *
 * Run through: wp eval-file wp-content/plugins/swiftforms/tests/e2e/dump-posts.php
 *
 * @package SwiftForms
 */

function swiftforms_e2e_dump_posts() {
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
	foreach ( $posts as $post ) {
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->show_in_rest || ! post_type_supports( $post->post_type, 'editor' ) ) {
			continue;
		}
		$rows[] = [
			'id'        => (int) $post->ID,
			'type'      => $post->post_type,
			'restBase'  => $type->rest_base ? $type->rest_base : $post->post_type,
			'title'     => $post->post_title,
			'hasBlocks' => has_blocks( $post ),
			'hasForms'  => false !== strpos( $post->post_content, '<!-- wp:swiftforms/' ),
		];
	}

	echo "\nSF_STATE_BEGIN\n" . wp_json_encode( $rows ) . "\nSF_STATE_END\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

swiftforms_e2e_dump_posts();
