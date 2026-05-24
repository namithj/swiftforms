import { useEntityProp } from '@wordpress/core-data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { TextareaControl, TextControl, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { registerPlugin } from '@wordpress/plugins';

const DEFAULT_SETTINGS = {
    adminRecipients: '',
    adminSubject: 'SwiftForms submission #{submission_id}',
    adminTemplate: '',
    autoresponderSubject: 'We received your submission',
    autoresponderTemplate: '',
    enableCaptcha: false,
    submitLabel: 'Send message',
    successMessage: 'Form submitted successfully.',
};

const FormSettingsPanel = () => {
    const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );

    if ( postType !== 'swiftforms_form' ) {
        return null;
    }

    const [ meta = {}, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
    const settings = {
        ...DEFAULT_SETTINGS,
        ...( meta._sf_settings || {} ),
    };

    const updateSetting = ( key, value ) => {
        setMeta( {
            ...meta,
            _sf_settings: {
                ...settings,
                [ key ]: value,
            },
        } );
    };

    return (
        <>
            <PluginDocumentSettingPanel
                name="swiftforms-confirmation-settings"
                title="Confirmation"
            >
                <TextControl
                    label="Submit label"
                    value={ settings.submitLabel }
                    onChange={ ( value ) => updateSetting( 'submitLabel', value ) }
                />
                <TextareaControl
                    label="Success message"
                    value={ settings.successMessage }
                    onChange={ ( value ) => updateSetting( 'successMessage', value ) }
                />
            </PluginDocumentSettingPanel>

            <PluginDocumentSettingPanel
                name="swiftforms-notification-settings"
                title="Notifications"
            >
                <TextareaControl
                    label="Admin recipients"
                    help="Separate multiple emails with commas or new lines."
                    value={ settings.adminRecipients }
                    onChange={ ( value ) => updateSetting( 'adminRecipients', value ) }
                />
                <TextControl
                    label="Admin subject"
                    value={ settings.adminSubject }
                    onChange={ ( value ) => updateSetting( 'adminSubject', value ) }
                />
                <TextareaControl
                    label="Admin template"
                    help="Supports {submission_id}, {form_id}, {fields}, and {field:slug}."
                    value={ settings.adminTemplate }
                    onChange={ ( value ) => updateSetting( 'adminTemplate', value ) }
                />
                <TextControl
                    label="Autoresponder subject"
                    value={ settings.autoresponderSubject }
                    onChange={ ( value ) => updateSetting( 'autoresponderSubject', value ) }
                />
                <TextareaControl
                    label="Autoresponder template"
                    value={ settings.autoresponderTemplate }
                    onChange={ ( value ) => updateSetting( 'autoresponderTemplate', value ) }
                />
            </PluginDocumentSettingPanel>

            <PluginDocumentSettingPanel
                name="swiftforms-protection-settings"
                title="Protection"
            >
                <ToggleControl
                    label="Enable math captcha"
                    checked={ !! settings.enableCaptcha }
                    onChange={ ( value ) => updateSetting( 'enableCaptcha', value ) }
                />
            </PluginDocumentSettingPanel>
        </>
    );
};

registerPlugin( 'swiftforms-form-settings-panel', {
    render: FormSettingsPanel,
} );
