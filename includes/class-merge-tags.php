<?php
/**
 * Merge tags.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Merge_Tags
 *
 * The one engine that puts submission data into text (C-6.2): confirmations, notification subjects and
 * bodies, recipients and redirect URLs.
 *
 * Tags: {field:NAME}, {all_fields}, {form:title}, {form:id}, {entry:id}, {entry:date}, {site:name},
 * {site:url}, {page:url}, {page:title}, {user:id}, {user:email}, {user:display_name}, plus whatever the
 * swiftforms_merge_tags filter adds. A tag the engine does not know (including {field:x} for a field the
 * form does not have) is left exactly as written; tags are never removed.
 *
 * Substitution is SINGLE-PASS: one regular-expression pass with a callback. What a tag turns into is
 * never scanned again, so a visitor who types "{user:email}" or "{all_fields}" into a field gets those
 * characters back, not the data.
 *
 * Values are plain text and are escaped for the place they land (modes):
 *
 *   html    text and markup: values are HTML-escaped. A template that contains markup is walked with
 *           WP_HTML_Tag_Processor: tags are replaced in text nodes and in attribute values only. In an
 *           attribute the value is attribute-escaped; in href, src and the other URL attributes a tag is
 *           allowed only as the whole value (and then only an http(s) URL comes through) or in the query
 *           and fragment (percent-encoded); inside <script>, <style>, <textarea>, <title>, in on* event
 *           attributes and in style/srcset attributes tags stay as written.
 *   attr    a value for an HTML attribute: values attribute-escaped.
 *   plain   plain-text mail: values as text, line endings normalised to "\n".
 *   header  a mail header value: CR, LF and other control characters become spaces.
 *   url     a URL part: values rawurlencode()d.
 *
 * Visitor-addressed mail (C-6.4) passes $visitor = true: each field value is cut to 500 characters and
 * {all_fields} lists at most 50 fields.
 */
class Merge_Tags {

	/**
	 * Modes.
	 */
	const MODES = [ 'html', 'attr', 'plain', 'header', 'url' ];

	/**
	 * What a tag looks like: {name} or {name:part}.
	 */
	const TAG_PATTERN = '/\{([a-z][a-z0-9_]*(?::[A-Za-z0-9_-]{1,64})?)\}/';

	/**
	 * Most fields {all_fields} lists in visitor-addressed mail.
	 */
	const MAX_ALL_FIELDS = 50;

	/**
	 * Longest field value in visitor-addressed mail.
	 */
	const MAX_VALUE_LENGTH = 500;

	/**
	 * Attributes that hold a URL.
	 */
	const URL_ATTRIBUTES = [ 'href', 'src', 'action', 'formaction', 'poster', 'cite', 'data', 'longdesc', 'xlink:href' ];

	/**
	 * Replace the tags in a template.
	 *
	 * @param string             $template Template text or HTML.
	 * @param Submission_Context $ctx      Submission.
	 * @param string             $mode     One of MODES (anything else is "plain").
	 * @param bool               $visitor  Whether the result is mailed to an address a visitor supplied (shorter values, 50 fields).
	 * @return string
	 */
	public static function replace( $template, Submission_Context $ctx, $mode = 'html', $visitor = false ) {
		$template = is_string( $template ) ? $template : '';
		if ( '' === $template || false === strpos( $template, '{' ) ) {
			return $template;
		}
		$mode = in_array( $mode, self::MODES, true ) ? $mode : 'plain';
		$tags = self::tags( $ctx, $mode, $visitor );

		if ( 'html' === $mode && false !== strpos( $template, '<' ) ) {
			return self::replace_in_html( $template, $ctx, $tags, $visitor );
		}

		return self::substitute( $template, $tags, $mode, $ctx, $visitor );
	}

