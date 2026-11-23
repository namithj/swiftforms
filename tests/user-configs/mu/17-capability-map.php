<?php
/**
 * Config 17 snippet: a support role sees entries without being an administrator.
 *
 * - blockforms_view_entries is mapped to the custom capability "bf_support_desk"; the role "bf_support" has it.
 * - blockforms_edit_entries is mapped to the same capability (a support agent may mark entries read or starred); delete, settings and the rest stay with administrators.
 * - Administrators keep access to entries: a user_has_cap filter gives "bf_support_desk" to anyone who can manage options.
 * - Three entries BlockForms must ignore: a BlockForms capability as the target, a value that is not a capability name, and a key BlockForms does not define.
 *
 * The roles in the database are not touched (C-8.4).
 *
 * @package BlockForms
 */

add_filter(
	'blockforms_capability_map',
	static function ( $map ) {
		$map['blockforms_view_entries']    = 'bf_support_desk';
		$map['blockforms_edit_entries']    = 'bf_support_desk';
		$map['blockforms_manage_settings'] = 'blockforms_view_entries'; // A BlockForms capability as the target: ignored.
		$map['blockforms_export_entries']  = 'not a capability!'; // Not a capability name: ignored.
		$map['blockforms_unknown']         = 'manage_options'; // Not a BlockForms capability: ignored.
		return $map;
	}
);

add_filter(
	'user_has_cap',
	static function ( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps['bf_support_desk'] = true;
		}
		return $allcaps;
	}
);
