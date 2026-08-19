<?php
/**
 * The global "SwiftForms → Settings" admin page, built on Cassette-CMF.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Settings;

use Pedalcms\CassetteCmf\Core\Manager;
use SwiftForms\Notifications\Mailer;
use SwiftForms\PostTypes;
use SwiftForms\Registrable;

/**
 * Registers the global Settings page with Cassette-CMF: one metabox
 * (required so the nested tabs container is valid) holding a tabbed field
 * list, filterable via `swf_settings_schema` — see
 * Design\DesignSystem::inject_design_tab() for the one built-in consumer,
 * which fills in the otherwise-empty "Design" tab.
 *
 * Cassette-CMF renders the page, handles the save POST, sanitizes, and
 * stores each field as its own `swf_settings_{field}` option; none of that
 * is reimplemented here. GlobalSettings::get() reads those options back
 * for the rest of the plugin.
 */
final class GlobalSettingsPage implements Registrable {

	private const TEST_EMAIL_ACTION = 'swf_send_test_email';

	public function __construct( private Mailer $mailer ) {
	}

	public function register(): void {
		// Deferred to `init` (default priority, after Plugin::load_textdomain()
		// at priority 1): building the field config below calls `__()` a lot,
		// and this register() method itself runs on `plugins_loaded` — calling
		// `__()` for the 'swiftforms' domain that early triggers WordPress
		// 6.7+'s "translation loaded too early" _doing_it_wrong() notice.
		add_action( 'init', array( $this, 'register_cassette_page' ) );

		add_filter( 'cassette_cmf_before_save_field_smtpPassword', array( Schema::class, 'preserve_blank_secret' ) );
		add_filter( 'cassette_cmf_before_save_field_turnstileSecretKey', array( Schema::class, 'preserve_blank_secret' ) );
		add_filter( 'cassette_cmf_before_save_field_defaultAdminRecipients', array( Schema::class, 'sanitize_email_list' ) );

		add_action( 'admin_init', array( $this, 'seed_defaults' ), 5 );
		add_action( 'admin_post_' . self::TEST_EMAIL_ACTION, array( $this, 'handle_test_email' ) );
	}

	/**
	 * Registers the settings page with Cassette-CMF. Still runs well before
	 * `admin_menu`/`admin_init` (which is all Cassette-CMF itself needs).
	 */
	public function register_cassette_page(): void {
		Manager::init()->register_from_array(
			array(
				'settings_pages' => array(
					array(
						'id'          => GlobalSettings::PAGE_ID,
						'page_title'  => __( 'SwiftForms Settings', 'swiftforms' ),
						'menu_title'  => __( 'Settings', 'swiftforms' ),
						'capability'  => 'manage_options',
						'menu_slug'   => 'swf-settings',
						'parent_slug' => 'edit.php?post_type=' . PostTypes::FORM_POST_TYPE,
						'fields'      => $this->page_fields(),
					),
				),
			)
		);
	}

	/**
	 * Seeds every field's option with its schema default the first time
	 * this runs, so the settings form (and any GlobalSettings::get() caller
	 * that fires before an admin ever visits the page) sees sensible values
	 * — Cassette-CMF only writes an option once the form is actually saved.
	 * `add_option()` is a no-op once the option exists, so this is safe to
	 * run on every request.
	 */
	public function seed_defaults(): void {
		foreach ( $this->flat_defaults() as $name => $default ) {
			add_option( GlobalSettings::PAGE_ID . '_' . $name, $default );
		}
	}

