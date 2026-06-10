import { useEntityProp } from '@wordpress/core-data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import {
    TextareaControl,
    TextControl,
    ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { registerPlugin } from '@wordpress/plugins';
import './settings-panel.css';

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
                title="Form Experience"
            >
                <div className="swiftforms-settings-panel">
                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-form-experience-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-form-experience-heading" className="swiftforms-settings-panel__heading">Submission</h3>
                            <p className="swiftforms-settings-panel__description">Configure the button text and confirmation message shown after a successful submission.</p>
                        </div>
                        <TextControl
                            label="Submit button label"
                            help="Appears on the frontend submit button."
                            value={ settings.submitLabel }
                            onChange={ ( value ) => updateSetting( 'submitLabel', value ) }
                        />
                        <TextareaControl
                            label="Success message"
                            help="Shown after a successful submission."
                            rows={ 3 }
                            value={ settings.successMessage }
                            onChange={ ( value ) => updateSetting( 'successMessage', value ) }
                        />
                    </section>

                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-spam-protection-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-spam-protection-heading" className="swiftforms-settings-panel__heading">Spam protection</h3>
                            <p className="swiftforms-settings-panel__description">Enable lightweight submission checks without adding unnecessary UI noise to the form builder.</p>
                        </div>
                        <ToggleControl
                            label="Enable math captcha"
                            checked={ !! settings.enableCaptcha }
                            onChange={ ( value ) => updateSetting( 'enableCaptcha', value ) }
                            help="Adds a lightweight captcha challenge to submissions."
                        />
                    </section>
                </div>
            </PluginDocumentSettingPanel>

            <PluginDocumentSettingPanel
                name="swiftforms-notification-settings"
                title="Notifications"
            >
                <div className="swiftforms-settings-panel">
                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-admin-notification-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-admin-notification-heading" className="swiftforms-settings-panel__heading">Admin notification</h3>
                            <p className="swiftforms-settings-panel__description">These emails are sent to your team when a visitor submits the form.</p>
                        </div>
                        <TextareaControl
                            label="Admin recipients"
                            help="Use commas or new lines for multiple email addresses."
                            rows={ 3 }
                            value={ settings.adminRecipients }
                            onChange={ ( value ) => updateSetting( 'adminRecipients', value ) }
                        />
                        <TextControl
                            label="Admin subject"
                            help="Placeholders: {submission_id}, {form_id}, {fields}, {field:slug}."
                            value={ settings.adminSubject }
                            onChange={ ( value ) => updateSetting( 'adminSubject', value ) }
                        />
                        <TextareaControl
                            label="Admin template"
                            help="Optional. Leave empty to use the default generated message."
                            rows={ 4 }
                            value={ settings.adminTemplate }
                            onChange={ ( value ) => updateSetting( 'adminTemplate', value ) }
                        />
                    </section>

                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-autoresponder-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-autoresponder-heading" className="swiftforms-settings-panel__heading">Autoresponder</h3>
                            <p className="swiftforms-settings-panel__description">Optional email sent back to the submitting visitor.</p>
                        </div>
                        <TextControl
                            label="Autoresponder subject"
                            value={ settings.autoresponderSubject }
                            onChange={ ( value ) => updateSetting( 'autoresponderSubject', value ) }
                        />
                        <TextareaControl
                            label="Autoresponder template"
                            help="Optional. Leave empty to use the default generated message."
                            rows={ 4 }
                            value={ settings.autoresponderTemplate }
                            onChange={ ( value ) => updateSetting( 'autoresponderTemplate', value ) }
                        />
                    </section>
                </div>
            </PluginDocumentSettingPanel>
        </>
    );
};

registerPlugin( 'swiftforms-form-settings-panel', {
    render: FormSettingsPanel,
} );
