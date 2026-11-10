<?php
/**
 * Server-side validation.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Validator
 *
 * The server is the authority on what a submission may contain (C-3.2). Two steps:
 *
 * - collect():  turns the posted `sf` array into clean values for the fields of a schema and nothing
 *               else. Unknown and extra fields are discarded, a hidden field's value is recomputed from
 *               its attribute and the source (a posted value is ignored, C-3.10), multi-value fields
 *               become lists, everything is cleaned to valid UTF-8 without control characters. Values are
 *               text: tags are NOT stripped, every place that prints a value escapes it.
 * - validate(): applies the rules of C-3.2 and R01 §2.5 and returns { field name => message }, one
 *               message per field (the first rule that fails). The error keys are the field names and
 *               are stable; the wording may improve.
 *
 * One function per rule (rule_*), each returning a message key or null, so the client (P6) can mirror
 * them with the same translatable templates from messages().
 *
 * Author-supplied `pattern` regexes are compiled with a fixed delimiter and flags and are anchored
 * like the HTML `pattern` attribute. They only ever run on a value already capped at the field's
 * maxLength (default 5,000 characters). A runtime PCRE error (backtrack limit and the like) makes the
 * value INVALID and is logged; a pattern that does not compile is ignored and logged.
 */
class Validator {

	/**
	 * Longest value any text field accepts when its author set no (or a larger) maxLength.
	 */
	const DEFAULT_MAX_LENGTH = 5000;

	/**
	 * Most values one multi-value field keeps before membership is checked.
	 */
	const MAX_LIST = 200;

	/**
	 * Backtrack limit while an author pattern runs.
	 */
	const PATTERN_BACKTRACK_LIMIT = 100000;

	/**
	 * Longest URL accepted.
	 */
	const MAX_URL_LENGTH = 2048;

	/**
	 * Compiled author patterns by pattern text; false for one that does not compile.
	 *
	 * @var array<string, string|false>
	 */
	private static $compiled = [];

	/**
	 * The error message templates by key, translated. Placeholders: {min}, {max}, {step}.
	 *
	 * The client (P6) receives this map through wp_interactivity_state() and mirrors the rules with it.
	 *
	 * @return array<string, string>
	 */
	public static function messages() {
		return [
			'required'    => __( 'This field is required.', 'swiftforms' ),
			'email'       => __( 'Please enter a valid email address.', 'swiftforms' ),
			'url'         => __( 'Please enter a valid web address starting with http:// or https://.', 'swiftforms' ),
			'number'      => __( 'Please enter a number.', 'swiftforms' ),
			'number_min'  => __( 'Please enter a number that is at least {min}.', 'swiftforms' ),
			'number_max'  => __( 'Please enter a number that is at most {max}.', 'swiftforms' ),
			'number_step' => __( 'Please enter a multiple of {step}.', 'swiftforms' ),
			'date'        => __( 'Please enter a valid date as YYYY-MM-DD.', 'swiftforms' ),
			'date_min'    => __( 'Please enter a date on or after {min}.', 'swiftforms' ),
			'date_max'    => __( 'Please enter a date on or before {max}.', 'swiftforms' ),
			'max_length'  => __( 'Please use at most {max} characters.', 'swiftforms' ),
			'min_length'  => __( 'Please use at least {min} characters.', 'swiftforms' ),
			'pattern'     => __( 'Please match the requested format.', 'swiftforms' ),
			'option'      => __( 'Please choose one of the available options.', 'swiftforms' ),
			'min_choices' => __( 'Please choose at least {min}.', 'swiftforms' ),
			'max_choices' => __( 'Please choose at most {max}.', 'swiftforms' ),
			'consent'     => __( 'Please tick this box to continue.', 'swiftforms' ),
			'invalid'     => __( 'Please check this field.', 'swiftforms' ),
		];
	}

	/**
	 * A message by key with its placeholders filled.
	 *
	 * @param string               $key  Message key.
	 * @param array<string, mixed> $vars Placeholder values by name (without braces).
	 * @return string
	 */
	public static function message( $key, array $vars = [] ) {
		$messages = self::messages();
		$text     = $messages[ $key ] ?? $messages['invalid'];
		$pairs    = [];
		foreach ( $vars as $name => $value ) {
			$pairs[ '{' . $name . '}' ] = (string) $value;
		}
		return strtr( $text, $pairs );
	}

	// ---------------------------------------------------------------------
	// Collecting.
	// ---------------------------------------------------------------------

