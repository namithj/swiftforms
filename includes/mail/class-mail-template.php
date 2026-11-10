<?php
/**
 * HTML mail template.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Mail_Template
 *
 * The HTML wrapper of a notification (setting `email.html_template`): site name, an optional logo (always
 * an attachment of this site, never a typed URL), the body, an accent colour and a footer. Table layout
 * and inline CSS only, so mail clients show it as written.
 */
class Mail_Template {

	/**
	 * Accent colour when none is set or the setting is not a hex colour.
	 */
	const DEFAULT_ACCENT = '#3c434a';

	/**
	 * Wrap a body in the template.
	 *
	 * @param string $body_html Body markup, already safe (kses'd body with escaped values).
	 * @return string A complete HTML document.
	 */
	public static function wrap( $body_html ) {
		$site   = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$accent = sanitize_hex_color( (string) Settings::get_value( 'email.accent_color' ) );
		$accent = is_string( $accent ) && '' !== $accent ? $accent : self::DEFAULT_ACCENT;

		$logo_id  = (int) Settings::get_value( 'email.logo_id' );
		$logo_url = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'medium' ) : false;
		$footer   = wp_kses_post( (string) Settings::get_value( 'email.footer_text' ) );

		$lang  = str_replace( '_', '-', (string) get_bloginfo( 'language' ) );
		$dir   = is_rtl() ? 'rtl' : 'ltr';
		$align = 'rtl' === $dir ? 'right' : 'left';

		$header = '';
		if ( is_string( $logo_url ) && '' !== $logo_url ) {
			$header .= '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $site ) . '" style="display:block;max-width:240px;height:auto;border:0;margin:0 0 8px">';
		}
		$header .= '<div style="font-size:18px;font-weight:600;line-height:1.3;color:' . esc_attr( $accent ) . '">' . esc_html( $site ) . '</div>';

		$html  = '<!DOCTYPE html>' . "\n";
		$html .= '<html lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '"><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $site ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f0f0f1">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0f0f1;border-collapse:collapse"><tr><td align="center" style="padding:24px 12px">';
		$html .= '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border-collapse:collapse;border-top:4px solid ' . esc_attr( $accent ) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;text-align:' . $align . '">';
		$html .= '<tr><td style="padding:20px 24px 8px">' . $header . '</td></tr>';
		$html .= '<tr><td style="padding:8px 24px 24px">' . $body_html . '</td></tr>';
		if ( '' !== trim( $footer ) ) {
			$html .= '<tr><td style="padding:16px 24px;border-top:1px solid #e2e4e7;font-size:13px;color:#646970">' . $footer . '</td></tr>';
		}
		$html .= '</table></td></tr></table></body></html>';

		return $html;
	}

	/**
	 * A plain-text version of HTML mail, for the AltBody part.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	public static function plain_text( $html ) {
		$text = (string) preg_replace( '#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$text = (string) preg_replace( '#<br\s*/?>(\r?\n)?#i', "\n", $text );
		$text = (string) preg_replace( '#</th>\s*#i', ': ', $text );
		$text = (string) preg_replace( '#</(p|div|tr|table|h[1-6]|li|ul|ol|blockquote)>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = (string) preg_replace( '/[ \t]+\n/', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}
}
