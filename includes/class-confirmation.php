<?php
/**
 * Confirmation builder.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Confirmation
 *
 * What the visitor gets after a submission (C-3.4): a message or a redirect, built from the CURRENT form
 * (C-3.8) and the submission.
 *
 * - message:  the stored confirmation inner blocks rendered on the server, then the merge tags in html mode
 *             (values escaped). With no usable blocks, general.default_success_message (kses'd).
 * - redirect: redirectPageId when that page is published and viewable (its permalink), otherwise the message;
 *             or redirectUrl through Merge_Tags::redirect_url() (tags only in the query and fragment,
 *             http/https only), and the message when that gives null.
 *
 * The confirmation is the same for a clean and a spam submission (no oracle), so it is built from a copy of
 * the context in which {entry:id} is empty: a guest never sees an entry number (no sequential-ID disclosure,
 * A1), and a spam submission that was not stored looks like one that was. The notification mail
 * (Submission_Context::$entry_id) still has the real ID.
 */
class Confirmation {

	/**
	 * Build the confirmation of a submission.
	 *
	 * @param Submission_Context $ctx The submission.
	 * @return array<string, string> { type: "message", html } or { type: "redirect", url }.
	 */
	public static function build( Submission_Context $ctx ) {
		$visible           = clone $ctx;
		$visible->entry_id = 0;
		$attributes        = $ctx->form->attributes;

		if ( 'redirect' === ( $attributes['confirmationType'] ?? 'message' ) ) {
			$url = self::redirect_target( $attributes, $visible );
			if ( null !== $url ) {
				return [
					'type' => 'redirect',
					'url'  => $url,
				];
			}
		}

		return [
			'type' => 'message',
			'html' => self::message_html( $visible ),
		];
	}

	/**
	 * Where a redirect confirmation goes, or null to show the message.
	 *
	 * @param array<string, mixed> $attributes Form attributes.
	 * @param Submission_Context   $ctx        Submission.
	 * @return string|null
	 */
	private static function redirect_target( array $attributes, Submission_Context $ctx ) {
		$page_id = (int) ( $attributes['redirectPageId'] ?? 0 );
		if ( $page_id > 0 ) {
			$post = get_post( $page_id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) || '' !== (string) $post->post_password ) {
				return null;
			}
			$link = get_permalink( $post );
			return is_string( $link ) && '' !== $link ? $link : null;
		}

		$template = (string) ( $attributes['redirectUrl'] ?? '' );
		return '' === trim( $template ) ? null : Merge_Tags::redirect_url( $template, $ctx );
	}

	/**
	 * The confirmation message as HTML.
	 *
	 * @param Submission_Context $ctx Submission.
	 * @return string
	 */
	private static function message_html( Submission_Context $ctx ) {
		$html = '';
		foreach ( $ctx->form->confirmation_blocks as $block ) {
			if ( is_array( $block ) ) {
				$html .= render_block( $block );
			}
		}

		if ( '' === trim( wp_strip_all_tags( $html, true ) ) && false === stripos( $html, '<img' ) ) {
			$html = '<p>' . wp_kses_post( (string) Settings::get_value( 'general.default_success_message', '' ) ) . '</p>';
		}

		return Merge_Tags::replace( $html, $ctx, 'html' );
	}
}
