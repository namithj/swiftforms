<?php
/**
 * Demo content for the WordPress Playground blueprint (assets/blueprints/blueprint.json).
 *
 * This file is the readable source of the `runPHP` step. `node assets/wporg-src/build-blueprint.mjs`
 * embeds it into the blueprint. It is not part of the plugin and is excluded from the ZIP.
 *
 * It only calls public parts of the plugin: the registered patterns, core post functions, and the plugin's own
 * PHP classes for the sample entries (Schema_Resolver, Validator, Schema_Store, Entries_Repository). No raw SQL.
 * No request leaves the instance.
 */

require '/wordpress/wp-load.php';

wp_set_current_user( 1 );
kses_remove_filters();

/**
 * Content of a registered SwiftForms pattern.
 *
 * @param string $slug Pattern slug, for example swiftforms/contact.
 * @return string
 */
function sfd_pattern( $slug ) {
	$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
	$content = is_array( $pattern ) ? (string) $pattern['content'] : '';
	// What the editor does on insert: the Form block gets its own formId.
	$form_id = wp_generate_uuid4();
	if ( false !== strpos( $content, '<!-- wp:swiftforms/form {' ) ) {
		return str_replace( '<!-- wp:swiftforms/form {', '<!-- wp:swiftforms/form {"formId":"' . $form_id . '",', $content );
	}
	return str_replace( '<!-- wp:swiftforms/form -->', '<!-- wp:swiftforms/form {"formId":"' . $form_id . '"} -->', $content );
}

/**
 * The formId of the first swiftforms/form block in some content (written on save).
 *
 * @param array $blocks Parsed blocks.
 * @return string
 */
function sfd_form_id( array $blocks ) {
	foreach ( $blocks as $block ) {
		if ( 'swiftforms/form' === $block['blockName'] && ! empty( $block['attrs']['formId'] ) ) {
			return (string) $block['attrs']['formId'];
		}
		$found = sfd_form_id( $block['innerBlocks'] );
		if ( '' !== $found ) {
			return $found;
		}
	}
	return '';
}

// The site.
update_option( 'blogdescription', 'Forms built in the block editor' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', 100 );

// Contact and Get a quote pages, from the patterns.
$pages = array(
	100 => array( 'Contact', 'contact', 'swiftforms/contact' ),
	101 => array( 'Get a quote', 'get-a-quote', 'swiftforms/quote-request' ),
);
foreach ( $pages as $id => $page ) {
	wp_insert_post(
		array(
			'import_id'    => $id,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $page[0],
			'post_name'    => $page[1],
			'post_content' => sfd_pattern( $page[2] ),
		)
	);
}

// A newsletter form in the footer template part of the active theme.
$theme  = get_stylesheet();
$footer = get_block_file_template( $theme . '//footer', 'wp_template_part' );
$part   = '<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->'
	. '<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">'
	. sfd_pattern( 'swiftforms/newsletter-signup' )
	. '</div><!-- /wp:group -->'
	. ( $footer ? $footer->content : '' );
$part_id = wp_insert_post(
	array(
		'post_type'    => 'wp_template_part',
		'post_status'  => 'publish',
		'post_title'   => 'Footer',
		'post_name'    => 'footer',
		'post_content' => $part,
	)
);
wp_set_object_terms( $part_id, $theme, 'wp_theme' );
wp_set_object_terms( $part_id, 'footer', 'wp_template_part_area' );
// Saved a second time so the form in the part gets its formId written, now that the theme term exists.
wp_update_post(
	array(
		'ID'           => $part_id,
		'post_content' => $part,
	)
);

$contact_id = sfd_form_id( parse_blocks( get_post_field( 'post_content', 100 ) ) );
$quote_id   = sfd_form_id( parse_blocks( get_post_field( 'post_content', 101 ) ) );
$news_id    = sfd_form_id( parse_blocks( get_post_field( 'post_content', $part_id ) ) );

// Eight sample entries through the plugin's own classes: [ form, source, days ago, values, read, starred ].
$samples = array(
	array( $contact_id, 'post:100', 9, array( 'name' => 'Maya Okafor', 'email' => 'maya@example.com', 'subject' => 'Opening hours', 'message' => 'Hi, are you open on public holidays? I would like to visit next week.' ), true, false ),
	array( $contact_id, 'post:100', 6, array( 'name' => 'Daniel Reyes', 'email' => 'daniel@example.com', 'subject' => 'Partnership idea', 'message' => 'We run a small newsletter for local makers and would love to feature your work.' ), false, false ),
	array( $contact_id, 'post:100', 1, array( 'name' => 'Priya Nair', 'email' => 'priya@example.com', 'subject' => 'Question about delivery', 'message' => 'Do you deliver outside the city? Thank you!' ), false, false ),
	array( $quote_id, 'post:101', 8, array( 'name' => 'Tom Bergström', 'email' => 'tom@example.com', 'phone' => '+1 555 0100', 'company' => 'Bergström Joinery', 'service' => 'Design', 'timeline' => 'Within a month', 'details' => 'A new logo and a one-page website for our workshop.' ), true, false ),
	array( $quote_id, 'post:101', 4, array( 'name' => 'Aisha Rahman', 'email' => 'aisha@example.com', 'phone' => '', 'company' => 'Rahman & Co', 'service' => 'Consulting', 'timeline' => 'As soon as possible', 'details' => 'We need advice on moving our shop to WordPress.' ), false, true ),
	array( $quote_id, 'post:101', 2, array( 'name' => 'Lukas Meyer', 'email' => 'lukas@example.com', 'phone' => '+49 555 0101', 'company' => '', 'service' => 'Development', 'timeline' => 'No rush', 'details' => 'A booking widget for our guest house.' ), false, false ),
	array( $news_id, 'template_part:' . $theme . '//footer', 5, array( 'email' => 'sara@example.com', 'consent' => '1' ), false, false ),
	array( $news_id, 'template_part:' . $theme . '//footer', 0, array( 'email' => 'jonas@example.com', 'consent' => '1' ), false, false ),
);

foreach ( $samples as $sample ) {
	$schema = \SwiftForms\Schema_Resolver::resolve( $sample[0], $sample[1] );
	if ( null === $schema ) {
		continue;
	}
	$ctx         = new \SwiftForms\Submission_Context( $schema );
	$ctx->values = \SwiftForms\Validator::collect( $schema, $sample[3], $ctx );
	if ( array() !== \SwiftForms\Validator::validate( $schema, $ctx->values, $ctx ) ) {
		continue;
	}
	$ctx->created_gmt = gmdate( 'Y-m-d H:i:s', time() - $sample[2] * DAY_IN_SECONDS - 3600 );
	\SwiftForms\Schema_Store::save( $schema );
	$entry_id = \SwiftForms\Entries_Repository::insert( $ctx );
	if ( $entry_id > 0 ) {
		\SwiftForms\Entries_Repository::update(
			$entry_id,
			array(
				'status'  => $sample[4] ? 'read' : 'new',
				'starred' => $sample[5],
			)
		);
	}
}

// Quiet the editor's first-run guides for the demo user.
update_user_meta(
	1,
	'wp_persisted_preferences',
	array(
		'core/edit-post' => array(
			'welcomeGuide' => false,
			'fullscreenMode' => false,
		),
		'core/edit-site' => array( 'welcomeGuide' => false ),
		'core'           => array( 'enableChoosePatternModal' => false ),
		'_modified'      => gmdate( 'c' ),
	)
);
