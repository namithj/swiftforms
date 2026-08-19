<?php
/**
 * Conditional field visibility engine.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

/**
 * Pure evaluation logic, mirrored exactly in `src/shared/conditions.js` —
 * both implementations are exercised against the same fixture file
 * (tests/fixtures/conditions.json) to guarantee they never drift apart.
 *
 * A field's `conditions` attribute looks like:
 *
 *     {
 *       "enabled": true,
 *       "action": "show" | "hide",
 *       "groups": [
 *         [ { "field": "country", "operator": "equals", "value": "us" }, … ],  // AND within a group
 *         …                                                                    // OR across groups
 *       ]
 *     }
 */
final class Conditions {

	public const MAX_PASSES = 10;

	private const OPERATORS = array( 'equals', 'not_equals', 'contains', 'empty', 'not_empty' );

	/**
	 * Whether one field should be visible given the current known values.
	 *
	 * @param array<string, mixed> $conditions Field's `conditions` attribute.
	 * @param array<string, mixed> $values     Slug => current value.
	 */
	public static function is_field_visible( array $conditions, array $values ): bool {
		if ( empty( $conditions['enabled'] ) || empty( $conditions['groups'] ) ) {
			return true;
		}

		$matches = self::evaluate_groups( (array) $conditions['groups'], $values );
		$action  = $conditions['action'] ?? 'show';

		return 'hide' === $action ? ! $matches : $matches;
	}

	/**
	 * Resolves visibility for every field in one pass, taking into account
	 * that a hidden field's value doesn't count toward other rules — run
	 * repeatedly (fixed-point) since conditions can chain (A hides B, B's
	 * visibility affects C).
	 *
	 * @param array<string, array<string, mixed>> $fields Slug => { conditions, ... }.
	 * @param array<string, mixed>                $values Slug => submitted value.
	 * @return array<string, bool> Slug => visible.
	 */
	public static function resolve_visibility( array $fields, array $values ): array {
		$visibility = array_fill_keys( array_keys( $fields ), true );

		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$effective_values = $values;

			foreach ( $visibility as $slug => $visible ) {
				if ( ! $visible ) {
					$effective_values[ $slug ] = '';
				}
			}

			$changed = false;

			foreach ( $fields as $slug => $field ) {
				$conditions = (array) ( $field['conditions'] ?? array() );
				$visible    = self::is_field_visible( $conditions, $effective_values );

				if ( $visibility[ $slug ] !== $visible ) {
					$visibility[ $slug ] = $visible;
					$changed             = true;
				}
			}

			if ( ! $changed ) {
				break;
			}
		}

		return $visibility;
	}

	/**
	 * OR across groups, AND within a group.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $groups Groups of rules.
	 * @param array<string, mixed>                          $values Slug => value.
	 */
	private static function evaluate_groups( array $groups, array $values ): bool {
		if ( ! $groups ) {
			return false;
		}

		foreach ( $groups as $group ) {
			if ( self::evaluate_group( (array) $group, $values ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * All rules in a group must pass.
	 *
	 * @param array<int, array<string, mixed>> $rules  Rules.
	 * @param array<string, mixed>             $values Slug => value.
	 */
	private static function evaluate_group( array $rules, array $values ): bool {
		if ( ! $rules ) {
			return false;
		}

		foreach ( $rules as $rule ) {
			if ( ! self::evaluate_rule( (array) $rule, $values ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Evaluates a single rule against the current values.
	 *
	 * @param array<string, mixed> $rule   { field, operator, value }.
	 * @param array<string, mixed> $values Slug => value.
	 */
	private static function evaluate_rule( array $rule, array $values ): bool {
		$field    = (string) ( $rule['field'] ?? '' );
		$operator = in_array( $rule['operator'] ?? '', self::OPERATORS, true ) ? $rule['operator'] : 'equals';
		$expected = (string) ( $rule['value'] ?? '' );
		$actual   = (string) ( $values[ $field ] ?? '' );

		return match ( $operator ) {
			'equals'     => trim( $actual ) === trim( $expected ),
			'not_equals' => trim( $actual ) !== trim( $expected ),
			'contains'   => '' !== $expected && false !== stripos( $actual, $expected ),
			'empty'      => '' === trim( $actual ),
			'not_empty'  => '' !== trim( $actual ),
			default      => false,
		};
	}

	/**
	 * Sanitizes a raw `conditions` attribute value into the canonical shape.
	 *
	 * @param mixed $raw Raw value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();

		$action = ( $raw['action'] ?? 'show' ) === 'hide' ? 'hide' : 'show';
		$groups = array();

		foreach ( (array) ( $raw['groups'] ?? array() ) as $group ) {
			$clean_rules = array();

			foreach ( (array) $group as $rule ) {
				if ( empty( $rule['field'] ) ) {
					continue;
				}

				$clean_rules[] = array(
					'field'    => sanitize_key( (string) $rule['field'] ),
					'operator' => in_array( $rule['operator'] ?? '', self::OPERATORS, true ) ? $rule['operator'] : 'equals',
					'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				);
			}

			if ( $clean_rules ) {
				$groups[] = $clean_rules;
			}
		}

		return array(
			'enabled' => ! empty( $raw['enabled'] ) && $groups,
			'action'  => $action,
			'groups'  => $groups,
		);
	}
}
