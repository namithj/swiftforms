<?php
/**
 * Global plugin settings: SMTP delivery, rate limiting, notification and entry defaults.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * Global plugin settings page and option handling.
 */
class SwiftForms_Settings {
	/**
	 * Option key holding the whole settings array.
	 */
	public const OPTION_KEY = 'swiftforms_settings';

	/**
	 * Settings page slug under the Forms menu.
	 */
	public const PAGE_SLUG = 'swiftforms-settings';

	/**
	 * Wires the settings page, mail delivery, and option-backed filters.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_test_email_notice' ) );
		add_action( 'admin_post_swiftforms_test_email', array( $this, 'handle_test_email' ) );
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );

		// Priority 5 so explicit add_filter() calls (default priority 10) can
		// still override the stored option — the code-level API keeps working.
		add_filter( 'swiftforms_rate_limit_max_requests', array( $this, 'filter_rate_limit_max_requests' ), 5 );
		add_filter( 'swiftforms_rate_limit_window_seconds', array( $this, 'filter_rate_limit_window_seconds' ), 5 );
		add_filter( 'swiftforms_min_submit_seconds', array( $this, 'filter_min_submit_seconds' ), 5 );
	}

	/**
	 * Returns the default global settings.
	 *
	 * @return array<string, string|bool|int>
	 */
	public static function get_default_settings(): array {
		return array(
			'akismetEnabled'         => false,
			'defaultAdminRecipients' => '',
			'minSubmitSeconds'       => 3,
			'rateLimitMaxRequests'   => 5,
			'rateLimitWindowSeconds' => 60,
			'saveEntriesDefault'     => true,
			'smtpEnabled'            => false,
			'smtpEncryption'         => 'tls',
			'smtpFromEmail'          => '',
			'smtpFromName'           => '',
			'smtpHost'               => '',
			'smtpPassword'           => '',
			'smtpPort'               => 587,
			'smtpUsername'           => '',
			'turnstileSecretKey'     => '',
			'turnstileSiteKey'       => '',
			'uninstallDeleteData'    => false,
		);
	}

