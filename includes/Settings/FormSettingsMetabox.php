<?php
/**
 * The per-form Settings meta box, built on Cassette-CMF.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Settings;

use Pedalcms\CassetteCmf\Core\Manager;
use SwiftForms\PostTypes;
use SwiftForms\Registrable;

/**
 * Registers a "Form Settings" meta box on the `smartlogix_swf_form` post-edit screen
 * with Cassette-CMF — mirrors GlobalSettingsPage, but for a CPT meta box
 * instead of a settings page: one metabox (required so the nested tabs
 * container is valid) holding a tabbed field list, filterable via
 * `smartlogix_swiftforms_form_settings_schema` so addons can contribute fields.
 *
 * Cassette-CMF renders the box, verifies the nonce, checks `edit_post`, and
 * saves each field as its own `_smartlogix_swiftforms_setting_{key}` post meta on `save_post`
 * — none of that is reimplemented here. FormSettings::get() reads those
 * meta keys back for the rest of the plugin. The otherwise-empty "design"
 * tab is filled in by Design\DesignSystem::inject_form_design_tab(), same
 * pattern as the global Settings page's own "design" tab.
 */
final class FormSettingsMetabox implements Registrable {
	private bool $retention_blocked = false;

	public const METABOX_ID = 'smartlogix-swiftforms-form-settings';

	private const META_PREFIX = '_smartlogix_swiftforms_setting_';

	private const DESIGN_META_PREFIX = '_smartlogix_swiftforms_design_';

