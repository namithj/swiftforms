<?php
/**
 * Config 15 snippet: the classic theme's sidebar is registered.
 *
 * On a real site the active theme's functions.php runs on every request and registers "bf-sidebar" on widgets_init.
 * The regression run switches themes without loading the new theme's functions.php, so this snippet loads it once and
 * registers the sidebar, which is what the form index needs to see a block widget as active (and what the widget
 * area render needs to draw it).
 *
 * @package BlockForms
 */

if ( ! function_exists( 'bf_test_classic_register_sidebar' ) ) {
	require_once get_stylesheet_directory() . '/functions.php';
}
bf_test_classic_register_sidebar();