	/**
	 * Returns the stored global settings merged with defaults.
	 *
	 * @return array<string, string|bool|int>
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_KEY );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return self::sanitize_settings( $saved );
	}

	/**
	 * Sanitizes the settings array before storage or runtime use.
	 *
	 * An empty submitted password keeps the currently stored one, so the saved
	 * secret never has to be echoed back into the form markup.
	 *
	 * @param mixed $settings Raw settings.
	 *
	 * @return array<string, string|bool|int>
	 */
	public static function sanitize_settings( $settings ): array {
		$defaults = self::get_default_settings();

		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		$encryption = isset( $settings['smtpEncryption'] ) ? (string) $settings['smtpEncryption'] : $defaults['smtpEncryption'];
		if ( ! in_array( $encryption, array( 'none', 'ssl', 'tls' ), true ) ) {
			$encryption = $defaults['smtpEncryption'];
		}

		$from_email = isset( $settings['smtpFromEmail'] ) ? trim( (string) $settings['smtpFromEmail'] ) : '';
		if ( '' !== $from_email && ! is_email( $from_email ) ) {
			$from_email = '';
		}

		$password = isset( $settings['smtpPassword'] ) ? (string) $settings['smtpPassword'] : '';
		if ( '' === $password ) {
			$stored   = get_option( self::OPTION_KEY );
			$password = is_array( $stored ) && isset( $stored['smtpPassword'] ) ? (string) $stored['smtpPassword'] : '';
		}

		// The Turnstile secret gets the same keep-when-blank treatment: it is
		// never echoed back into the form, so an empty submit means "keep".
		$turnstile_secret = isset( $settings['turnstileSecretKey'] ) ? trim( (string) $settings['turnstileSecretKey'] ) : '';
		if ( '' === $turnstile_secret ) {
			$stored_secret    = get_option( self::OPTION_KEY );
			$turnstile_secret = is_array( $stored_secret ) && isset( $stored_secret['turnstileSecretKey'] ) ? (string) $stored_secret['turnstileSecretKey'] : '';
		}

		return array(
			'akismetEnabled'         => ! empty( $settings['akismetEnabled'] ),
			'defaultAdminRecipients' => isset( $settings['defaultAdminRecipients'] ) ? sanitize_textarea_field( (string) $settings['defaultAdminRecipients'] ) : $defaults['defaultAdminRecipients'],
			'minSubmitSeconds'       => isset( $settings['minSubmitSeconds'] ) ? max( 0, (int) $settings['minSubmitSeconds'] ) : $defaults['minSubmitSeconds'],
			'rateLimitMaxRequests'   => isset( $settings['rateLimitMaxRequests'] ) ? max( 1, (int) $settings['rateLimitMaxRequests'] ) : $defaults['rateLimitMaxRequests'],
			'rateLimitWindowSeconds' => isset( $settings['rateLimitWindowSeconds'] ) ? max( 10, (int) $settings['rateLimitWindowSeconds'] ) : $defaults['rateLimitWindowSeconds'],
			'saveEntriesDefault'     => isset( $settings['saveEntriesDefault'] ) ? ! empty( $settings['saveEntriesDefault'] ) : $defaults['saveEntriesDefault'],
			'smtpEnabled'            => ! empty( $settings['smtpEnabled'] ),
			'smtpEncryption'         => $encryption,
			'smtpFromEmail'          => $from_email,
			'smtpFromName'           => isset( $settings['smtpFromName'] ) ? sanitize_text_field( (string) $settings['smtpFromName'] ) : $defaults['smtpFromName'],
			'smtpHost'               => isset( $settings['smtpHost'] ) ? sanitize_text_field( (string) $settings['smtpHost'] ) : $defaults['smtpHost'],
			'smtpPassword'           => $password,
			'smtpPort'               => isset( $settings['smtpPort'] ) ? min( 65535, max( 1, (int) $settings['smtpPort'] ) ) : $defaults['smtpPort'],
			'smtpUsername'           => isset( $settings['smtpUsername'] ) ? sanitize_text_field( (string) $settings['smtpUsername'] ) : $defaults['smtpUsername'],
			'turnstileSecretKey'     => $turnstile_secret,
			'turnstileSiteKey'       => isset( $settings['turnstileSiteKey'] ) ? sanitize_text_field( (string) $settings['turnstileSiteKey'] ) : $defaults['turnstileSiteKey'],
			'uninstallDeleteData'    => ! empty( $settings['uninstallDeleteData'] ),
		);
	}

	/**
	 * Returns the SMTP password, preferring the wp-config constant when defined.
	 */
	public static function get_smtp_password(): string {
		if ( defined( 'SWIFTFORMS_SMTP_PASSWORD' ) ) {
			return (string) SWIFTFORMS_SMTP_PASSWORD;
		}

		$settings = self::get_settings();

		return (string) $settings['smtpPassword'];
	}

	/**
	 * Resolves whether submissions for a form should be stored as entries.
	 *
	 * Per-form `saveEntries` ('enabled'/'disabled') wins; 'default' (or any
	 * unknown value) falls back to the global `saveEntriesDefault`.
	 *
	 * @param int $form_id Form post ID.
	 */
	public static function should_save_entries( int $form_id ): bool {
		if ( $form_id > 0 ) {
			$form_settings = SwiftForms_CPTs::get_form_settings( $form_id );
			$mode          = isset( $form_settings['saveEntries'] ) ? (string) $form_settings['saveEntries'] : 'default';

			if ( 'enabled' === $mode ) {
				return true;
			}

			if ( 'disabled' === $mode ) {
				return false;
			}
		}

		$settings = self::get_settings();

		return ! empty( $settings['saveEntriesDefault'] );
	}