	/**
	 * Clean values for the fields of a schema.
	 *
	 * @param Form_Schema          $schema Schema whose fields are collected.
	 * @param array<string, mixed> $posted The posted `sf` array (name => value).
	 * @param Submission_Context   $ctx    Context (source and user, for hidden fields).
	 * @return array<string, string|string[]> One key per field of the schema.
	 */
	public static function collect( Form_Schema $schema, array $posted, Submission_Context $ctx ) {
		$values = [];

		foreach ( $schema->fields as $field ) {
			$name = (string) $field['name'];
			$type = (string) $field['type'];

			if ( 'hidden' === $type ) {
				$values[ $name ] = self::clean_text( Hidden_Value::resolve( (string) ( $field['value'] ?? '' ), $ctx->source, $ctx->user_id() ), false );
				continue;
			}

			$raw = array_key_exists( $name, $posted ) ? $posted[ $name ] : null;

			if ( Field_Types::is_multi( $type, $field ) ) {
				$list = is_array( $raw ) ? $raw : ( null === $raw ? [] : [ $raw ] );
				$kept = [];
				foreach ( array_slice( array_values( $list ), 0, self::MAX_LIST ) as $item ) {
					$text = self::scalar_text( $item, false );
					if ( '' !== $text ) {
						$kept[ $text ] = $text;
					}
				}
				$values[ $name ] = array_values( $kept );
				continue;
			}

			if ( 'consent' === $type ) {
				$values[ $name ] = self::is_truthy( $raw ) ? '1' : '';
				continue;
			}

			$values[ $name ] = self::scalar_text( $raw, 'textarea' === $type );
		}

		return $values;
	}

