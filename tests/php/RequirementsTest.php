<?php
/**
 * Supported platform guard tests.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests;

final class RequirementsTest extends TestCase {

	public function test_supported_platform_boundaries_match_plugin_headers(): void {
		$this->assertTrue( smartlogix_swiftforms_meets_requirements( '6.6', '8.2' ) );
		$this->assertTrue( smartlogix_swiftforms_meets_requirements( '7.0', '8.4' ) );
		$this->assertFalse( smartlogix_swiftforms_meets_requirements( '6.5.5', '8.2' ) );
		$this->assertFalse( smartlogix_swiftforms_meets_requirements( '6.6', '8.1.30' ) );
	}
}