	public function register(): void {
		// Deferred to `init` (default priority, after Plugin::load_textdomain()
		// at priority 1), same reasoning as GlobalSettingsPage: building the
		// field config below calls `__()` a lot, and this would otherwise run
		// during `plugins_loaded`, tripping WordPress 6.7+'s "translation
		// loaded too early" notice.
		add_action( 'init', array( $this, 'register_cassette_fields' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'require_retention_decision' ), 10, 4 );
		add_filter( 'redirect_post_location', array( $this, 'retention_redirect_notice' ) );
		add_action( 'admin_notices', array( $this, 'retention_notice' ) );
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $postarr */
	public function require_retention_decision( array $data, array $postarr ): array {
		if ( PostTypes::FORM_POST_TYPE !== ( $data['post_type'] ?? '' ) || 'publish' !== ( $data['post_status'] ?? '' ) ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core and Cassette-CMF verify the post save; this filter only prevents publishing.
		$confirmed = ! empty( $_POST[ self::meta_key( 'retentionConfirmed' ) ] ) || (bool) get_post_meta( $post_id, self::meta_key( 'retentionConfirmed' ), true );
		if ( ! $confirmed ) {
			$data['post_status']     = 'draft';
			$this->retention_blocked = true;
		}

		return $data;
	}

	public function retention_redirect_notice( string $location ): string {
		return $this->retention_blocked ? add_query_arg( 'smartlogix_swiftforms_retention_required', '1', $location ) : $location;
	}

	public function retention_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation-only notice flag.
		if ( empty( $_GET['smartlogix_swiftforms_retention_required'] ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The form remains a draft. Review the entry retention period and confirm that decision before publishing.', 'swiftforms' ) . '</p></div>';
	}

	/**
	 * Registers the field tree with Cassette-CMF and wires the two
	 * before-save filters that a plain field-type registration can't
	 * express on its own (see Schema::preserve_blank_secret()/sanitize_email_list()).
	 */
	public function register_cassette_fields(): void {
		Manager::init()->get_existing_cpt_handler()->add_fields(
			PostTypes::FORM_POST_TYPE,
			$this->page_fields()
		);

		foreach ( self::flat_fields() as $name => $field ) {
			if ( 'password' === ( $field['type'] ?? '' ) ) {
				add_filter( 'cassette_cmf_before_save_field_' . $name, array( Schema::class, 'preserve_blank_secret' ) );
			}
		}
		add_filter( 'cassette_cmf_before_save_field_' . self::meta_key( 'webhookSecret' ), array( $this, 'preserve_or_clear_webhook_secret' ) );

		add_filter( 'cassette_cmf_before_save_field_' . self::meta_key( 'adminRecipients' ), array( Schema::class, 'sanitize_email_list' ) );
	}

	public function preserve_or_clear_webhook_secret( $value ) {
		if ( defined( 'SMARTLOGIX_SWIFTFORMS_WEBHOOK_SECRET' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Cassette-CMF verifies the enclosing post save.
		return ! empty( $_POST[ self::meta_key( 'clearWebhookSecret' ) ] ) ? '' : Schema::preserve_blank_secret( $value );
	}

	/**
	 * The postmeta key a logical settings key (e.g. `submitLabel`) is stored
	 * under. Prefixed so it stays out of the "Custom Fields" metabox
	 * (`smartlogix_swf_form` supports it) and never collides with another plugin's meta.
	 */
	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	/**
	 * The postmeta key a logical design key (e.g. `accent`) is stored
	 * under — same idea as meta_key(), a different prefix so
	 * Design\CssVariables' fields don't collide with FormSettings' own.
	 */
	public static function design_meta_key( string $key ): string {
		return self::DESIGN_META_PREFIX . $key;
	}

	/**
	 * Flat key (unprefixed) => default value, for FormSettings::get()'s
	 * fallback when a field has never been saved. Scoped to `meta_key()`
	 * fields only — the "design" tab's fields (Design\CssVariables' own
	 * concern, a different prefix) are deliberately excluded.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = array();

		foreach ( self::flat_fields() as $name => $field ) {
			if ( ! str_starts_with( $name, self::META_PREFIX ) ) {
				continue;
			}

			$key              = substr( $name, strlen( self::META_PREFIX ) );
			$defaults[ $key ] = $field['default'] ?? '';
		}

		return $defaults;
	}

	/**
	 * The Cassette-CMF field type (`text`, `checkbox`, `number`, …) a
	 * logical settings key was declared with, for FormSettings::get()'s
	 * type coercion. Defaults to `text` for an unknown key.
	 */
	public static function field_type( string $key ): string {
		$field = self::flat_fields()[ self::meta_key( $key ) ] ?? array();

		return (string) ( $field['type'] ?? 'text' );
	}

	/**
	 * Every leaf (non-container) field config, keyed by its final postmeta
	 * name — the single place both registration and reading derive from,
	 * so they can never drift apart.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function flat_fields(): array {
		return Schema::flatten( self::tabs_config() );
	}

	/**
	 * The Cassette-CMF field tree for the meta box.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page_fields(): array {
		return Schema::metabox(
			'smartlogix_swf_form_settings',
			self::METABOX_ID,
			__( 'Form Settings', 'swiftforms' ),
			self::tabs_config(),
			array(
				'context'  => 'normal',
				'priority' => 'high',
			)
		);
	}

	/**
	 * Tab id => { label, fields[] }, each field a Cassette-CMF field config
	 * (name, type, label, default, …) with `name` namespaced through
	 * meta_key(). Filterable via `smartlogix_swiftforms_form_settings_schema` so addons can
	 * contribute fields — the filter only ever sees the plugin's own
	 * defaults; there's no per-form context here, since Cassette-CMF builds
	 * this field tree once on `init`, well before any specific form is being
	 * edited.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function tabs_config(): array {
		$tabs = array(
			'general'       => array(
				'label'  => __( 'General', 'swiftforms' ),
				'fields' => array(
					array(
						'name'    => self::meta_key( 'submitLabel' ),
						'type'    => 'text',
						'label'   => __( 'Submit button label', 'swiftforms' ),
						'default' => __( 'Send message', 'swiftforms' ),
					),
					array(
						'name'    => self::meta_key( 'successMessage' ),
						'type'    => 'textarea',
						'label'   => __( 'Success message', 'swiftforms' ),
						'default' => __( 'Form submitted successfully.', 'swiftforms' ),
					),
					array(
						'name'        => self::meta_key( 'redirectUrl' ),
						'type'        => 'text',
						'label'       => __( 'Redirect URL', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'Leave blank to show the success message in place instead.', 'swiftforms' ),
					),
				),
			),
			'design'        => array(
				'label'  => __( 'Design', 'swiftforms' ),
				'fields' => array(),
			),
			'notifications' => array(
				'label'  => __( 'Notifications', 'swiftforms' ),
				'fields' => array(
					Schema::heading( 'smartlogix_swiftforms_admin_notification_heading', __( 'Admin notification', 'swiftforms' ) ),
					array(
						'name'        => self::meta_key( 'adminRecipients' ),
						'type'        => 'text',
						'label'       => __( 'Recipients', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'Submission data is sent to these recipients through your email or SMTP provider. Leave blank to use the site default.', 'swiftforms' ),
					),
					array(
						'name'    => self::meta_key( 'adminSubject' ),
						'type'    => 'text',
						'label'   => __( 'Subject', 'swiftforms' ),
						'default' => __( 'New form submission: {form_title}', 'swiftforms' ),
					),
					array(
						'name'        => self::meta_key( 'adminTemplate' ),
						'type'        => 'textarea',
						'label'       => __( 'Message', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'Placeholders: {entry_id}, {form_id}, {fields}, {field:slug}.', 'swiftforms' ),
					),
					Schema::heading( 'smartlogix_swiftforms_autoresponder_heading', __( 'Autoresponder', 'swiftforms' ) ),
					array(
						'name'        => self::meta_key( 'autoresponderField' ),
						'type'        => 'text',
						'label'       => __( 'Recipient field', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'This sends submission data through your email or SMTP provider. Leave blank to use the first email field.', 'swiftforms' ),
					),
					array(
						'name'    => self::meta_key( 'autoresponderSubject' ),
						'type'    => 'text',
						'label'   => __( 'Subject', 'swiftforms' ),
						'default' => __( 'We received your submission', 'swiftforms' ),
					),
					array(
						'name'    => self::meta_key( 'autoresponderTemplate' ),
						'type'    => 'textarea',
						'label'   => __( 'Message', 'swiftforms' ),
						'default' => '',
					),
				),
			),
			'spam'          => array(
				'label'  => __( 'Spam Protection', 'swiftforms' ),
				'fields' => array(
					array(
						'name'    => self::meta_key( 'enableCaptcha' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable math CAPTCHA', 'swiftforms' ),
						'default' => '0',
					),
					array(
						'name'        => self::meta_key( 'enableTurnstile' ),
						'type'        => 'checkbox',
						'label'       => __( 'Enable Cloudflare Turnstile', 'swiftforms' ),
						'default'     => '0',
						'description' => __( 'Requires a site/secret key configured under global Settings → Spam Protection.', 'swiftforms' ),
					),
				),
			),
			'entries'       => array(
				'label'  => __( 'Entries', 'swiftforms' ),
				'fields' => array(
					array(
						'name'    => self::meta_key( 'saveEntries' ),
						'type'    => 'select',
						'label'   => __( 'Save entries', 'swiftforms' ),
						'default' => 'default',
						'options' => array(
							'default'  => __( 'Use site default', 'swiftforms' ),
							'enabled'  => __( 'Always save', 'swiftforms' ),
							'disabled' => __( 'Never save', 'swiftforms' ),
						),
					),
					array(
						'name'        => self::meta_key( 'retentionDays' ),
						'type'        => 'number',
						'label'       => __( 'Auto-delete entries after (days)', 'swiftforms' ),
						'default'     => 0,
						'min'         => 0,
						'description' => __( '0 = keep forever.', 'swiftforms' ),
					),
					array(
						'name'        => self::meta_key( 'retentionConfirmed' ),
						'type'        => 'checkbox',
						'label'       => __( 'I reviewed this retention period', 'swiftforms' ),
						'default'     => '0',
						'description' => __( 'Required before publishing. Choose a period appropriate for the form purpose and your legal obligations; 0 keeps entries indefinitely.', 'swiftforms' ),
					),
				),
			),
			'integrations'  => array(
				'label'  => __( 'Integrations', 'swiftforms' ),
				'fields' => array(
					array(
						'name'        => self::meta_key( 'webhookUrl' ),
						'type'        => 'text',
						'label'       => __( 'Webhook URL', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'Sends submitted fields to this third-party destination. Disclose its provider, purpose, location, and retention.', 'swiftforms' ),
					),
					array(
						'name'        => self::meta_key( 'webhookSecret' ),
						'type'        => 'password',
						'label'       => __( 'Webhook signing secret', 'swiftforms' ),
						'default'     => '',
						'disabled'    => defined( 'SMARTLOGIX_SWIFTFORMS_WEBHOOK_SECRET' ),
						'description' => __( 'Leave blank to preserve it. Enter a replacement to rotate it. Values are never displayed; wp-config.php overrides are read-only.', 'swiftforms' ),
					),
					array(
						'name'        => self::meta_key( 'clearWebhookSecret' ),
						'type'        => 'checkbox',
						'label'       => __( 'Clear the saved webhook secret', 'swiftforms' ),
						'default'     => '0',
						'disabled'    => defined( 'SMARTLOGIX_SWIFTFORMS_WEBHOOK_SECRET' ),
						'description' => __( 'Explicitly removes the database value on save. Configure a new secret before enabling signed delivery.', 'swiftforms' ),
					),
				),
			),
		);

		/**
		 * Filters the per-form settings tabs before they're handed to
		 * Cassette-CMF. Tab id => { label, fields: [ field config, … ] },
		 * where each field config is a Cassette-CMF field array (name,
		 * type, label, default, …) — run `name` through
		 * FormSettingsMetabox::meta_key() so it lands in the right postmeta
		 * key and FormSettings::get() can find it again.
		 *
		 * @param array<string, array<string, mixed>> $tabs Tab definitions.
		 */
		return (array) apply_filters( 'smartlogix_swiftforms_form_settings_schema', $tabs );
	}
}