	/**
	 * Clean text: valid UTF-8, no control characters, line endings normalised, trimmed. A single-line value has
	 * its line breaks and tabs turned into spaces.
	 *
	 * @param string $text      Text.
	 * @param bool   $multiline Whether line breaks are kept.
	 * @return string
	 */
	public static function clean_text( $text, $multiline ) {
		$text = self::valid_utf8( (string) $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		if ( ! $multiline ) {
			$text = (string) preg_replace( '/[\n\t]+/', ' ', $text );
		}
		return trim( $text );
	}

	/**
	 * The valid UTF-8 sequences of a string; invalid bytes are dropped (the same on every WordPress version).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function valid_utf8( $text ) {
		if ( 1 === preg_match( '//u', $text ) ) {
			return $text;
		}
		$sequence = '[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';
		return preg_match_all( '/' . $sequence . '/', $text, $m ) ? implode( '', $m[0] ) : '';
	}

	/**
	 * Text of a posted scalar; anything else (arrays, null, false) is the empty string.
	 *
	 * @param mixed $value     Posted value.
	 * @param bool  $multiline Whether line breaks are kept.
	 * @return string
	 */
	private static function scalar_text( $value, $multiline ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		return is_string( $value ) ? self::clean_text( $value, $multiline ) : '';
	}

	/**
	 * Whether a posted checkbox value means "ticked".
	 *
	 * @param mixed $value Posted value.
	 * @return bool
	 */
	private static function is_truthy( $value ) {
		if ( true === $value || 1 === $value ) {
			return true;
		}
		return is_string( $value ) && in_array( strtolower( trim( $value ) ), [ '1', 'on', 'true', 'yes' ], true );
	}

	// ---------------------------------------------------------------------
	// Validating.
	// ---------------------------------------------------------------------

	/**
	 * Validate collected values.
	 *
	 * @param Form_Schema                    $schema Schema the values were collected for.
	 * @param array<string, string|string[]> $values Values from collect().
	 * @param Submission_Context             $ctx    Context, passed to the swiftforms_validate_field filter.
	 * @return array<string, string> Field name => message. Empty when everything is valid.
	 */
	public static function validate( Form_Schema $schema, array $values, Submission_Context $ctx ) {
		$errors = [];

		foreach ( $schema->fields as $field ) {
			$name = (string) $field['name'];
			$type = (string) $field['type'];
			if ( 'hidden' === $type ) {
				continue;
			}

			$value = $values[ $name ] ?? ( Field_Types::is_multi( $type, $field ) ? [] : '' );
			$key   = self::check( $field, $value, $schema->form_id );
			$error = null === $key ? null : self::error_text( $key, $field );

			/**
			 * Filters the validation error of one field.
			 *
			 * Runs for every field except hidden ones, after SwiftForms' own rules. Return a message to
			 * make the field invalid, or null (or an empty string) to leave the result as it is. Return
			 * the received value unchanged to keep a built-in error. The message is shown to the visitor
			 * as plain text, so do not put the submitted value in it. The values are plain text, not
			 * HTML: escape them where you print them.
			 *
			 * @since 1.0.0
			 *
			 * @param string|null        $error   The error message from the built-in rules, or null.
			 * @param string|string[]    $value   The submitted value: a string, or a list of strings for multi-value fields.
			 * @param array<string, mixed> $field The field as resolved from the stored form (type, name, label, options, ...).
			 * @param Submission_Context $context The submission being validated. $context->values holds the values collected so far.
			 */
			$error = apply_filters( 'swiftforms_validate_field', $error, $value, $field, $ctx );

			if ( is_string( $error ) && '' !== $error ) {
				$errors[ $name ] = $error;
			}
		}

		return $errors;
	}

	/**
	 * The first failing rule of a field.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param string|string[]      $value   Collected value.
	 * @param string               $form_id Effective formId (for the log).
	 * @return string|null Message key, or null when the value is valid.
	 */
	public static function check( array $field, $value, $form_id = '' ) {
		$type = (string) $field['type'];

		if ( Field_Types::is_multi( $type, $field ) ) {
			$list = is_array( $value ) ? $value : [];
			return self::rule_required( $field, $list )
				?? self::rule_options( $field, $list )
				?? self::rule_choice_count( $field, $list );
		}

		$value = is_string( $value ) ? $value : '';

		if ( 'consent' === $type ) {
			return ( true === $field['required'] && '' === $value ) ? 'consent' : null;
		}

		$key = self::rule_required( $field, $value );
		if ( null !== $key ) {
			return $key;
		}
		if ( '' === $value ) {
			return null; // Optional and empty: no other rule applies.
		}

		// The length cap comes before anything that scans the value, in particular a pattern.
		$key = self::rule_max_length( $field, $value ) ?? self::rule_min_length( $field, $value );
		if ( null !== $key ) {
			return $key;
		}

		switch ( $type ) {
			case 'email':
				return self::rule_email( $value );
			case 'url':
				return self::rule_url( $value );
			case 'number':
				return self::rule_number( $field, $value );
			case 'date':
				return self::rule_date( $field, $value );
			case 'select':
			case 'radio':
				return self::rule_options( $field, [ $value ] );
			case 'text':
				return self::rule_pattern( $field, $value, $form_id );
		}
		return null;
	}

	/**
	 * Rule: a required field has a value.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string|string[]      $value Value.
	 * @return string|null
	 */
	public static function rule_required( array $field, $value ) {
		if ( true !== ( $field['required'] ?? false ) ) {
			return null;
		}
		return ( '' === $value || [] === $value ) ? 'required' : null;
	}

	/**
	 * The longest allowed length of a field: its maxLength, never above the default cap.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return int
	 */
	public static function max_length_of( array $field ) {
		$max = (int) ( $field['maxLength'] ?? 0 );
		return ( $max > 0 && $max < self::DEFAULT_MAX_LENGTH ) ? $max : self::DEFAULT_MAX_LENGTH;
	}

	/**
	 * Rule: not longer than maxLength (default 5,000 characters).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $value Value.
	 * @return string|null
	 */
	public static function rule_max_length( array $field, $value ) {
		return mb_strlen( $value, 'UTF-8' ) > self::max_length_of( $field ) ? 'max_length' : null;
	}

	/**
	 * Rule: at least minLength characters.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $value Value.
	 * @return string|null
	 */
	public static function rule_min_length( array $field, $value ) {
		$min = (int) ( $field['minLength'] ?? 0 );
		return ( $min > 0 && mb_strlen( $value, 'UTF-8' ) < $min ) ? 'min_length' : null;
	}

	/**
	 * Rule: a valid email address.
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	public static function rule_email( $value ) {
		return false === is_email( $value ) ? 'email' : null;
	}

	/**
	 * Rule: an http or https URL.
	 *
	 * Not wp_http_validate_url(): that function resolves the host name (a DNS lookup per submission) and
	 * rejects local and private addresses, which it does to protect outbound requests. Here the value is
	 * only stored and shown, never fetched.
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	public static function rule_url( $value ) {
		return self::is_http_url( $value ) ? null : 'url';
	}

	/**
	 * Whether a string is an absolute http or https URL with a host.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_http_url( $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > self::MAX_URL_LENGTH || 1 === preg_match( '/[\x00-\x20\x7F"<>\\\\^`{|}]/', $value ) ) {
			return false;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return false;
		}
		return 1 === preg_match( '/^(?:\[[0-9a-f:.]+\]|[^\s\/?#@:]+)$/iu', $parts['host'] );
	}

	/**
	 * Rule: a decimal number within min and max and on the step grid.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $value Value.
	 * @return string|null
	 */
	public static function rule_number( array $field, $value ) {
		if ( 1 !== preg_match( '/^-?(?:[0-9]+|[0-9]*\.[0-9]+)$/D', $value ) ) {
			return 'number';
		}
		$number = (float) $value;

		$min = self::number_attr( $field, 'min' );
		$max = self::number_attr( $field, 'max' );
		if ( null !== $min && $number < $min ) {
			return 'number_min';
		}
		if ( null !== $max && $number > $max ) {
			return 'number_max';
		}

		$step = self::number_attr( $field, 'step' );
		if ( null !== $step && $step > 0 ) {
			$base     = $min ?? 0.0;
			$quotient = ( $number - $base ) / $step;
			if ( abs( $quotient - round( $quotient ) ) > 1e-8 * max( 1.0, abs( $quotient ) ) ) {
				return 'number_step';
			}
		}
		return null;
	}

	/**
	 * A numeric field attribute as a float, or null when it is empty or not a number ("any" included).
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $key   Attribute name.
	 * @return float|null
	 */
	private static function number_attr( array $field, $key ) {
		$raw = isset( $field[ $key ] ) ? trim( (string) $field[ $key ] ) : '';
		return ( '' !== $raw && is_numeric( $raw ) ) ? (float) $raw : null;
	}

	/**
	 * Rule: a real calendar date as Y-m-d within min and max.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $value Value.
	 * @return string|null
	 */
	public static function rule_date( array $field, $value ) {
		if ( ! self::is_date( $value ) ) {
			return 'date';
		}
		$min = isset( $field['min'] ) ? trim( (string) $field['min'] ) : '';
		$max = isset( $field['max'] ) ? trim( (string) $field['max'] ) : '';
		if ( self::is_date( $min ) && $value < $min ) {
			return 'date_min';
		}
		if ( self::is_date( $max ) && $value > $max ) {
			return 'date_max';
		}
		return null;
	}

	/**
	 * Whether a string is a real date as Y-m-d.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_date( $value ) {
		return 1 === preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Rule: every value is one of the field's options (compared as whole strings; commas and pipes in an option value are just characters).
	 *
	 * @param array<string, mixed> $field  Field.
	 * @param string[]             $values Values to check (an empty string is skipped: it is "no choice").
	 * @return string|null
	 */
	public static function rule_options( array $field, array $values ) {
		$allowed = [];
		foreach ( (array) ( $field['options'] ?? [] ) as $option ) {
			if ( is_array( $option ) && isset( $option['value'] ) ) {
				$allowed[ (string) $option['value'] ] = true;
			}
		}
		foreach ( $values as $value ) {
			if ( '' !== $value && ! isset( $allowed[ $value ] ) ) {
				return 'option';
			}
		}
		return null;
	}

	/**
	 * Rule: minChoices and maxChoices of a checkbox group. minChoices applies once the visitor chose anything (an
	 * empty optional group is "no answer"); a required group is already refused when empty.
	 *
	 * @param array<string, mixed> $field  Field.
	 * @param string[]             $values Chosen values.
	 * @return string|null
	 */
	public static function rule_choice_count( array $field, array $values ) {
		$count = count( $values );
		$min   = (int) ( $field['minChoices'] ?? 0 );
		$max   = (int) ( $field['maxChoices'] ?? 0 );
		if ( $min > 0 && $count > 0 && $count < $min ) {
			return 'min_choices';
		}
		if ( $max > 0 && $count > $max ) {
			return 'max_choices';
		}
		return null;
	}

	/**
	 * Rule: the author's pattern. Fixed delimiter and flags, anchored like the HTML pattern attribute.
	 *
	 * @param array<string, mixed> $field   Field.
	 * @param string               $value   Value (already within maxLength).
	 * @param string               $form_id Effective formId (for the log).
	 * @return string|null
	 */
	public static function rule_pattern( array $field, $value, $form_id = '' ) {
		$pattern = isset( $field['pattern'] ) ? (string) $field['pattern'] : '';
		if ( '' === $pattern ) {
			return null;
		}

		$regex = self::compile( $pattern );
		if ( false === $regex ) {
			Log::add( 'error', 'warning', 'A field pattern does not compile; it is ignored.', [ 'field' => (string) ( $field['name'] ?? '' ) ], $form_id );
			return null;
		}

		$matched = self::run( $regex, $value );
		if ( null === $matched ) {
			Log::add( 'error', 'warning', 'A field pattern failed at run time (PCRE error); the value counts as invalid.', [ 'field' => (string) ( $field['name'] ?? '' ) ], $form_id );
			return 'pattern';
		}
		return $matched ? null : 'pattern';
	}

	/**
	 * Compile an author pattern into a full regular expression.
	 *
	 * @param string $pattern The pattern as the author typed it (what the HTML pattern attribute takes).
	 * @return string|false The regex, or false when it does not compile.
	 */
	public static function compile( $pattern ) {
		if ( isset( self::$compiled[ $pattern ] ) || array_key_exists( $pattern, self::$compiled ) ) {
			return self::$compiled[ $pattern ];
		}

		// Escape every unescaped delimiter; an author's "\#" stays as it is.
		$escaped = '';
		$length  = strlen( $pattern );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '\\' === $char && $i + 1 < $length ) {
				$escaped .= $char . $pattern[ ++$i ];
			} elseif ( '#' === $char ) {
				$escaped .= '\\#';
			} else {
				$escaped .= $char;
			}
		}

