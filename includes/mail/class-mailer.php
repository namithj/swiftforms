<?php
/**
 * Notification mail.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Mailer
 *
 * Sends the notifications of a stored submission through wp_mail() (so SMTP plugins keep working).
 *
 * - Spam sends nothing at all: no mail to the site owner either.
 * - The default notification (form attribute useDefaultNotification) goes to the setting
 *   email.notification_to, or the site's admin email; then every enabled item of `notifications`, in order.
 * - Recipients are literal addresses and {field:NAME} tags of email fields of the same form (Merge_Tags).
 *   Every address is validated with is_email(); unusable entries are dropped and logged.
 * - A notification with a recipient taken from a visitor value is visitor-addressed (C-6.4): its body uses
 *   the visitor limits of Merge_Tags and each such recipient falls under Mail_Limits.
 * - From is the settings (or the site name and wordpress@domain), never the visitor. Reply-To is a bare
 *   validated address. Header values carry no CR or LF.
 * - Nothing is thrown out of notify().
 */
class Mailer {

	/**
	 * Send the notifications of a submission.
	 *
	 * @param Submission_Context $ctx The (stored, non-spam) submission.
	 * @return void
	 */
	public static function notify( Submission_Context $ctx ) {
		if ( $ctx->is_spam ) {
			return;
		}

		try {
			$list = self::notifications( $ctx );
		} catch ( \Throwable $e ) {
			self::log_exception( $e, $ctx );
			return;
		}

		foreach ( $list as $index => $notification ) {
			try {
				self::send_one( $notification, $index, $ctx );
			} catch ( \Throwable $e ) {
				self::log_exception( $e, $ctx );
			}
		}
	}

	/**
	 * The notifications to send: the default one, then the enabled items of the form.
	 *
	 * @param Submission_Context $ctx Submission.
	 * @return array<int, array<string, mixed>>
	 */
	private static function notifications( Submission_Context $ctx ) {
		$list = [];
		$attr = $ctx->form->attributes;

		if ( ! empty( $attr['useDefaultNotification'] ) ) {
			$to = trim( (string) Settings::get_value( 'email.notification_to' ) );
			if ( '' === $to ) {
				$to = (string) get_option( 'admin_email' );
			}
			$list[] = [
				'enabled' => true,
				'to'      => $to,
				'cc'      => '',
				'bcc'     => '',
				'subject' => __( 'New submission: {form:title}', 'swiftforms' ),
				'replyTo' => '',
				'body'    => '{all_fields}',
				'format'  => 'html',
			];
		}

		$rows = isset( $attr['notifications'] ) && is_array( $attr['notifications'] ) ? $attr['notifications'] : [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row = array_merge( Field_Types::notification_defaults(), $row );
			if ( empty( $row['enabled'] ) ) {
				continue;
			}
			$list[] = $row;
		}

		return $list;
	}

