<?php
/**
 * Tests for the main plugin file (swiftforms.php).
 *
 * Mirrors the plugin root entry point: bootstrap constants, the swiftforms()
 * singleton accessor, and the activation callback.
 */

declare(strict_types=1);

class SwiftForms_Plugin_Test extends WP_UnitTestCase {
    public function test_plugin_constants_are_defined(): void {
        $this->assertTrue(defined('SWIFTFORMS_VERSION'));
        $this->assertTrue(defined('SWIFTFORMS_FILE'));
        $this->assertTrue(defined('SWIFTFORMS_PATH'));
        $this->assertTrue(defined('SWIFTFORMS_URL'));
    }

    public function test_version_constant_matches_plugin_header(): void {
        $this->assertSame('0.1.0', SWIFTFORMS_VERSION);
    }

    public function test_path_constant_points_to_plugin_directory(): void {
        $this->assertFileExists(SWIFTFORMS_PATH . 'swiftforms.php');
        $this->assertSame(trailingslashit(SWIFTFORMS_PATH), SWIFTFORMS_PATH);
    }

    public function test_swiftforms_returns_core_instance(): void {
        $this->assertInstanceOf(SwiftForms_Core::class, swiftforms());
    }

    public function test_swiftforms_returns_singleton_instance(): void {
        $this->assertSame(swiftforms(), swiftforms());
    }

    public function test_swiftforms_activate_registers_custom_post_types(): void {
        swiftforms_activate();

        $this->assertNotNull(get_post_type_object(SwiftForms_CPTs::FORM_POST_TYPE));
        $this->assertNotNull(get_post_type_object(SwiftForms_CPTs::SUBMISSION_POST_TYPE));
    }

    public function test_activation_hook_is_registered(): void {
        $this->assertNotFalse(
            has_action('activate_' . plugin_basename(SWIFTFORMS_FILE), 'swiftforms_activate')
        );
    }
}
