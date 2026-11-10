<?php
/**
 * Config 13 snippet: loads the French fixture translation for the "swiftforms" text domain.
 *
 * A site in French has the plugin's .mo in wp-content/languages/plugins/; the fixture sits next to the configs.
 * Loaded by the loader after the site settings (WPLANG fr_FR) are written. Plugin code is not touched.
 *
 * @package SwiftForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

load_textdomain( 'swiftforms', dirname( __DIR__ ) . '/fixtures/languages/swiftforms-fr_FR.mo', 'fr_FR' );
