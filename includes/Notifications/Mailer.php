<?php
/**
 * Mail delivery: optional SMTP configuration and a thin wp_mail() wrapper.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Notifications;

use SwiftForms\Registrable;
use SwiftForms\Settings\GlobalSettings;
use WP_Error;

/**
 * When SMTP is enabled in global settings, configures PHPMailer directly on
 * `phpmailer_init` (the same hook every SMTP plugin uses). The stored
 * password can be overridden by defining `SMARTLOGIX_SWIFTFORMS_SMTP_PASSWORD` in
 * `wp-config.php`, which always wins and is never echoed back to the UI.
 */
final class Mailer implements Registrable {

	public function register(): void {
		add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Passed by reference by WordPress.
	 */
	public function configure_smtp( $phpmailer ): void {
		$settings = GlobalSettings::instance();

		if ( ! $settings->get( 'smtpEnabled', false ) ) {
			return;
		}

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own public property names.
		$phpmailer->isSMTP();
		$phpmailer->Host = (string) $settings->get( 'smtpHost', '' );
		$phpmailer->Port = (int) $settings->get( 'smtpPort', 587 );

		$encryption            = (string) $settings->get( 'smtpEncryption', 'tls' );
		$phpmailer->SMTPSecure = 'none' === $encryption ? '' : $encryption;

		$username = (string) $settings->get( 'smtpUsername', '' );

		if ( '' !== $username ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $username;
			$phpmailer->Password = (string) $settings->get( 'smtpPassword', '' );
		}

		$from_email = (string) $settings->get( 'smtpFromEmail', '' );
		$from_name  = (string) $settings->get( 'smtpFromName', '' );

		if ( '' !== $from_email && is_email( $from_email ) ) {
			$phpmailer->setFrom( $from_email, '' !== $from_name ? $from_name : $from_email );
		}
		// phpcs:enable
	}

	/**
	 * Sends an email through wp_mail().
	 *
	 * @param string|string[] $headers Extra headers, e.g. `Reply-To: …`.
	 */
	public function send( string $to, string $subject, string $body, $headers = array() ): bool {
		return wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Sends a test email for the global Settings screen's "Send test email" action.
	 *
	 * @return true|WP_Error
	 */
	public function send_test( string $to ) {
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'swiftforms' ) );
		}

		$sent = $this->send(
			$to,
			__( 'SwiftForms test email', 'swiftforms' ),
			__( 'This is a test email from SwiftForms. If you received this, your email settings are working.', 'swiftforms' )
		);

		return $sent ? true : new WP_Error( 'test_email_failed', __( 'The test email could not be sent. Check your SMTP settings.', 'swiftforms' ) );
	}
}