		$regex = '#^(?:' . $escaped . ')$#uD';
		$ok    = self::run( $regex, '' );

		self::$compiled[ $pattern ] = null === $ok ? false : $regex;
		return self::$compiled[ $pattern ];
	}

	/**
	 * Run a regex under a bounded backtrack limit, without letting PHP warnings escape.
	 *
	 * @param string $regex Full regular expression.
	 * @param string $value Subject.
	 * @return bool|null True or false for a match result, null for a PCRE error (compile or run time).
	 */
	private static function run( $regex, $value ) {
		$previous_limit = ini_get( 'pcre.backtrack_limit' );
		$limit          = is_numeric( $previous_limit ) ? min( (int) $previous_limit, self::PATTERN_BACKTRACK_LIMIT ) : self::PATTERN_BACKTRACK_LIMIT;

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- A bad author pattern must not raise a warning.
			static function () {
				return true;
			}
		);
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Bounds the work an author pattern may do; restored below.
		ini_set( 'pcre.backtrack_limit', (string) $limit );
		try {
			$result = preg_match( $regex, $value );
			$error  = preg_last_error();
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value above.
			ini_set( 'pcre.backtrack_limit', false === $previous_limit ? '1000000' : (string) $previous_limit );
			restore_error_handler();
		}

		if ( false === $result || PREG_NO_ERROR !== $error ) {
			return null;
		}
		return 1 === $result;
	}

	/**
	 * Message text for a rule key of a field.
	 *
	 * The pattern rule uses the field's own patternMessage when the author wrote one.
	 *
	 * @param string               $key   Message key.
	 * @param array<string, mixed> $field Field.
	 * @return string
	 */
	private static function error_text( $key, array $field ) {
		if ( 'pattern' === $key && ! empty( $field['patternMessage'] ) && is_string( $field['patternMessage'] ) ) {
			return $field['patternMessage'];
		}
		return self::message(
			$key,
			[
				'min'  => self::limit_text( $key, $field ),
				'max'  => self::limit_text( $key, $field ),
				'step' => (string) ( $field['step'] ?? '' ),
			]
		);
	}

	/**
	 * The limit a message names (min or max of the rule that failed).
	 *
	 * @param string               $key   Message key.
	 * @param array<string, mixed> $field Field.
	 * @return string
	 */
	private static function limit_text( $key, array $field ) {
		switch ( $key ) {
			case 'max_length':
				return (string) self::max_length_of( $field );
			case 'min_length':
				return (string) (int) ( $field['minLength'] ?? 0 );
			case 'min_choices':
				return (string) (int) ( $field['minChoices'] ?? 0 );
			case 'max_choices':
				return (string) (int) ( $field['maxChoices'] ?? 0 );
			case 'number_min':
			case 'date_min':
				return (string) ( $field['min'] ?? '' );
			case 'number_max':
			case 'date_max':
				return (string) ( $field['max'] ?? '' );
		}
		return '';
	}
}
