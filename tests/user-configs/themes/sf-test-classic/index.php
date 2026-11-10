<?php
/**
 * Fixture classic theme: one template for everything.
 *
 * @package SwiftForms
 */

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main>
<?php
while ( have_posts() ) {
	the_post();
	the_content();
}
?>
</main>
<?php dynamic_sidebar( 'sf-sidebar' ); ?>
<?php wp_footer(); ?>
</body>
</html>
