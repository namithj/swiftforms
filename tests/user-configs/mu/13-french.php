<?php
/**
 * Config 13 snippet: loads the French fixture translation for the "blockforms" text domain.
 *
 * A site in French has the plugin's .mo in wp-content/languages/plugins/; the fixture sits next to the configs.
 * Loaded by the loader after the site settings (WPLANG fr_FR) are written. Plugin code is not touched.
 *
 * @package BlockForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

load_textdomain( 'blockforms', dirname( __DIR__ ) . '/fixtures/languages/blockforms-fr_FR.mo', 'fr_FR' );
