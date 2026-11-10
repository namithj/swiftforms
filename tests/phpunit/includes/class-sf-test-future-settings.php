<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test helper, named like the SF_ helpers of the R00 harness.
/**
 * Test double for Settings (C-5.3).
 *
 * @package SwiftForms
 */

use SwiftForms\Settings;

/**
 * Stand-in for a future release: one key whose default differs for new installs.
 *
 * Proves the C-5.3 mechanism, which has no real key in 1.0.
 */
class Sf_Test_Future_Settings extends Settings {

	/**
	 * The base defaults plus a key that existing sites keep as false.
	 *
	 * @return array
	 */
	protected static function base_defaults() {
		$defaults                           = parent::base_defaults();
		$defaults['general']['future_flag'] = false;
		return $defaults;
	}

	/**
	 * Installs at 1.2.0 or later get true.
	 *
	 * @return array
	 */
	protected static function new_install_defaults() {
		return [
			'general.future_flag' => [
				'since' => '1.2.0',
				'value' => true,
			],
		];
	}
}
