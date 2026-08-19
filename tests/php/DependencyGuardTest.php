<?php
/**
 * Dependency collision guard tests.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests;

use SwiftForms\DependencyGuard;

final class DependencyGuardTest extends TestCase {

	public function test_incompatible_preloaded_dependency_fails_cleanly(): void {
		$this->assertFalse( DependencyGuard::available( array( self::class => array( 'missing_api' ) ) ) );
	}

	public function test_locked_runtime_dependency_exposes_required_api(): void {
		$this->assertTrue( DependencyGuard::available() );
	}
}