	/**
	 * The redirect URL of a template, or null when it is not usable.
	 *
	 * Tags are allowed only in the query string and the fragment, never in the scheme, host or path
	 * (A5): that would let a visitor choose where the redirect goes. Values are percent-encoded. The
	 * result must be an absolute http or https URL after esc_url_raw().
	 *
	 * @param string             $template The redirectUrl attribute.
	 * @param Submission_Context $ctx      Submission.
	 * @return string|null Null means "show the message instead".
	 */
	public static function redirect_url( $template, Submission_Context $ctx ) {
		$template = trim( is_string( $template ) ? $template : '' );
		if ( 1 !== preg_match( '#^https?://#i', $template ) ) {
			return null;
		}

		$split = strcspn( $template, '?#' );
		$base  = substr( $template, 0, $split );
		$rest  = substr( $template, $split );
		if ( false !== strpos( $base, '{' ) ) {
			return null;
		}

		if ( '' !== $rest && false !== strpos( $rest, '{' ) ) {
			$rest = self::substitute( $rest, self::tags( $ctx, 'url', false ), 'url', $ctx, false );
		}

		$url = esc_url_raw( $base . $rest, [ 'http', 'https' ] );
		if ( '' === $url ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! isset( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * Recipient addresses from a to/cc/bcc template.
	 *
	 * Merge tags in a recipient field resolve ONLY to values of email-type fields of the same form (the
	 * field must be an email field in the current form and in the schema the value was validated against),
	 * and only as a whole entry ("{field:email}" between the commas); every other tag, and a tag with text
	 * around it, gives nothing. The swiftforms_merge_tags filter does not apply here.
	 *
	 * @param string             $template Comma-separated addresses and tags.
	 * @param Submission_Context $ctx      Submission.
	 * @return array{addresses:string[], visitor:string[]} All valid addresses (duplicates removed, case-insensitively) and the subset that came from visitor values (those mails fall under the visitor-mail limits, C-6.4).
	 */
	public static function recipient_tags( $template, Submission_Context $ctx ) {
		$addresses = [];
		$visitor   = [];

		$parts = preg_split( '/[,;]/', is_string( $template ) ? $template : '' );
		foreach ( false === $parts ? [] : $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			$from_tag = false;
			if ( false !== strpos( $part, '{' ) ) {
				// A tag is the whole entry: text around it would let the author's characters and the visitor's form one address.
				if ( 1 !== preg_match( '/^\{field:([A-Za-z0-9_-]{1,64})\}$/D', $part, $m ) || ! self::is_email_field( $ctx, $m[1] ) || ! isset( $ctx->values[ $m[1] ] ) || ! is_string( $ctx->values[ $m[1] ] ) ) {
					continue;
				}
				$part     = $ctx->values[ $m[1] ];
				$from_tag = true;
			} elseif ( 1 === preg_match( '/<([^<>]+)>\s*$/', $part, $m ) ) {
				$part = trim( $m[1] ); // "Name <address>" written by the author.
			}

			$address = is_email( $part );
			if ( false === $address ) {
				continue;
			}
			$lower = strtolower( $address );
			if ( isset( $addresses[ $lower ] ) ) {
				if ( $from_tag ) {
					$visitor[ $lower ] = $address;
				}
				continue;
			}
			$addresses[ $lower ] = $address;
			if ( $from_tag ) {
				$visitor[ $lower ] = $address;
			}
		}

		return [
			'addresses' => array_values( $addresses ),
			'visitor'   => array_values( $visitor ),
		];
	}

	/**
	 * The {all_fields} output for a mode, for callers that need it on its own.
	 *
	 * @param Submission_Context $ctx     Submission.
	 * @param string             $mode    html (a table) or anything else (plain lines).
	 * @param bool               $visitor Visitor-addressed limits.
	 * @return string HTML for "html", plain text otherwise.
	 */
	public static function all_fields( Submission_Context $ctx, $mode = 'html', $visitor = false ) {
		$rows = self::field_rows( $ctx, $visitor );

		if ( 'html' === $mode ) {
			$html = '<table class="swiftforms-fields" role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse">';
			foreach ( $rows as $row ) {
				$html .= '<tr><th align="left" valign="top" style="padding:8px 12px 8px 0;border-bottom:1px solid #e2e4e7;font-weight:600">' . self::esc_text( $row[0] )
					. '</th><td valign="top" style="padding:8px 0;border-bottom:1px solid #e2e4e7">' . nl2br( self::esc_text( $row[1] ), false ) . '</td></tr>';
			}
			return $html . '</table>';
		}

		$lines = [];
		foreach ( $rows as $row ) {
			$lines[] = $row[0] . ': ' . $row[1];
		}
		return self::to_plain( implode( "\n", $lines ) );
	}

	// ---------------------------------------------------------------------
	// Substitution.
	// ---------------------------------------------------------------------

	/**
	 * One-pass substitution in a template that is not an HTML document.
	 *
	 * @param string               $template Template.
	 * @param array<string,string> $tags     Tag values (plain text).
	 * @param string               $mode     Mode.
	 * @param Submission_Context   $ctx      Submission.
	 * @param bool                 $visitor  Visitor-addressed limits.
	 * @return string
	 */
	private static function substitute( $template, array $tags, $mode, Submission_Context $ctx, $visitor ) {
		$result = preg_replace_callback(
			self::TAG_PATTERN,
			static function ( $m ) use ( $tags, $mode, $ctx, $visitor ) {
				if ( isset( $tags[ $m[1] ] ) ) {
					return self::encode( $tags[ $m[1] ], $mode );
				}
				if ( 'all_fields' === $m[1] ) {
					return 'html' === $mode ? self::all_fields( $ctx, 'html', $visitor ) : self::encode( self::all_fields( $ctx, 'plain', $visitor ), $mode );
				}
				return $m[0]; // Unknown: verbatim.
			},
			$template
		);
		return is_string( $result ) ? $result : $template;
	}

	/**
	 * Substitution in an HTML document: text nodes and attribute values only.
	 *
	 * @param string               $html    Markup.
	 * @param Submission_Context   $ctx     Submission.
	 * @param array<string,string> $tags    Tag values (plain text).
	 * @param bool                 $visitor Visitor-addressed limits.
	 * @return string
	 */
	private static function replace_in_html( $html, Submission_Context $ctx, array $tags, $visitor ) {
		// {all_fields} is markup, so it cannot go through set_modifiable_text(); a random marker stands in and is swapped at the end.
		// Visitor text is inserted before the swap and cannot contain a marker it never saw.
		$marker = 'sf-all-fields-' . Random::token( 8 );
		$has_af = false;

		$text_tags = $tags;
		$p         = new \WP_HTML_Tag_Processor( $html );

		while ( $p->next_token() ) {
			$type = $p->get_token_type();

			if ( '#text' === $type ) {
				$text = $p->get_modifiable_text();
				if ( false === strpos( $text, '{' ) ) {
					continue;
				}
				$new = (string) preg_replace_callback(
					self::TAG_PATTERN,
					static function ( $m ) use ( $text_tags, $marker, &$has_af ) {
						if ( isset( $text_tags[ $m[1] ] ) ) {
							return $text_tags[ $m[1] ];
						}
						if ( 'all_fields' === $m[1] ) {
							$has_af = true;
							return $marker;
						}
						return $m[0];
					},
					$text
				);
				if ( $new !== $text ) {
					$p->set_modifiable_text( $new );
				}
				continue;
			}

			if ( '#tag' !== $type || $p->is_tag_closer() ) {
				continue;
			}

			$names = $p->get_attribute_names_with_prefix( '' );
			foreach ( is_array( $names ) ? $names : [] as $name ) {
				$value = $p->get_attribute( $name );
				if ( ! is_string( $value ) || false === strpos( $value, '{' ) ) {
					continue;
				}
				if ( 0 === strpos( $name, 'on' ) || in_array( $name, [ 'style', 'srcset' ], true ) ) {
					continue; // Script and CSS contexts: tags stay as written.
				}
				$new = in_array( $name, self::URL_ATTRIBUTES, true )
					? self::url_attribute( $value, $tags, $ctx, $visitor )
					: self::substitute( $value, $tags, 'plain', $ctx, $visitor );
				if ( $new !== $value ) {
					$p->set_attribute( $name, $new );
				}
			}
		}

		$out = $p->get_updated_html();
		if ( $has_af ) {
			$out = str_replace( $marker, self::all_fields( $ctx, 'html', $visitor ), $out );
		}
		return $out;
	}

	/**
	 * The value of a URL attribute with its tags replaced.
	 *
	 * A tag may be the whole value (then the visitor's value must itself be an http(s) URL, otherwise the
	 * attribute becomes empty), or sit in the query or fragment (percent-encoded). A tag in the scheme,
	 * host or path leaves the value unchanged.
	 *
	 * @param string               $value   Attribute value as written.
	 * @param array<string,string> $tags    Tag values.
	 * @param Submission_Context   $ctx     Submission.
	 * @param bool                 $visitor Visitor-addressed limits.
	 * @return string
	 */
	private static function url_attribute( $value, array $tags, Submission_Context $ctx, $visitor ) {
		$trimmed = trim( $value );

		if ( 1 === preg_match( '/^\{([a-z][a-z0-9_]*(?::[A-Za-z0-9_-]{1,64})?)\}$/D', $trimmed, $m ) ) {
			if ( ! isset( $tags[ $m[1] ] ) || ! Validator::is_http_url( $tags[ $m[1] ] ) ) {
				return 'all_fields' === $m[1] || isset( $tags[ $m[1] ] ) ? '' : $value;
			}
			return esc_url_raw( $tags[ $m[1] ], [ 'http', 'https' ] );
		}

		$split = strcspn( $value, '?#' );
		$base  = substr( $value, 0, $split );
		$rest  = substr( $value, $split );
		if ( false !== strpos( $base, '{' ) || '' === $rest ) {
			return $value;
		}
		return $base . self::substitute( $rest, $tags, 'url', $ctx, $visitor );
	}

	/**
	 * Escape (or otherwise encode) a plain-text value for a mode.
	 *
	 * @param string $value Plain text.
	 * @param string $mode  Mode.
	 * @return string
	 */
	private static function encode( $value, $mode ) {
		switch ( $mode ) {
			case 'html':
			case 'attr':
				return self::esc_text( $value );
			case 'url':
				return rawurlencode( $value );
			case 'header':
				return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
			default:
				return self::to_plain( $value );
		}
	}

	/**
	 * HTML-escape text, quotes included. Existing entities are escaped too: a value is text, so "&amp;" the visitor typed stays "&amp;" on screen.
	 *
	 * @param string $value Plain text.
	 * @return string
	 */
	private static function esc_text( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Plain text for a text mail: line endings normalised, NUL removed.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function to_plain( $value ) {
		return str_replace( [ "\r\n", "\r", "\0" ], [ "\n", "\n", '' ], (string) $value );
	}

	// ---------------------------------------------------------------------
	// Tag values.
	// ---------------------------------------------------------------------

	/**
	 * The form's title, or the title the form index shows for it (its source's title).
	 *
	 * The inserted default form has no title attribute; the admin screens then show the
	 * source's title, so {form:title} says the same.
	 *
	 * @param Submission_Context $ctx Submission.
	 * @return string
	 */
	private static function form_title( Submission_Context $ctx ) {
		$title = $ctx->form->title();
		if ( '' !== $title ) {
			return $title;
		}
		$row = Form_Index::get_row( $ctx->form->form_id );
		return null !== $row && isset( $row['title'] ) ? (string) $row['title'] : '';
	}

	/**
	 * The plain-text value of every tag (all_fields excluded), after the swiftforms_merge_tags filter.
	 *
	 * @param Submission_Context $ctx     Submission.
	 * @param string             $mode    Mode the tags are used in.
	 * @param bool               $visitor Visitor-addressed limits.
	 * @return array<string,string>
	 */
	private static function tags( Submission_Context $ctx, $mode, $visitor ) {
		$user = $ctx->user instanceof \WP_User ? $ctx->user : null;
		$tags = [
			'form:title'        => self::form_title( $ctx ),
			'form:id'           => $ctx->form->form_id,
			'entry:id'          => $ctx->entry_id > 0 ? (string) $ctx->entry_id : '',
			'entry:date'        => self::entry_date( $ctx ),
			'site:name'         => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
			'site:url'          => home_url(),
			'page:url'          => $ctx->page_url,
			'page:title'        => $ctx->page_title,
			'user:id'           => $user ? (string) $user->ID : '',
			'user:email'        => $user ? (string) $user->user_email : '',
			'user:display_name' => $user ? (string) $user->display_name : '',
		];
		foreach ( $ctx->values as $name => $value ) {
			$tags[ 'field:' . $name ] = self::flatten( $value, $visitor );
		}

		/**
		 * Filters the merge tag values before they are substituted.
		 *
		 * The array maps a tag name without braces ("field:email", "site:name", ...) to its PLAIN-TEXT
		 * value. Add entries to create your own tags ({order:total} is the key "order:total": lowercase
		 * letters and digits, optionally ":" and a name), or change entries to override a built-in one.
		 * Do not escape the values: the engine escapes them for the place they land (HTML text, an
		 * attribute, a mail header, a URL), in one pass, and never scans them for tags again. Only strings
		 * and numbers are kept. {all_fields} is built from the fields and is not part of this array. The
		 * recipient fields (to, cc, bcc) ignore this filter.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $tags    Tag name => plain-text value.
		 * @param Submission_Context    $context The submission.
		 * @param string                $mode    html, attr, plain, header or url: where the result is used.
		 */
		$filtered = apply_filters( 'swiftforms_merge_tags', $tags, $ctx, $mode );
		if ( ! is_array( $filtered ) ) {
			return $tags;
		}

		$clean = [];
		foreach ( $filtered as $key => $value ) {
			if ( is_string( $key ) && 1 === preg_match( '/^[a-z][a-z0-9_]*(?::[A-Za-z0-9_-]{1,64})?$/D', $key ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ) {
				$clean[ $key ] = (string) $value;
			}
		}
		return $clean;
	}

	/**
	 * A field value as one string: a list is joined with ", ". In visitor-addressed mail it is cut to 500 characters.
	 *
	 * @param string|string[] $value   Value.
	 * @param bool            $visitor Visitor-addressed limits.
	 * @return string
	 */
	private static function flatten( $value, $visitor ) {
		$text = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		return $visitor ? self::truncate( $text ) : $text;
	}

	/**
	 * Cut text to the visitor-mail limit.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function truncate( $text ) {
		if ( mb_strlen( $text, 'UTF-8' ) <= self::MAX_VALUE_LENGTH ) {
			return $text;
		}
		return mb_substr( $text, 0, self::MAX_VALUE_LENGTH, 'UTF-8' ) . '…';
	}

	/**
	 * The submission time in the site's date and time format and time zone.
	 *
	 * @param Submission_Context $ctx Submission.
	 * @return string
	 */
	private static function entry_date( Submission_Context $ctx ) {
		$time = strtotime( $ctx->created_gmt . ' UTC' );
		if ( false === $time ) {
			return '';
		}
		$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * The label and value of each field for {all_fields}.
	 *
	 * @param Submission_Context $ctx     Submission.
	 * @param bool               $visitor Visitor-addressed limits.
	 * @return array<int, array{0:string, 1:string}>
	 */
	private static function field_rows( Submission_Context $ctx, $visitor ) {
		$rows = [];
		foreach ( $ctx->validation_schema->fields as $field ) {
			if ( $visitor && count( $rows ) >= self::MAX_ALL_FIELDS ) {
				break;
			}
			$name  = (string) $field['name'];
			$label = trim( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) );
			if ( '' === $label && 'consent' === $field['type'] ) {
				$label = trim( wp_strip_all_tags( (string) ( $field['text'] ?? '' ) ) );
			}
			$rows[] = [
				'' !== $label ? $label : $name,
				self::flatten( $ctx->values[ $name ] ?? '', $visitor ),
			];
		}
		return $rows;
	}

	/**
	 * Whether a field is an email field in both the current form and the schema the value was validated against.
	 *
	 * @param Submission_Context $ctx  Submission.
	 * @param string             $name Field name.
	 * @return bool
	 */
	private static function is_email_field( Submission_Context $ctx, $name ) {
		$current   = $ctx->form->field( $name );
		$validated = $ctx->validation_schema->field( $name );
		return null !== $current && null !== $validated && 'email' === $current['type'] && 'email' === $validated['type'];
	}
}
