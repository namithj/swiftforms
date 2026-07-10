<?php
/**
 * Conditional logic evaluation.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * Sanitizes and evaluates per-field conditional visibility rules.
 *
 * A field's `conditions` attribute holds OR-ed groups of AND-ed rules.
 * The same semantics are implemented in the frontend view script; the two
 * implementations must stay in lockstep (see docs in .vscode/ROUND_A spec):
 * equals/not_equals compare trimmed strings case-sensitively, contains is a
 * case-insensitive substring match, empty/not_empty compare the trimmed
 * value against ''. A slug missing from the value map evaluates as ''.
 */
class SwiftForms_Conditions {

	/**
	 * Operators a rule may use.
	 */
	public const OPERATORS = array( 'equals', 'not_equals', 'contains', 'empty', 'not_empty' );

	/**
	 * Maximum fixed-point iterations when resolving chained conditions.
	 *
	 * Mirrored by the frontend engine so both sides settle circular chains
	 * identically.
	 */
	public const MAX_PASSES = 10;

	/**
	 * Normalizes a raw conditions attribute into the canonical shape.
	 *
	 * Returns an empty array when the attribute is disabled, empty, or
	 * malformed — an empty array always means "no conditions, keep visible".
	 *
	 * @param mixed $raw Raw block attribute value.
	 *
	 * @return array{action?: string, groups?: array<int, array<int, array{field: string, operator: string, value: string}>>}
	 */
	public static function sanitize( mixed $raw ): array {
		if ( ! is_array( $raw ) || empty( $raw['enabled'] ) || ! is_array( $raw['groups'] ?? null ) ) {
			return array();
		}

		$groups = array();

		foreach ( $raw['groups'] as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$rules = array();

			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				$field    = sanitize_key( (string) ( $rule['field'] ?? '' ) );
				$operator = (string) ( $rule['operator'] ?? '' );

				if ( '' === $field || ! in_array( $operator, self::OPERATORS, true ) ) {
					continue;
				}

				$rules[] = array(
					'field'    => $field,
					'operator' => $operator,
					'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				);
			}

			if ( ! empty( $rules ) ) {
				$groups[] = $rules;
			}
		}

		if ( empty( $groups ) ) {
			return array();
		}

		return array(
			'action' => 'hide' === ( $raw['action'] ?? '' ) ? 'hide' : 'show',
			'groups' => $groups,
		);
	}

	/**
	 * Evaluates a single sanitized rule against the field value map.
	 *
	 * @param array<string, string> $rule   Sanitized rule (field/operator/value).
	 * @param array<string, string> $values Field values keyed by slug.
	 */
	public static function evaluate_rule( array $rule, array $values ): bool {
		$actual   = trim( (string) ( $values[ $rule['field'] ?? '' ] ?? '' ) );
		$expected = trim( (string) ( $rule['value'] ?? '' ) );

		switch ( $rule['operator'] ?? '' ) {
			case 'equals':
				return $actual === $expected;
			case 'not_equals':
				return $actual !== $expected;
			case 'contains':
				return '' !== $expected && false !== stripos( $actual, $expected );
			case 'empty':
				return '' === $actual;
			case 'not_empty':
				return '' !== $actual;
		}

		return false;
	}

	/**
	 * Returns whether a field with the given conditions should be visible.
	 *
	 * Rules within a group are AND-ed; groups are OR-ed. A 'hide' action
	 * inverts the result: the field hides when the groups match.
	 *
	 * @param array<string, mixed>  $conditions Sanitized conditions (see sanitize()).
	 * @param array<string, string> $values     Field values keyed by slug.
	 */
	public static function is_field_visible( array $conditions, array $values ): bool {
		$groups = $conditions['groups'] ?? array();

		if ( empty( $groups ) || ! is_array( $groups ) ) {
			return true;
		}

		$matched = false;

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group ) ) {
				continue;
			}

			$group_matched = true;

			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) || ! self::evaluate_rule( $rule, $values ) ) {
					$group_matched = false;
					break;
				}
			}

			if ( $group_matched ) {
				$matched = true;
				break;
			}
		}

		return 'hide' === ( $conditions['action'] ?? 'show' ) ? ! $matched : $matched;
	}

	/**
	 * Computes visibility for every field in a form schema.
	 *
	 * Conditions can chain (field A shows field B which shows field C), so
	 * visibility is resolved by fixed-point iteration: a field hidden in one
	 * pass contributes an empty value to the next, up to MAX_PASSES passes.
	 * All fields start visible, which also guarantees circular chains settle.
	 *
	 * @param array<string, array<string, mixed>> $schema Field schema keyed by slug (entries may carry 'conditions').
	 * @param array<string, string>               $values Submitted scalar values keyed by slug.
	 *
	 * @return array<string, bool> Visibility keyed by slug.
	 */
	public static function compute_visibility( array $schema, array $values ): array {
		$visibility = array_fill_keys( array_keys( $schema ), true );

		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$effective_values = array();

			foreach ( $values as $slug => $value ) {
				$effective_values[ $slug ] = ( $visibility[ $slug ] ?? true ) ? $value : '';
			}

			$changed = false;

			foreach ( $schema as $slug => $config ) {
				$conditions = is_array( $config['conditions'] ?? null ) ? $config['conditions'] : array();
				$visible    = empty( $conditions ) || self::is_field_visible( $conditions, $effective_values );

				if ( $visible !== $visibility[ $slug ] ) {
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
}
