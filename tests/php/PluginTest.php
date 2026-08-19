<?php
/**
 * Tests for plugin bootstrap.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests;

use SwiftForms\Plugin;
use SwiftForms\PostTypes;

final class PluginTest extends TestCase {

	public function test_constants_are_defined(): void {
		$this->assertTrue( defined( 'SMARTLOGIX_SWIFTFORMS_VERSION' ) );
		$this->assertTrue( defined( 'SMARTLOGIX_SWIFTFORMS_PLUGIN_FILE' ) );
		$this->assertTrue( defined( 'SMARTLOGIX_SWIFTFORMS_PLUGIN_PATH' ) );
		$this->assertTrue( defined( 'SMARTLOGIX_SWIFTFORMS_PLUGIN_URL' ) );
	}

	public function test_instance_is_a_singleton(): void {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_boot_registers_both_post_types(): void {
		// Plugin::instance()->boot() already ran once during the shared
		// PHPUnit bootstrap (via plugins_loaded); re-firing `init` here
		// would also re-trigger WP core's own init-time block/post type
		// registration a second time. Just assert the outcome.
		$this->assertTrue( post_type_exists( PostTypes::FORM_POST_TYPE ) );
		$this->assertTrue( post_type_exists( PostTypes::ENTRY_POST_TYPE ) );
	}

	public function test_boot_registers_the_submit_rest_route(): void {
		// rest_get_server() lazily fires `rest_api_init` itself on first
		// access, registering every controller's routes.
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/smartlogix-swiftforms/v1/submit', $routes );
	}

	public function test_boot_is_idempotent(): void {
		$plugin = Plugin::instance();
		$plugin->boot();
		$plugin->boot();

		$this->assertTrue( post_type_exists( PostTypes::FORM_POST_TYPE ) );
	}
}
