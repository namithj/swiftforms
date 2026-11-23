<?php
/**
 * Fixture classic theme: registers the one sidebar the configs place widgets in.
 *
 * @package BlockForms
 */

/**
 * Register the sidebar "bf-sidebar".
 *
 * @return void
 */
function bf_test_classic_register_sidebar() {
	register_sidebar(
		[
			'id'            => 'bf-sidebar',
			'name'          => 'BF Sidebar',
			'before_widget' => '<section id="%1$s" class="widget %2$s">',
			'after_widget'  => '</section>',
			'before_title'  => '<h2 class="widget-title">',
			'after_title'   => '</h2>',
		]
	);
}
add_action( 'widgets_init', 'bf_test_classic_register_sidebar' );
