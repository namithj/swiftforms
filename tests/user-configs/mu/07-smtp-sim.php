<?php
/**
 * Config 07: what an SMTP plugin does to mail, as a snippet.
 *
 * It forces the From address and name through wp_mail_from and wp_mail_from_name, and on phpmailer_init it
 * sets a Sender (return path), switches the mailer to SMTP and adds a header. SwiftForms must keep working
 * through wp_mail() and its own From header may be overridden by the plugin without anything breaking.
 * The config captures mail with mail_capture "phpmailer", so these hooks really run.
 *
 * @package SwiftForms
 */

add_filter(
	'wp_mail_from',
	static function () {
		return 'relay@smtp-sim.example';
	}
);

add_filter(
	'wp_mail_from_name',
	static function () {
		return 'Old School Shop (relay)';
	}
);

add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		$mailer->isSMTP();
		$mailer->Host   = 'smtp.invalid'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		$mailer->Sender = 'bounces@smtp-sim.example'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		$mailer->addCustomHeader( 'X-Mailer-Sim', 'smtp-plugin' );
	},
	5
);