	/**
	 * Build and send one notification.
	 *
	 * @param array<string, mixed> $n     Notification.
	 * @param int                  $index Position in the list (for the log).
	 * @param Submission_Context   $ctx   Submission.
	 * @return void
	 */
	private static function send_one( array $n, $index, Submission_Context $ctx ) {
		$form_id = $ctx->form->form_id;
		$ref     = [
			'entry'        => $ctx->entry_id,
			'notification' => $index,
		];

		// Recipients.
		$to  = self::recipients( (string) $n['to'], $ctx );
		$cc  = self::recipients( (string) $n['cc'], $ctx );
		$bcc = self::recipients( (string) $n['bcc'], $ctx );

		$dropped = $to['dropped'] + $cc['dropped'] + $bcc['dropped'];
		if ( $dropped > 0 ) {
			Log::add( 'mail', 'warning', sprintf( '%d recipient entries were not usable addresses and were dropped.', $dropped ), $ref, $form_id );
		}

		$seen    = [];
		$lists   = [];
		$visitor = [];
		foreach ( [
			'to'  => $to,
			'cc'  => $cc,
			'bcc' => $bcc,
		] as $field => $resolved ) {
			$lists[ $field ] = [];
			foreach ( $resolved['addresses'] as $address ) {
				$lower = strtolower( $address );
				if ( isset( $seen[ $lower ] ) ) {
					continue;
				}
				$seen[ $lower ]    = true;
				$lists[ $field ][] = $address;
			}
			foreach ( $resolved['visitor'] as $address ) {
				$visitor[ strtolower( $address ) ] = true;
			}
		}

		if ( [] === $seen ) {
			Log::add( 'mail', 'warning', 'A notification was skipped: it has no usable recipient.', $ref, $form_id );
			return;
		}

		// Visitor-addressed mail: the limits decide per recipient.
		$visitor_mail = [] !== $visitor;
		if ( $visitor_mail ) {
			foreach ( $visitor as $lower => $unused ) {
				if ( '' !== Mail_Limits::reserve( $lower, $form_id ) ) {
					foreach ( $lists as $field => $addresses ) {
						$lists[ $field ] = array_values(
							array_filter(
								$addresses,
								static function ( $address ) use ( $lower ) {
									return strtolower( $address ) !== $lower;
								}
							)
						);
					}
				}
			}
			if ( [] === $lists['to'] && [] === $lists['cc'] && [] === $lists['bcc'] ) {
				return; // Every recipient was held back; Mail_Limits logged why.
			}
		}

		// Subject.
		$subject = trim( (string) $n['subject'] );
		if ( '' === $subject ) {
			$subject = __( 'New submission: {form:title}', 'swiftforms' );
		}
		$subject = Merge_Tags::replace( $subject, $ctx, 'header', $visitor_mail );
		$subject = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $subject ) );

		// Body.
		$html_format = 'plain' !== $n['format'];
		$template    = (string) $n['body'];
		$alt_body    = null;
		if ( $html_format ) {
			$template = self::safe_body( $template );
			$body     = Merge_Tags::replace( $template, $ctx, 'html', $visitor_mail );
			$alt_body = Mail_Template::plain_text( $body );
			if ( true === Settings::get_value( 'email.html_template' ) ) {
				$body = Mail_Template::wrap( $body );
			}
		} else {
			$body = Merge_Tags::replace( $template, $ctx, 'plain', $visitor_mail );
		}

		// Headers.
		$headers   = [];
		$headers[] = 'From: ' . self::from();
		$reply_to  = self::reply_to( (string) $n['replyTo'], $ctx );
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		if ( [] !== $lists['cc'] ) {
			$headers[] = 'Cc: ' . implode( ', ', $lists['cc'] );
		}
		if ( [] !== $lists['bcc'] ) {
			$headers[] = 'Bcc: ' . implode( ', ', $lists['bcc'] );
		}
		$charset   = (string) get_bloginfo( 'charset' );
		$headers[] = 'Content-Type: ' . ( $html_format ? 'text/html' : 'text/plain' ) . '; charset=' . ( '' !== $charset ? $charset : 'UTF-8' );

		$mail = [
			'to'          => $lists['to'],
			'subject'     => $subject,
			'message'     => $body,
			'headers'     => $headers,
			'attachments' => [],
		];

		/**
		 * Filters a notification mail before it is sent, or stops it.
		 *
		 * Runs once per notification, after the recipients, subject, body and headers were built (the
		 * visitor-mail limits have already removed the recipients they held back). Return the array
		 * (changed or not) to send it, or false to skip this mail. `to` is a list of addresses; the Cc and
		 * Bcc recipients are in the `headers` list. You are responsible for anything you add: keep header
		 * values free of line breaks, and do not put a visitor-supplied value in From.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $mail         Keys: to (string[]), subject (string), message (string), headers (string[]), attachments (string[]).
		 * @param Submission_Context   $context      The submission.
		 * @param array<string, mixed> $notification The notification: enabled, to, cc, bcc, subject, replyTo, body, format (the templates as authored, before tags are replaced).
		 */
		$mail = apply_filters( 'swiftforms_before_send_mail', $mail, $ctx, $n );
		if ( ! is_array( $mail ) || ! isset( $mail['to'], $mail['subject'], $mail['message'] ) ) {
			Log::add( 'mail', 'info', 'A notification was not sent: a swiftforms_before_send_mail filter stopped it.', $ref, $form_id );
			return;
		}
		$mail = array_merge(
			[
				'headers'     => [],
				'attachments' => [],
			],
			$mail
		);

		$sent = self::deliver( $mail, $alt_body, $error );

		$recipients = array_merge( (array) $mail['to'], $lists['cc'], $lists['bcc'] );
		if ( $sent ) {
			Log::add( 'mail', 'info', 'Notification sent.', $ref + [ 'to' => array_values( $recipients ) ], $form_id );
		} else {
			Log::add( 'mail', 'error', 'Notification could not be sent: ' . $error, $ref + [ 'to' => array_values( $recipients ) ], $form_id );
		}

		/**
		 * Fires after a notification was handed to wp_mail().
		 *
		 * Fires for failures too ($sent is false then); the failure is already logged. Not fired for
		 * spam, and not when the mail was skipped.
		 *
		 * @since 1.0.0
		 *
		 * @param bool               $sent    Whether wp_mail() accepted the mail.
		 * @param array<string, mixed> $mail  The mail as it was passed to wp_mail(): to, subject, message, headers, attachments.
		 * @param Submission_Context $context The submission.
		 */
		do_action( 'swiftforms_mail_sent', $sent, $mail, $ctx );
	}

	/**
	 * Call wp_mail() with the plain-text alternative attached for this one call only.
	 *
	 * @param array<string, mixed> $mail     The mail.
	 * @param string|null          $alt_body Plain-text version, or null for plain mail.
	 * @param string               $error    Out: the failure message.
	 * @return bool
	 */
	private static function deliver( array $mail, $alt_body, &$error = '' ) {
		$error = '';

		$on_failure = static function ( $wp_error ) use ( &$error ) {
			if ( $wp_error instanceof \WP_Error ) {
				$error = substr( $wp_error->get_error_code() . ' ' . $wp_error->get_error_message(), 0, 300 );
			}
		};
		$alt        = null === $alt_body ? null : static function ( $phpmailer ) use ( $alt_body ) {
			if ( is_object( $phpmailer ) ) {
				$phpmailer->AltBody = $alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
			}
		};

		add_action( 'wp_mail_failed', $on_failure );
		if ( null !== $alt ) {
			add_action( 'phpmailer_init', $alt, 99 );
		}

		try {
			$sent = (bool) wp_mail( $mail['to'], (string) $mail['subject'], (string) $mail['message'], $mail['headers'], $mail['attachments'] );
		} catch ( \Throwable $e ) {
			$sent  = false;
			$error = get_class( $e );
		} finally {
			remove_action( 'wp_mail_failed', $on_failure );
			if ( null !== $alt ) {
				remove_action( 'phpmailer_init', $alt, 99 );
			}
		}

		if ( ! $sent && '' === $error ) {
			$error = 'wp_mail() returned false.';
		}
		return $sent;
	}

	/**
	 * A stored body, made safe at send time (C-6.3). A body without markup gets paragraphs.
	 *
	 * @param string $body Stored body.
	 * @return string
	 */
	private static function safe_body( $body ) {
		if ( false === strpos( $body, '<' ) && '{all_fields}' !== trim( $body ) ) {
			$body = wpautop( $body );
		}
		return wp_kses_post( $body );
	}

	/**
	 * Resolve a to/cc/bcc template.
	 *
	 * @param string             $template Comma-separated addresses and tags.
	 * @param Submission_Context $ctx      Submission.
	 * @return array{addresses:string[], visitor:string[], dropped:int}
	 */
	private static function recipients( $template, Submission_Context $ctx ) {
		$resolved = Merge_Tags::recipient_tags( $template, $ctx );

		$dropped = 0;
		$parts   = preg_split( '/[,;]/', $template );
		foreach ( false === $parts ? [] : $parts as $part ) {
			if ( '' !== trim( $part ) && [] === Merge_Tags::recipient_tags( $part, $ctx )['addresses'] ) {
				++$dropped;
			}
		}

		$resolved['dropped'] = $dropped;
		return $resolved;
	}

	/**
	 * The bare Reply-To address, or an empty string.
	 *
	 * @param string             $template A literal address, or a tag of an email field.
	 * @param Submission_Context $ctx      Submission.
	 * @return string
	 */
	private static function reply_to( $template, Submission_Context $ctx ) {
		if ( '' === trim( $template ) ) {
			return '';
		}
		$resolved = Merge_Tags::recipient_tags( $template, $ctx );
		return isset( $resolved['addresses'][0] ) ? (string) $resolved['addresses'][0] : '';
	}

	/**
	 * The From header value: settings, or the site name and wordpress@domain.
	 *
	 * @return string
	 */
	private static function from() {
		$name  = trim( (string) Settings::get_value( 'email.from_name' ) );
		$email = trim( (string) Settings::get_value( 'email.from_email' ) );

		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		}
		$name = trim( (string) preg_replace( '/[\x00-\x1F\x7F<>",;]+/', ' ', $name ) );

		if ( '' === $email || false === is_email( $email ) ) {
			$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
			if ( 0 === strpos( strtolower( $host ), 'www.' ) ) {
				$host = substr( $host, 4 );
			}
			$email = 'wordpress@' . ( '' !== $host ? $host : 'localhost' );
		}

		return '' !== $name ? $name . ' <' . $email . '>' : $email;
	}

	/**
	 * Log an exception (class only: its message can hold visitor data).
	 *
	 * @param \Throwable         $e   Exception.
	 * @param Submission_Context $ctx Submission.
	 * @return void
	 */
	private static function log_exception( \Throwable $e, Submission_Context $ctx ) {
		Log::add( 'mail', 'error', 'A notification failed: ' . get_class( $e ), [ 'entry' => $ctx->entry_id ], $ctx->form->form_id );
	}
}
