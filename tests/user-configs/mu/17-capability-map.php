<?php
/**
 * Config 17 snippet: a support role sees entries without being an administrator.
 *
 * - swiftforms_view_entries is mapped to the custom capability "sf_support_desk"; the role "sf_support" has it.
 * - swiftforms_edit_entries is mapped to the same capability (a support agent may mark entries read or starred); delete, settings and the rest stay with administrators.
 * - Administrators keep access to entries: a user_has_cap filter gives "sf_support_desk" to anyone who can manage options.
 * - Three entries SwiftForms must ignore: a SwiftForms capability as the target, a value that is not a capability name, and a key SwiftForms does not define.
 *
 * The roles in the database are not touched (C-8.4).
 *
 * @package SwiftForms
 */

add_filter(
	'swiftforms_capability_map',
	static function ( $map ) {
		$map['swiftforms_view_entries']    = 'sf_support_desk';
		$map['swiftforms_edit_entries']    = 'sf_support_desk';
		$map['swiftforms_manage_settings'] = 'swiftforms_view_entries'; // A SwiftForms capability as the target: ignored.
		$map['swiftforms_export_entries']  = 'not a capability!'; // Not a capability name: ignored.
		$map['swiftforms_unknown']         = 'manage_options'; // Not a SwiftForms capability: ignored.
		return $map;
	}
);

add_filter(
	'user_has_cap',
	static function ( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['sf_support_desk'] = true;
		}
		return $allcaps;
	}
);
