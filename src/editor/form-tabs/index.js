/**
 * Quick links to a form's Settings (its meta box, right on this same
 * screen — the anchor just scrolls to it) and Entries (its own screen,
 * since it's a full filterable table), shown in the document sidebar's
 * Summary panel via the officially supported `PluginPostStatusInfo`
 * SlotFill — no fragile header-DOM injection needed. (Admin\EditorIntegration
 * also adds row-action and admin-bar links as further fallbacks reachable
 * without opening the editor at all; its Settings link uses the same
 * `#smartlogix-swiftforms-form-settings` anchor — see Settings\FormSettingsMetabox::METABOX_ID.)
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginPostStatusInfo } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';

const config = window.smartlogixSwiftFormsEditorSettings || {};

function FormTabsLinks() {
	if ( ! config.formId ) {
		return null;
	}

	return (
		<PluginPostStatusInfo>
			<div className="swf-form-tabs-links">
				<a href="#smartlogix-swiftforms-form-settings">
					{ __( 'Form Settings', 'swiftforms' ) }
				</a>
				{ ' · ' }
				<a
					href={ `${ config.adminUrl }edit.php?post_type=smartlogix_swf_entry&smartlogix_swf_entry_form=smartlogix-swiftforms-form-${ config.formId }` }
				>
					{ __( 'Entries', 'swiftforms' ) }
				</a>
			</div>
		</PluginPostStatusInfo>
	);
}

registerPlugin( 'smartlogix-swiftforms-form-tabs', { render: FormTabsLinks } );