	/**
	 * Applies the stored SMTP configuration to outgoing mail.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer being initialized.
	 */
	public function configure_phpmailer( $phpmailer ): void {
		$settings = self::get_settings();

		if ( empty( $settings['smtpEnabled'] ) || '' === (string) $settings['smtpHost'] ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = (string) $settings['smtpHost']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Port       = (int) $settings['smtpPort']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->SMTPSecure = 'none' === $settings['smtpEncryption'] ? '' : (string) $settings['smtpEncryption']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		$username = (string) $settings['smtpUsername'];
		if ( '' !== $username ) {
			$phpmailer->SMTPAuth = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Username = $username; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Password = self::get_smtp_password(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		$from_email = (string) $settings['smtpFromEmail'];
		if ( '' !== $from_email && is_email( $from_email ) ) {
			$from_name = (string) $settings['smtpFromName'];
			$phpmailer->setFrom( $from_email, '' !== $from_name ? $from_name : $phpmailer->FromName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
	}

	/**
	 * Feeds the stored rate limit maximum into the existing filter.
	 *
	 * @param mixed $max_requests Filtered default maximum (unused; the option wins).
	 */
	public function filter_rate_limit_max_requests( $max_requests ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter callback signature.
		$settings = self::get_settings();

		return (int) $settings['rateLimitMaxRequests'];
	}

	/**
	 * Feeds the stored rate limit window into the existing filter.
	 *
	 * @param mixed $window Filtered default window (unused; the option wins).
	 */
	public function filter_rate_limit_window_seconds( $window ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter callback signature.
		$settings = self::get_settings();

		return (int) $settings['rateLimitWindowSeconds'];
	}

	/**
	 * Feeds the stored minimum-submit-seconds value into the time-trap filter.
	 *
	 * @param mixed $seconds Filtered default (unused; the option wins).
	 */
	public function filter_min_submit_seconds( $seconds ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Filter callback signature.
		$settings = self::get_settings();

		return (int) $settings['minSubmitSeconds'];
	}

	/**
	 * Adds the Settings submenu under the Forms menu.
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . SwiftForms_CPTs::FORM_POST_TYPE,
			__( 'SwiftForms Settings', 'swiftforms' ),
			__( 'Settings', 'swiftforms' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Registers the option and its Settings API sections/fields.
	 */
	public function register_settings(): void {
		register_setting(
			'swiftforms_settings_group',
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'type'              => 'array',
			)
		);

		add_settings_section(
			'swiftforms_smtp',
			__( 'Email delivery (SMTP)', 'swiftforms' ),
			array( $this, 'render_smtp_section_intro' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'smtpEnabled', __( 'Send via SMTP', 'swiftforms' ), 'render_smtp_enabled_field', 'swiftforms_smtp' );
		$this->add_field( 'smtpHost', __( 'SMTP host', 'swiftforms' ), 'render_smtp_host_field', 'swiftforms_smtp' );
		$this->add_field( 'smtpPort', __( 'Port', 'swiftforms' ), 'render_smtp_port_field', 'swiftforms_smtp' );
		$this->add_field( 'smtpEncryption', __( 'Encryption', 'swiftforms' ), 'render_smtp_encryption_field', 'swiftforms_smtp' );
		$this->add_field( 'smtpUsername', __( 'Username', 'swiftforms' ), 'render_smtp_username_field', 'swiftforms_smtp' );
		$this->add_field( 'smtpPassword', __( 'Password', 'swiftforms' ), 'render_smtp_password_field', 'swiftforms_smtp' );
		$this->add_field( 'smtpFromEmail', __( 'From email', 'swiftforms' ), 'render_smtp_from_email_field', 'swiftforms_smtp' );
		$this->add_field( 'smtpFromName', __( 'From name', 'swiftforms' ), 'render_smtp_from_name_field', 'swiftforms_smtp' );

		add_settings_section(
			'swiftforms_notifications',
			__( 'Notifications', 'swiftforms' ),
			'__return_null',
			self::PAGE_SLUG
		);

		$this->add_field( 'defaultAdminRecipients', __( 'Default admin recipients', 'swiftforms' ), 'render_default_recipients_field', 'swiftforms_notifications' );

		add_settings_section(
			'swiftforms_submissions',
			__( 'Submissions', 'swiftforms' ),
			'__return_null',
			self::PAGE_SLUG
		);

		$this->add_field( 'saveEntriesDefault', __( 'Save entries', 'swiftforms' ), 'render_save_entries_field', 'swiftforms_submissions' );
		$this->add_field( 'rateLimitMaxRequests', __( 'Rate limit', 'swiftforms' ), 'render_rate_limit_field', 'swiftforms_submissions' );

		add_settings_section(
			'swiftforms_spam',
			__( 'Spam protection', 'swiftforms' ),
			array( $this, 'render_spam_section_intro' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'minSubmitSeconds', __( 'Minimum submit time', 'swiftforms' ), 'render_min_submit_seconds_field', 'swiftforms_spam' );
		$this->add_field( 'turnstileSiteKey', __( 'Turnstile site key', 'swiftforms' ), 'render_turnstile_site_key_field', 'swiftforms_spam' );
		$this->add_field( 'turnstileSecretKey', __( 'Turnstile secret key', 'swiftforms' ), 'render_turnstile_secret_key_field', 'swiftforms_spam' );
		$this->add_field( 'akismetEnabled', __( 'Akismet', 'swiftforms' ), 'render_akismet_enabled_field', 'swiftforms_spam' );

		add_settings_section(
			'swiftforms_advanced',
			__( 'Advanced', 'swiftforms' ),
			'__return_null',
			self::PAGE_SLUG
		);

		$this->add_field( 'uninstallDeleteData', __( 'Uninstall', 'swiftforms' ), 'render_uninstall_delete_data_field', 'swiftforms_advanced' );
	}

	/**
	 * Registers a single settings field with less boilerplate.
	 *
	 * @param string $id            Settings key.
	 * @param string $label         Field label.
	 * @param string $render_method Renderer method name on this class.
	 * @param string $section       Settings section ID.
	 */
	private function add_field( string $id, string $label, string $render_method, string $section ): void {
		add_settings_field( $id, $label, array( $this, $render_method ), self::PAGE_SLUG, $section );
	}

	/**
	 * Renders the settings page wrapper.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$test_email_url = wp_nonce_url(
			add_query_arg( 'action', 'swiftforms_test_email', admin_url( 'admin-post.php' ) ),
			'swiftforms_test_email'
		);

		// Custom (non options-*.php) settings pages don't get the automatic
		// "Settings saved." notice from options-head.php — replicate it.
		if ( ! empty( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error( 'swiftforms_settings_group', 'settings_updated', __( 'Settings saved.', 'swiftforms' ), 'success' );
		}
		settings_errors( 'swiftforms_settings_group' );

		echo '<div class="wrap"><h1>' . esc_html__( 'SwiftForms Settings', 'swiftforms' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( 'swiftforms_settings_group' );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form>';
		echo '<hr />';
		echo '<p><a class="button" href="' . esc_url( $test_email_url ) . '">' . esc_html__( 'Send test email', 'swiftforms' ) . '</a> ';
		echo '<span class="description">' . esc_html__( 'Sends a test message to your account email using the settings saved above.', 'swiftforms' ) . '</span></p>';
		echo '</div>';
	}

	/**
	 * Renders the SMTP section introduction.
	 */
	public function render_smtp_section_intro(): void {
		echo '<p>' . esc_html__( 'Route SwiftForms (and all other WordPress) email through an SMTP server instead of PHP mail. Save your changes before sending a test email.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Field renderers. Each prints one input bound to the option array.
	 */
	public function render_smtp_enabled_field(): void {
		$settings = self::get_settings();
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[smtpEnabled]" value="1" ' . checked( ! empty( $settings['smtpEnabled'] ), true, false ) . ' /> ';
		echo esc_html__( 'Use the SMTP server below for outgoing email', 'swiftforms' ) . '</label>';
	}

	/**
	 * Renders the SMTP host field.
	 */
	public function render_smtp_host_field(): void {
		$settings = self::get_settings();
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[smtpHost]" value="' . esc_attr( (string) $settings['smtpHost'] ) . '" placeholder="smtp.example.com" />';
	}

	/**
	 * Renders the SMTP port field.
	 */
	public function render_smtp_port_field(): void {
		$settings = self::get_settings();
		echo '<input type="number" class="small-text" min="1" max="65535" name="' . esc_attr( self::OPTION_KEY ) . '[smtpPort]" value="' . esc_attr( (string) $settings['smtpPort'] ) . '" />';
		echo ' <span class="description">' . esc_html__( 'Common ports: 587 (TLS), 465 (SSL), 25 (none).', 'swiftforms' ) . '</span>';
	}

	/**
	 * Renders the SMTP encryption selector.
	 */
	public function render_smtp_encryption_field(): void {
		$settings = self::get_settings();
		$options  = array(
			'tls'  => __( 'TLS (recommended)', 'swiftforms' ),
			'ssl'  => __( 'SSL', 'swiftforms' ),
			'none' => __( 'None', 'swiftforms' ),
		);

		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[smtpEncryption]">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) $settings['smtpEncryption'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Renders the SMTP username field.
	 */
	public function render_smtp_username_field(): void {
		$settings = self::get_settings();
		echo '<input type="text" class="regular-text" autocomplete="off" name="' . esc_attr( self::OPTION_KEY ) . '[smtpUsername]" value="' . esc_attr( (string) $settings['smtpUsername'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave empty if the server does not require authentication.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Renders the SMTP password field.
	 */
	public function render_smtp_password_field(): void {
		if ( defined( 'SWIFTFORMS_SMTP_PASSWORD' ) ) {
			echo '<input type="password" class="regular-text" disabled value="********" />';
			echo '<p class="description">' . esc_html__( 'Defined by the SWIFTFORMS_SMTP_PASSWORD constant in wp-config.php.', 'swiftforms' ) . '</p>';
			return;
		}

		$settings     = self::get_settings();
		$has_password = '' !== (string) $settings['smtpPassword'];

		// The stored secret is never echoed back into the markup.
		echo '<input type="password" class="regular-text" autocomplete="new-password" name="' . esc_attr( self::OPTION_KEY ) . '[smtpPassword]" value="" placeholder="' . esc_attr( $has_password ? '********' : '' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved password. For extra security define SWIFTFORMS_SMTP_PASSWORD in wp-config.php instead.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Renders the SMTP from-address field.
	 */
	public function render_smtp_from_email_field(): void {
		$settings = self::get_settings();
		echo '<input type="email" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[smtpFromEmail]" value="' . esc_attr( (string) $settings['smtpFromEmail'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Optional. Overrides the sender address on outgoing email.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Renders the SMTP from-name field.
	 */
	public function render_smtp_from_name_field(): void {
		$settings = self::get_settings();
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[smtpFromName]" value="' . esc_attr( (string) $settings['smtpFromName'] ) . '" />';
	}

	/**
	 * Renders the default admin recipients field.
	 */
	public function render_default_recipients_field(): void {
		$settings = self::get_settings();
		echo '<textarea class="regular-text" rows="3" name="' . esc_attr( self::OPTION_KEY ) . '[defaultAdminRecipients]">' . esc_textarea( (string) $settings['defaultAdminRecipients'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Used when a form has no admin recipients of its own. One address per line (or comma separated). Falls back to the site admin email when empty.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Renders the global save-entries toggle.
	 */
	public function render_save_entries_field(): void {
		$settings = self::get_settings();
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[saveEntriesDefault]" value="1" ' . checked( ! empty( $settings['saveEntriesDefault'] ), true, false ) . ' /> ';
		echo esc_html__( 'Store submissions as entries by default', 'swiftforms' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Each form can override this under its Form Experience settings. Notifications are sent either way.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Renders the rate limit fields.
	 */
	public function render_rate_limit_field(): void {
		$settings = self::get_settings();
		echo '<input type="number" class="small-text" min="1" name="' . esc_attr( self::OPTION_KEY ) . '[rateLimitMaxRequests]" value="' . esc_attr( (string) $settings['rateLimitMaxRequests'] ) . '" /> ';
		echo esc_html__( 'submissions per', 'swiftforms' ) . ' ';
		echo '<input type="number" class="small-text" min="10" name="' . esc_attr( self::OPTION_KEY ) . '[rateLimitWindowSeconds]" value="' . esc_attr( (string) $settings['rateLimitWindowSeconds'] ) . '" /> ';
		echo esc_html__( 'seconds, per visitor IP.', 'swiftforms' );
	}

	/**
	 * Renders the spam protection section introduction.
	 */
	public function render_spam_section_intro(): void {
		echo '<p>' . esc_html__( 'These protections layer on top of the built-in honeypot, math captcha, and rate limiting. No single layer has to catch everything.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Renders the minimum submit time field.
	 */
	public function render_min_submit_seconds_field(): void {
		$settings = self::get_settings();
		echo '<input type="number" class="small-text" min="0" name="' . esc_attr( self::OPTION_KEY ) . '[minSubmitSeconds]" value="' . esc_attr( (string) $settings['minSubmitSeconds'] ) . '" /> ';
		echo esc_html__( 'seconds. Submissions faster than this are silently discarded as bot traffic. 0 disables the check.', 'swiftforms' );
	}

	/**
	 * Renders the Turnstile site key field.
	 */
	public function render_turnstile_site_key_field(): void {
		$settings = self::get_settings();
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[turnstileSiteKey]" value="' . esc_attr( (string) $settings['turnstileSiteKey'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Free keys at dash.cloudflare.com. Forms opt in individually under their Form Experience settings.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Renders the Turnstile secret key field.
	 */
	public function render_turnstile_secret_key_field(): void {
		$settings   = self::get_settings();
		$has_secret = '' !== (string) $settings['turnstileSecretKey'];

		// The stored secret is never echoed back into the markup.
		echo '<input type="password" class="regular-text" autocomplete="new-password" name="' . esc_attr( self::OPTION_KEY ) . '[turnstileSecretKey]" value="" placeholder="' . esc_attr( $has_secret ? '********' : '' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved secret.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Renders the Akismet integration toggle.
	 */
	public function render_akismet_enabled_field(): void {
		$settings  = self::get_settings();
		$available = SwiftForms_Spam::is_akismet_active();

		echo '<label><input type="checkbox" ' . disabled( ! $available, true, false ) . ' name="' . esc_attr( self::OPTION_KEY ) . '[akismetEnabled]" value="1" ' . checked( ! empty( $settings['akismetEnabled'] ) && $available, true, false ) . ' /> ';
		echo esc_html__( 'Check submissions against Akismet and file matches as spam instead of rejecting them', 'swiftforms' ) . '</label>';

		if ( ! $available ) {
			echo '<p class="description">' . esc_html__( 'Requires the Akismet plugin to be installed, activated, and configured with an API key.', 'swiftforms' ) . '</p>';
		}
	}

	/**
	 * Renders the uninstall data-deletion opt-in.
	 */
	public function render_uninstall_delete_data_field(): void {
		$settings = self::get_settings();
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[uninstallDeleteData]" value="1" ' . checked( ! empty( $settings['uninstallDeleteData'] ), true, false ) . ' /> ';
		echo esc_html__( 'Delete all SwiftForms data when the plugin is uninstalled', 'swiftforms' ) . '</label>';
		echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'Permanently removes every form, all stored submissions, uploaded files, and plugin settings. This cannot be undone.', 'swiftforms' ) . '</p>';
	}

	/**
	 * Sends a test email to the current user and redirects back with a status flag.
	 */
	public function handle_test_email(): void {
		check_admin_referer( 'swiftforms_test_email' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to send a test email.', 'swiftforms' ) );
		}

		$user = wp_get_current_user();
		$sent = wp_mail(
			$user->user_email,
			__( 'SwiftForms test email', 'swiftforms' ),
			sprintf(
				/* translators: %s: site URL. */
				__( "This is a test email from SwiftForms on %s.\n\nIf you're reading this, your email delivery settings work.", 'swiftforms' ),
				home_url()
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                  => self::PAGE_SLUG,
					'post_type'             => SwiftForms_CPTs::FORM_POST_TYPE,
					'swiftforms_test_email' => $sent ? 'sent' : 'failed',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Surfaces the test email result after the redirect.
	 */
	public function render_test_email_notice(): void {
		if ( ! isset( $_GET['swiftforms_test_email'] ) || ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$status = sanitize_key( (string) $_GET['swiftforms_test_email'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'sent' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent. Check your inbox (and spam folder).', 'swiftforms' ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The test email could not be sent. Double-check the SMTP settings.', 'swiftforms' ) . '</p></div>';
	}
}
