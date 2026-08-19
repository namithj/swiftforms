<?php
/**
 * Tests for SwiftForms\Conditions.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests;

use SwiftForms\Conditions;

final class ConditionsTest extends TestCase {

	/**
	 * Runs every case in tests/fixtures/conditions.json#isFieldVisible —
	 * the same fixture Jest runs against src/shared/conditions.js, so PHP
	 * and JS can never silently diverge in what "visible" means.
	 */
	public function test_is_field_visible_matches_shared_fixtures(): void {
		$cases = $this->fixture( 'conditions.json' )['isFieldVisible'];

		foreach ( $cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Conditions::is_field_visible( $case['conditions'], $case['values'] ),
				'Failed fixture case: ' . $case['name']
			);
		}
	}

	/**
	 * Runs every case in tests/fixtures/conditions.json#resolveVisibility.
	 */
	public function test_resolve_visibility_matches_shared_fixtures(): void {
		$cases = $this->fixture( 'conditions.json' )['resolveVisibility'];

		foreach ( $cases as $case ) {
			$this->assertSame(
				$case['expected'],
				Conditions::resolve_visibility( $case['fields'], $case['values'] ),
				'Failed fixture case: ' . $case['name']
			);
		}
	}

	public function test_resolve_visibility_terminates_on_circular_conditions(): void {
		$fields = array(
			'a' => array(
				'conditions' => array(
					'enabled' => true,
					'action'  => 'show',
					'groups'  => array(
						array(
							array(
								'field'    => 'b',
								'operator' => 'equals',
								'value'    => 'x',
							),
						),
					),
				),
			),
			'b' => array(
				'conditions' => array(
					'enabled' => true,
					'action'  => 'show',
					'groups'  => array(
						array(
							array(
								'field'    => 'a',
								'operator' => 'equals',
								'value'    => 'x',
							),
						),
					),
				),
			),
		);

		$result = Conditions::resolve_visibility( $fields, array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'a', $result );
		$this->assertArrayHasKey( 'b', $result );
	}

	public function test_sanitize_drops_rules_missing_a_field(): void {
		$sanitized = Conditions::sanitize(
			array(
				'enabled' => true,
				'action'  => 'show',
				'groups'  => array(
					array(
						array(
							'operator' => 'equals',
							'value'    => 'x',
						), // no "field" -> dropped.
						array(
							'field'    => 'country',
							'operator' => 'bogus_operator',
							'value'    => 'us',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $sanitized['groups'][0] );
		$this->assertSame( 'equals', $sanitized['groups'][0][0]['operator'] );
	}

	public function test_sanitize_disables_when_no_valid_groups_remain(): void {
		$sanitized = Conditions::sanitize(
			array(
				'enabled' => true,
				'action'  => 'show',
				'groups'  => array(
					array(
						array(
							'operator' => 'equals',
							'value'    => 'x',
						),
					),
				),
			)
		);

		$this->assertFalse( $sanitized['enabled'] );
		$this->assertSame( array(), $sanitized['groups'] );
	}
}
