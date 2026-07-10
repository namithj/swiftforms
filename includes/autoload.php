<?php
/**
 * SwiftForms class autoloader.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

$swiftforms_class_map = array(
	'SwiftForms_Blocks'      => SWIFTFORMS_PATH . 'includes/class-swiftforms-blocks.php',
	'SwiftForms_Conditions'  => SWIFTFORMS_PATH . 'includes/class-swiftforms-conditions.php',
	'SwiftForms_CPTs'        => SWIFTFORMS_PATH . 'includes/class-swiftforms-cpts.php',
	'SwiftForms_Core'        => SWIFTFORMS_PATH . 'includes/class-swiftforms-core.php',
	'SwiftForms_Privacy'     => SWIFTFORMS_PATH . 'includes/class-swiftforms-privacy.php',
	'SwiftForms_Settings'    => SWIFTFORMS_PATH . 'includes/class-swiftforms-settings.php',
	'SwiftForms_Spam'        => SWIFTFORMS_PATH . 'includes/class-swiftforms-spam.php',
	'SwiftForms_Submissions' => SWIFTFORMS_PATH . 'includes/class-swiftforms-submissions.php',
	'SwiftForms_Templates'   => SWIFTFORMS_PATH . 'includes/class-swiftforms-templates.php',
);

spl_autoload_register(
	static function ( string $class_name ) use ( $swiftforms_class_map ): void {
		if ( ! isset( $swiftforms_class_map[ $class_name ] ) ) {
			return;
		}

		require_once $swiftforms_class_map[ $class_name ];
	}
);

foreach ( $swiftforms_class_map as $swiftforms_class_file ) {
	require_once $swiftforms_class_file;
}
