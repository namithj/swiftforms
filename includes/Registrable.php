<?php
/**
 * Contract for services that hook themselves into WordPress.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

/**
 * Any service implementing this is expected to be side-effect free until
 * register() runs, which keeps every class independently unit-testable.
 */
interface Registrable {

	/**
	 * Adds this service's hooks to WordPress.
	 */
	public function register(): void;
}
