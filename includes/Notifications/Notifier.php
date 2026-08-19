<?php
/**
 * Admin notification + autoresponder emails.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Notifications;

use SwiftForms\Settings\GlobalSettings;

/**
 * Resolves recipients from the form's own settings, falling back to global
 * defaults, then `get_option('admin_email')`. Notification config always
 * comes from stored form settings — never from the client request — so a
 * visitor can never turn a form into an open mail relay.
 */
final class Notifier {

	public function __construct( private TemplateRenderer $template_renderer, private Mailer $mailer ) {
	}

	/**
	 * @param int                                                            $entry_id      New entry post id (0 if entries aren't being saved).
	 * @param int                                                            $form_id       Source form post id.
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields Schema-enforced fields.
	 * @param array<string, mixed>                                          $form_settings Resolved `_swf_settings`.
	 */
	public function dispatch( int $entry_id, int $form_id, array $fields, array $form_settings ): void {
		$form_title = get_the_title( $form_id );
		$context    = array(
			'entry_id'   => $entry_id,
			'form_id'    => $form_id,
			'form_title' => $form_title,
			'fields'     => $this->context_fields( $fields ),
		);

		$this->send_admin_notification( $context, $form_settings );
		$this->send_autoresponder( $context, $fields, $form_settings );
	}

	/**
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields Schema-enforced fields.
	 * @return array<int, array{slug: string, label: string, value: mixed}>
	 */
	private function context_fields( array $fields ): array {
		return array_map(
			static fn( array $field ) => array(
				'slug'  => $field['slug'],
				'label' => (string) ( $field['attributes']['label'] ?? $field['slug'] ),
				'value' => $field['value'],
			),
			$fields
		);
	}

	/**
	 * @param array{entry_id: int, form_id: int, form_title: string, fields: array<int, array{slug: string, label: string, value: mixed}>} $context Template context.
	 * @param array<string, mixed> $form_settings Resolved `_swf_settings`.
	 */
	private function send_admin_notification( array $context, array $form_settings ): void {
		$recipients = $this->resolve_admin_recipients( $form_settings );

		if ( ! $recipients ) {
			return;
		}

		$subject = $this->template_renderer->render( (string) $form_settings['adminSubject'], $context );
		$subject = sanitize_text_field( $subject ); // Strip newlines: rules out header injection via a field value in the subject.

		$body = $form_settings['adminTemplate']
			? $this->template_renderer->render( (string) $form_settings['adminTemplate'], $context )
			: $this->template_renderer->render( '{fields}', $context );

		/**
		 * Filters an outgoing notification's body before it's sent.
		 *
		 * @param string $body    Rendered body.
		 * @param string $context One of 'admin', 'autoresponder'.
		 * @param int    $entry_id Entry post id.
		 */
		$body = (string) apply_filters( 'swf_email_content', $body, 'admin', $context['entry_id'] );

		$headers  = array();
		$reply_to = $this->first_email_value( $context['fields'] );
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$this->mailer->send( $recipients, $subject, $body, $headers );
	}

	/**
	 * @param array{entry_id: int, form_id: int, form_title: string, fields: array<int, array{slug: string, label: string, value: mixed}>} $context Template context.
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields Schema-enforced fields.
	 * @param array<string, mixed> $form_settings Resolved `_swf_settings`.
	 */
	private function send_autoresponder( array $context, array $fields, array $form_settings ): void {
		$recipient = $this->resolve_autoresponder_recipient( $fields, $form_settings );

		if ( ! $recipient ) {
			return;
		}

		$subject = sanitize_text_field( $this->template_renderer->render( (string) $form_settings['autoresponderSubject'], $context ) );
		$body    = $form_settings['autoresponderTemplate']
			? $this->template_renderer->render( (string) $form_settings['autoresponderTemplate'], $context )
			: $subject;

		$body = (string) apply_filters( 'swf_email_content', $body, 'autoresponder', $context['entry_id'] );

		$this->mailer->send( $recipient, $subject, $body );
	}

	/**
	 * @param array<string, mixed> $form_settings Resolved `_swf_settings`.
	 */
	private function resolve_admin_recipients( array $form_settings ): string {
		$configured = trim( (string) $form_settings['adminRecipients'] );

		if ( '' !== $configured ) {
			return $configured;
		}

		$default = trim( (string) GlobalSettings::instance()->get( 'defaultAdminRecipients', '' ) );

		return '' !== $default ? $default : (string) get_option( 'admin_email' );
	}

	/**
	 * @param array<int, array{slug: string, type: string, value: mixed, attributes: array<string, mixed>}> $fields Schema-enforced fields.
	 * @param array<string, mixed> $form_settings Resolved `_swf_settings`.
	 */
	private function resolve_autoresponder_recipient( array $fields, array $form_settings ): string {
		$configured_slug = (string) $form_settings['autoresponderField'];

		if ( '' !== $configured_slug ) {
			foreach ( $fields as $field ) {
				if ( $field['slug'] === $configured_slug && is_string( $field['value'] ) && is_email( $field['value'] ) ) {
					return (string) $field['value'];
				}
			}
		}

		foreach ( $fields as $field ) {
			if ( 'email' === $field['type'] && is_string( $field['value'] ) && is_email( $field['value'] ) ) {
				return (string) $field['value'];
			}
		}

		return '';
	}

	/**
	 * @param array<int, array{slug: string, label: string, value: mixed}> $context_fields Template context fields.
	 */
	private function first_email_value( array $context_fields ): string {
		foreach ( $context_fields as $field ) {
			if ( is_string( $field['value'] ) && is_email( $field['value'] ) ) {
				return (string) $field['value'];
			}
		}

		return '';
	}
}
