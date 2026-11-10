<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Included by the loader: the variables are local to the include, and the snippet's names are test fixtures.
/**
 * Config 20 snippet: a developer's site that uses every documented v1 hook (C-8.3).
 *
 * Actions: swiftforms_entry_saved, swiftforms_mail_sent, swiftforms_submission_complete.
 * Filters: swiftforms_validate_field, swiftforms_spam_check, swiftforms_before_send_mail, swiftforms_merge_tags,
 * swiftforms_render_form, swiftforms_capability_map, swiftforms_client_ip, swiftforms_throttle_limit.
 *
 * Every callback records that it ran (the global $sf_user_config_hook_log: hook => calls and the types of its arguments,
 * dumped by the check "hooks"). Most do nothing else; the ones that change something do it only for values the
 * config's payloads and the check supply:
 * - validate_field rejects the value "forbidden-by-hook" in a field named "code";
 * - spam_check calls any submission with "hook-spam" in a value spam;
 * - before_send_mail adds the header X-Power-User and a subject prefix;
 * - merge_tags adds {power:greeting};
 * - render_form adds an HTML comment after the form;
 * - client_ip answers with the X-SF-Client header when the check sets $_SERVER['HTTP_X_SF_CLIENT'];
 * - throttle_limit answers with $GLOBALS['sf_user_config_throttle'] when the check sets it.
 *
 * @package SwiftForms
 */

$GLOBALS['sf_user_config_hook_log'] = [];

/**
 * Record that a hook ran, with the types of its arguments.
 *
 * @param string $hook Hook name.
 * @param array  $args The arguments it received.
 */
function sf_user_config_record_hook( $hook, array $args ) {
	$types = [];
	foreach ( $args as $arg ) {
		$types[] = is_object( $arg ) ? get_class( $arg ) : gettype( $arg );
	}
	if ( ! isset( $GLOBALS['sf_user_config_hook_log'][ $hook ] ) ) {
		$GLOBALS['sf_user_config_hook_log'][ $hook ] = [
			'calls' => 0,
			'types' => [],
		];
	}
	++$GLOBALS['sf_user_config_hook_log'][ $hook ]['calls'];
	$GLOBALS['sf_user_config_hook_log'][ $hook ]['types'][ implode( ', ', $types ) ] = true;
}

add_action(
	'swiftforms_entry_saved',
	static function ( $entry_id, $context ) {
		sf_user_config_record_hook( 'swiftforms_entry_saved', [ $entry_id, $context ] );
	},
	10,
	2
);

add_action(
	'swiftforms_mail_sent',
	static function ( $sent, $mail, $context ) {
		sf_user_config_record_hook( 'swiftforms_mail_sent', [ $sent, $mail, $context ] );
	},
	10,
	3
);

add_action(
	'swiftforms_submission_complete',
	static function ( $context, $result ) {
		sf_user_config_record_hook( 'swiftforms_submission_complete', [ $context, $result ] );
	},
	10,
	2
);

add_filter(
	'swiftforms_validate_field',
	static function ( $error, $value, $field, $context ) {
		sf_user_config_record_hook( 'swiftforms_validate_field', [ $error, $value, $field, $context ] );
		if ( 'code' === ( $field['name'] ?? '' ) && 'forbidden-by-hook' === $value ) {
			return 'This code is not accepted by the site.';
		}
		return $error;
	},
	10,
	4
);

add_filter(
	'swiftforms_spam_check',
	static function ( $verdict, $context ) {
		sf_user_config_record_hook( 'swiftforms_spam_check', [ $verdict, $context ] );
		foreach ( (array) $context->values as $value ) {
			if ( false !== strpos( implode( ' ', (array) $value ), 'hook-spam' ) ) {
				return 'power-user-rule';
			}
		}
		return $verdict;
	},
	10,
	2
);

add_filter(
	'swiftforms_before_send_mail',
	static function ( $mail, $context, $notification ) {
		sf_user_config_record_hook( 'swiftforms_before_send_mail', [ $mail, $context, $notification ] );
		$mail['subject']   = '[power] ' . $mail['subject'];
		$mail['headers'][] = 'X-Power-User: 1';
		return $mail;
	},
	10,
	3
);

add_filter(
	'swiftforms_merge_tags',
	static function ( $tags, $context, $mode ) {
		sf_user_config_record_hook( 'swiftforms_merge_tags', [ $tags, $context, $mode ] );
		$tags['power:greeting'] = 'Hello from the hook';
		return $tags;
	},
	10,
	3
);

add_filter(
	'swiftforms_render_form',
	static function ( $html, $form_id, $instance, $attributes ) {
		sf_user_config_record_hook( 'swiftforms_render_form', [ $html, $form_id, $instance, $attributes ] );
		return $html . '<!-- power-user: form instance ' . (int) $instance . ' -->';
	},
	10,
	4
);

add_filter(
	'swiftforms_capability_map',
	static function ( $map ) {
		sf_user_config_record_hook( 'swiftforms_capability_map', [ $map ] );
		return $map;
	}
);

add_filter(
	'swiftforms_client_ip',
	static function ( $ip ) {
		sf_user_config_record_hook( 'swiftforms_client_ip', [ $ip ] );
		if ( ! empty( $_SERVER['HTTP_X_SF_CLIENT'] ) ) {
			return (string) $_SERVER['HTTP_X_SF_CLIENT'];
		}
		return $ip;
	}
);

add_filter(
	'swiftforms_throttle_limit',
	static function ( $limit ) {
		sf_user_config_record_hook( 'swiftforms_throttle_limit', [ $limit ] );
		if ( isset( $GLOBALS['sf_user_config_throttle'] ) ) {
			return $GLOBALS['sf_user_config_throttle'];
		}
		return $limit;
	}
);
