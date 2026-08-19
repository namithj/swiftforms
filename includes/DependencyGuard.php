<?php
/**
 * Runtime dependency compatibility checks.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

final class DependencyGuard {

	/** @param array<class-string, string[]>|null $requirements */
	public static function available( ?array $requirements = null ): bool {
		$requirements ??= array(
			\Pedalcms\CassetteCmf\Core\Manager::class => array( 'init', 'register_from_array', 'get_existing_cpt_handler' ),
			\Pedalcms\CassetteCmf\CassetteCmf::class  => array( 'get_settings_field', 'get_post_field' ),
		);

		foreach ( $requirements as $class => $methods ) {
			if ( ! class_exists( $class ) ) {
				return false;
			}
			foreach ( $methods as $method ) {
				if ( ! method_exists( $class, $method ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