	/**
	 * admin-post handler for the "send test email" action (see
	 * test_email_field()). Redirects back to the Settings page with a
	 * result flag for test_email_notice() to display — no JS/AJAX involved.
	 */
	public function handle_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'swiftforms' ) );
		}

		check_admin_referer( self::TEST_EMAIL_ACTION, 'swf_test_email_nonce' );

		$to     = isset( $_POST['swf_test_email_to'] ) ? sanitize_email( wp_unslash( $_POST['swf_test_email_to'] ) ) : '';
		$result = $this->mailer->send_test( $to );

		$args = array(
			'post_type'      => PostTypes::FORM_POST_TYPE,
			'page'           => 'swf-settings',
			'swf_test_email' => is_wp_error( $result ) ? 'error' : 'success',
			'swf_test_nonce' => wp_create_nonce( self::TEST_EMAIL_ACTION ),
		);

		if ( is_wp_error( $result ) ) {
			$args['swf_test_email_message'] = rawurlencode( $result->get_error_message() );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * The Cassette-CMF field tree for the settings page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page_fields(): array {
		return Schema::metabox(
			'swf_settings',
			'swf-settings',
			__( 'SwiftForms Settings', 'swiftforms' ),
			$this->tabs_config()
		);
	}

	/**
	 * Tab id => { label, fields[] }, each field a Cassette-CMF field
	 * config (name, type, label, default, …). Filterable via
	 * `swf_settings_schema` so addons — and Design\DesignSystem for the
	 * "design" tab — can contribute fields without touching this class.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function tabs_config(): array {
		$tabs = array(
			'general'  => array(
				'label'  => __( 'General', 'swiftforms' ),
				'fields' => array(
					array(
						'name'        => 'saveEntriesDefault',
						'type'        => 'checkbox',
						'label'       => __( 'Save entries by default', 'swiftforms' ),
						'default'     => '1',
						'description' => __( 'Individual forms can override this.', 'swiftforms' ),
					),
				),
			),
			'email'    => array(
				'label'  => __( 'Email', 'swiftforms' ),
				'fields' => array(
					array(
						'name'        => 'defaultAdminRecipients',
						'type'        => 'text',
						'label'       => __( 'Default admin recipient(s)', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'Comma separated. Used when a form has no admin recipients of its own. Falls back to the site admin email.', 'swiftforms' ),
					),
					$this->test_email_field(),
					Schema::heading( 'swf_smtp_heading', __( 'SMTP', 'swiftforms' ) ),
					array(
						'name'    => 'smtpEnabled',
						'type'    => 'checkbox',
						'label'   => __( 'Send mail via SMTP', 'swiftforms' ),
						'default' => '0',
					),
					array(
						'name'    => 'smtpHost',
						'type'    => 'text',
						'label'   => __( 'Host', 'swiftforms' ),
						'default' => '',
					),
					array(
						'name'    => 'smtpPort',
						'type'    => 'number',
						'label'   => __( 'Port', 'swiftforms' ),
						'default' => 587,
						'min'     => 1,
						'max'     => 65535,
					),
					array(
						'name'    => 'smtpEncryption',
						'type'    => 'select',
						'label'   => __( 'Encryption', 'swiftforms' ),
						'default' => 'tls',
						'options' => array(
							'none' => __( 'None', 'swiftforms' ),
							'ssl'  => 'SSL',
							'tls'  => 'TLS',
						),
					),
					array(
						'name'    => 'smtpUsername',
						'type'    => 'text',
						'label'   => __( 'Username', 'swiftforms' ),
						'default' => '',
					),
					array(
						'name'        => 'smtpPassword',
						'type'        => 'password',
						'label'       => __( 'Password', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'Leave blank to keep the currently saved password.', 'swiftforms' ),
					),
					array(
						'name'    => 'smtpFromEmail',
						'type'    => 'email',
						'label'   => __( 'From email', 'swiftforms' ),
						'default' => '',
					),
					array(
						'name'    => 'smtpFromName',
						'type'    => 'text',
						'label'   => __( 'From name', 'swiftforms' ),
						'default' => '',
					),
				),
			),
			'spam'     => array(
				'label'  => __( 'Spam Protection', 'swiftforms' ),
				'fields' => array(
					array(
						'name'    => 'rateLimitMaxRequests',
						'type'    => 'number',
						'label'   => __( 'Max submissions per window', 'swiftforms' ),
						'default' => 5,
						'min'     => 1,
					),
					array(
						'name'    => 'rateLimitWindowSeconds',
						'type'    => 'number',
						'label'   => __( 'Window (seconds)', 'swiftforms' ),
						'default' => 60,
						'min'     => 1,
					),
					array(
						'name'    => 'minSubmitSeconds',
						'type'    => 'number',
						'label'   => __( 'Minimum time to submit (seconds)', 'swiftforms' ),
						'default' => 3,
						'min'     => 0,
					),
					array(
						'name'        => 'akismetEnabled',
						'type'        => 'checkbox',
						'label'       => __( 'Use Akismet', 'swiftforms' ),
						'default'     => '0',
						'description' => __( 'Requires the Akismet plugin to be active and configured.', 'swiftforms' ),
					),
					Schema::heading( 'swf_turnstile_heading', __( 'Cloudflare Turnstile', 'swiftforms' ) ),
					array(
						'name'        => 'turnstileSiteKey',
						'type'        => 'text',
						'label'       => __( 'Site key', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'Enabling Turnstile loads Cloudflare resources on pages using it; include this third-party service in your privacy notice.', 'swiftforms' ),
					),
					array(
						'name'        => 'turnstileSecretKey',
						'type'        => 'password',
						'label'       => __( 'Secret key', 'swiftforms' ),
						'default'     => '',
						'description' => __( 'Leave blank to keep the currently saved secret key.', 'swiftforms' ),
					),
				),
			),
			'design'   => array(
				'label'  => __( 'Design', 'swiftforms' ),
				'fields' => array(),
			),
			'advanced' => array(
				'label'  => __( 'Advanced', 'swiftforms' ),
				'fields' => array(
					array(
						'name'        => 'uninstallDeleteData',
						'type'        => 'checkbox',
						'label'       => __( 'Delete all data on uninstall', 'swiftforms' ),
						'default'     => '0',
						'description' => __( 'Permanently deletes all forms, entries, and uploaded files when the plugin is deleted.', 'swiftforms' ),
					),
				),
			),
		);

		/**
		 * Filters the global settings tabs before they're handed to
		 * Cassette-CMF. Tab id => { label, fields: [ field config, … ] },
		 * where each field config is a Cassette-CMF field array (name,
		 * type, label, default, …). See Design\DesignSystem::inject_design_tab()
		 * for the one built-in consumer.
		 *
		 * @param array<string, array<string, mixed>> $tabs Tab definitions.
		 */
		return (array) apply_filters( 'swf_settings_schema', $tabs );
	}

	/**
	 * A "send test email" action, rendered as raw HTML in the Email tab.
	 * The button submits (via `formaction`) to a dedicated admin-post
	 * handler instead of the page's own save action, so it works without
	 * any JavaScript.
	 *
	 * @return array<string, mixed>
	 */
	private function test_email_field(): array {
		$markup  = '<div class="swf-test-email">';
		$markup .= '<label for="swf-test-email-to">' . esc_html__( 'Send a test email to', 'swiftforms' ) . '</label> ';
		$markup .= '<input type="email" id="swf-test-email-to" name="swf_test_email_to" class="regular-text" placeholder="you@example.com" />';
		$markup .= ' <button type="submit" class="button" formnovalidate';
		$markup .= ' formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" formmethod="post"';
		$markup .= ' name="action" value="' . esc_attr( self::TEST_EMAIL_ACTION ) . '">';
		$markup .= esc_html__( 'Send test email', 'swiftforms' );
		$markup .= '</button>';
		$markup .= wp_nonce_field( self::TEST_EMAIL_ACTION, 'swf_test_email_nonce', true, false );
		$markup .= $this->test_email_notice();
		$markup .= '</div>';

		return array(
			'name'     => 'swf_test_email_action',
			'type'     => 'custom_html',
			'label'    => '',
			'content'  => $markup,
			'raw_html' => true,
		);
	}

	/**
	 * Renders a notice after a test-email attempt, based on the redirect
	 * query args set by handle_test_email(). Nonce-checked so a crafted
	 * link can't be used to plant an arbitrary "message" on the page.
	 */
	private function test_email_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display check; the nonce itself is verified below.
		if ( empty( $_GET['swf_test_email'] ) || empty( $_GET['swf_test_nonce'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.
		$nonce = sanitize_text_field( wp_unslash( (string) $_GET['swf_test_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, self::TEST_EMAIL_ACTION ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		if ( 'success' === $_GET['swf_test_email'] ) {
			return '<p class="notice notice-success">' . esc_html__( 'Test email sent.', 'swiftforms' ) . '</p>';
		}

		$message = isset( $_GET['swf_test_email_message'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			? sanitize_text_field( wp_unslash( (string) $_GET['swf_test_email_message'] ) )
			: __( 'Could not send the test email.', 'swiftforms' );

		return '<p class="notice notice-error">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Flat field-name => default map across every tab.
	 *
	 * @return array<string, mixed>
	 */
	private function flat_defaults(): array {
		return array_map(
			static fn ( array $field ) => $field['default'] ?? '',
			Schema::flatten( $this->tabs_config() )
		);
	}
}
