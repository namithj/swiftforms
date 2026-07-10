import { useEntityProp } from '@wordpress/core-data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import {
    SelectControl,
    TextareaControl,
    TextControl,
    ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';
import './settings-panel.css';

const DEFAULT_SETTINGS = {
    adminRecipients: '',
    adminSubject: 'SwiftForms submission #{submission_id}',
    adminTemplate: '',
    autoresponderField: '',
    autoresponderSubject: 'We received your submission',
    autoresponderTemplate: '',
    enableCaptcha: false,
    enableTurnstile: false,
    redirectUrl: '',
    retentionDays: 0,
    saveEntries: 'default',
    submitLabel: 'Send message',
    successMessage: 'Form submitted successfully.',
    webhookUrl: '',
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
                title={ __( 'Form Experience', 'swiftforms' ) }
            >
                <div className="swiftforms-settings-panel">
                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-form-experience-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-form-experience-heading" className="swiftforms-settings-panel__heading">{ __( 'Submission', 'swiftforms' ) }</h3>
                            <p className="swiftforms-settings-panel__description">{ __( 'Configure the button text and confirmation message shown after a successful submission.', 'swiftforms' ) }</p>
                        </div>
                        <TextControl
                            label={ __( 'Submit button label', 'swiftforms' ) }
                            help={ __( 'Appears on the frontend submit button.', 'swiftforms' ) }
                            value={ settings.submitLabel }
                            onChange={ ( value ) => updateSetting( 'submitLabel', value ) }
                        />
                        <TextareaControl
                            label={ __( 'Success message', 'swiftforms' ) }
                            help={ __( 'Shown after a successful submission.', 'swiftforms' ) }
                            rows={ 3 }
                            value={ settings.successMessage }
                            onChange={ ( value ) => updateSetting( 'successMessage', value ) }
                        />
                        <TextControl
                            label={ __( 'Redirect URL', 'swiftforms' ) }
                            help={ __( 'Optional. Send visitors to this URL after a successful submission instead of showing the success message.', 'swiftforms' ) }
                            type="url"
                            value={ settings.redirectUrl }
                            onChange={ ( value ) => updateSetting( 'redirectUrl', value ) }
                        />
                    </section>

                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-spam-protection-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-spam-protection-heading" className="swiftforms-settings-panel__heading">{ __( 'Spam protection', 'swiftforms' ) }</h3>
                            <p className="swiftforms-settings-panel__description">{ __( 'Enable lightweight submission checks without adding unnecessary UI noise to the form builder.', 'swiftforms' ) }</p>
                        </div>
                        <ToggleControl
                            label={ __( 'Enable math captcha', 'swiftforms' ) }
                            checked={ !! settings.enableCaptcha }
                            onChange={ ( value ) => updateSetting( 'enableCaptcha', value ) }
                            help={ __( 'Adds a lightweight captcha challenge to submissions.', 'swiftforms' ) }
                        />
                        <ToggleControl
                            label={ __( 'Enable Cloudflare Turnstile', 'swiftforms' ) }
                            checked={ !! settings.enableTurnstile }
                            onChange={ ( value ) => updateSetting( 'enableTurnstile', value ) }
                            help={ __( 'Requires Turnstile keys under Forms → Settings → Spam protection. Ignored while no site key is saved.', 'swiftforms' ) }
                        />
                    </section>

                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-data-retention-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-data-retention-heading" className="swiftforms-settings-panel__heading">{ __( 'Entries', 'swiftforms' ) }</h3>
                            <p className="swiftforms-settings-panel__description">{ __( 'Control whether submissions are stored as entries and how long they are kept.', 'swiftforms' ) }</p>
                        </div>
                        <SelectControl
                            label={ __( 'Save entries', 'swiftforms' ) }
                            help={ __( 'Notifications are sent either way. The site default lives under Forms → Settings.', 'swiftforms' ) }
                            value={ settings.saveEntries || 'default' }
                            options={ [
                                { label: __( 'Site default', 'swiftforms' ), value: 'default' },
                                { label: __( 'Always save', 'swiftforms' ), value: 'enabled' },
                                { label: __( 'Never save', 'swiftforms' ), value: 'disabled' },
                            ] }
                            onChange={ ( value ) => updateSetting( 'saveEntries', value ) }
                        />
                        <TextControl
                            label={ __( 'Delete submissions after (days)', 'swiftforms' ) }
                            help={ __( 'Set to 0 to keep submissions forever.', 'swiftforms' ) }
                            type="number"
                            min={ 0 }
                            value={ String( settings.retentionDays ?? 0 ) }
                            onChange={ ( value ) => updateSetting( 'retentionDays', Math.max( 0, parseInt( value, 10 ) || 0 ) ) }
                        />
                    </section>
                </div>
            </PluginDocumentSettingPanel>

            <PluginDocumentSettingPanel
                name="swiftforms-notification-settings"
                title={ __( 'Notifications', 'swiftforms' ) }
            >
                <div className="swiftforms-settings-panel">
                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-admin-notification-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-admin-notification-heading" className="swiftforms-settings-panel__heading">{ __( 'Admin notification', 'swiftforms' ) }</h3>
                            <p className="swiftforms-settings-panel__description">{ __( 'These emails are sent to your team when a visitor submits the form.', 'swiftforms' ) }</p>
                        </div>
                        <TextareaControl
                            label={ __( 'Admin recipients', 'swiftforms' ) }
                            help={ __( 'Use commas or new lines for multiple email addresses.', 'swiftforms' ) }
                            rows={ 3 }
                            value={ settings.adminRecipients }
                            onChange={ ( value ) => updateSetting( 'adminRecipients', value ) }
                        />
                        <TextControl
                            label={ __( 'Admin subject', 'swiftforms' ) }
                            help={ __( 'Placeholders: {submission_id}, {form_id}, {fields}, {field:slug}.', 'swiftforms' ) }
                            value={ settings.adminSubject }
                            onChange={ ( value ) => updateSetting( 'adminSubject', value ) }
                        />
                        <TextareaControl
                            label={ __( 'Admin template', 'swiftforms' ) }
                            help={ __( 'Optional. Leave empty to use the default generated message.', 'swiftforms' ) }
                            rows={ 4 }
                            value={ settings.adminTemplate }
                            onChange={ ( value ) => updateSetting( 'adminTemplate', value ) }
                        />
                    </section>

                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-autoresponder-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-autoresponder-heading" className="swiftforms-settings-panel__heading">{ __( 'Autoresponder', 'swiftforms' ) }</h3>
                            <p className="swiftforms-settings-panel__description">{ __( 'Optional email sent back to the submitting visitor.', 'swiftforms' ) }</p>
                        </div>
                        <TextControl
                            label={ __( 'Recipient field slug', 'swiftforms' ) }
                            help={ __( 'Optional. Slug of the field holding the visitor’s email. Defaults to the first email field.', 'swiftforms' ) }
                            value={ settings.autoresponderField }
                            onChange={ ( value ) => updateSetting( 'autoresponderField', value.replace( /[^a-z0-9_-]/gi, '' ).toLowerCase() ) }
                        />
                        <TextControl
                            label={ __( 'Autoresponder subject', 'swiftforms' ) }
                            value={ settings.autoresponderSubject }
                            onChange={ ( value ) => updateSetting( 'autoresponderSubject', value ) }
                        />
                        <TextareaControl
                            label={ __( 'Autoresponder template', 'swiftforms' ) }
                            help={ __( 'Optional. Leave empty to use the default generated message.', 'swiftforms' ) }
                            rows={ 4 }
                            value={ settings.autoresponderTemplate }
                            onChange={ ( value ) => updateSetting( 'autoresponderTemplate', value ) }
                        />
                    </section>

                    <section className="swiftforms-settings-panel__section" aria-labelledby="swiftforms-webhook-heading">
                        <div className="swiftforms-settings-panel__header">
                            <h3 id="swiftforms-webhook-heading" className="swiftforms-settings-panel__heading">{ __( 'Webhook', 'swiftforms' ) }</h3>
                            <p className="swiftforms-settings-panel__description">{ __( 'POST each submission as JSON to an external service (Zapier, Make, n8n, or your own endpoint).', 'swiftforms' ) }</p>
                        </div>
                        <TextControl
                            label={ __( 'Webhook URL', 'swiftforms' ) }
                            help={ __( 'Optional. Leave empty to disable.', 'swiftforms' ) }
                            type="url"
                            value={ settings.webhookUrl }
                            onChange={ ( value ) => updateSetting( 'webhookUrl', value ) }
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
