<?php
/**
 * Activation and deactivation lifecycle.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

/**
 * Static handlers passed directly to register_activation_hook() /
 * register_deactivation_hook(), so they run even before `plugins_loaded`
 * (and therefore before the container exists).
 */
final class Activation {

	/**
	 * Registers CPTs so rewrite rules include them, then flushes.
	 */
	public static function activate(): void {
		( new PostTypes() )->register_post_types();

		flush_rewrite_rules();
	}

	/**
	 * Clears scheduled events and rewrite rules.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Privacy::CLEANUP_HOOK );
		flush_rewrite_rules();
	}
}
