<?php
/**
 * Tests for the class autoloader (includes/autoload.php).
 *
 * Verifies every mapped SwiftForms class resolves without an explicit require.
 */

declare(strict_types=1);

class SwiftForms_Autoload_Test extends WP_UnitTestCase {
	/**
	 * @return array<string, array{0:string}>
	 */
	public function mapped_classes(): array {
		return array(
			'core'        => array( SwiftForms_Core::class ),
			'cpts'        => array( SwiftForms_CPTs::class ),
			'blocks'      => array( SwiftForms_Blocks::class ),
			'privacy'     => array( SwiftForms_Privacy::class ),
			'settings'    => array( SwiftForms_Settings::class ),
			'submissions' => array( SwiftForms_Submissions::class ),
		);
	}

	/**
	 * @dataProvider mapped_classes
	 */
	public function test_mapped_classes_are_loadable( string $class_name ): void {
		$this->assertTrue( class_exists( $class_name ) );
	}

	public function test_autoloader_ignores_unknown_classes(): void {
		$this->assertFalse( class_exists( 'SwiftForms_Does_Not_Exist' ) );
	}

	public function test_each_mapped_class_file_exists(): void {
		foreach ( array_keys( $this->mapped_classes() ) as $key ) {
			$file = SWIFTFORMS_PATH . 'includes/class-swiftforms-' . $key . '.php';
			$this->assertFileExists( $file );
		}
	}
}
