<?php
/**
 * Tests for conditional logic evaluation.
 */

declare(strict_types=1);

class SwiftForms_Conditions_Test extends WP_UnitTestCase {
	private function make_conditions( array $groups, string $action = 'show' ): array {
		return array(
			'action' => $action,
			'groups' => $groups,
		);
	}

	public function test_sanitize_returns_empty_for_disabled(): void {
		$raw = array(
			'enabled' => false,
			'action'  => 'show',
			'groups'  => array(
				array(
					array(
						'field'    => 'topic',
						'operator' => 'equals',
						'value'    => 'x',
					),
				),
			),
		);

		$this->assertSame( array(), SwiftForms_Conditions::sanitize( $raw ) );
		$this->assertSame( array(), SwiftForms_Conditions::sanitize( 'nonsense' ) );
		$this->assertSame( array(), SwiftForms_Conditions::sanitize( array() ) );
	}

	public function test_sanitize_whitelists_operators(): void {
		$raw = array(
			'enabled' => true,
			'action'  => 'show',
			'groups'  => array(
				array(
					array(
						'field'    => 'topic',
						'operator' => 'equals',
						'value'    => 'x',
					),
					array(
						'field'    => 'topic',
						'operator' => 'regex_match',
						'value'    => '.*',
					),
				),
			),
		);

		$sanitized = SwiftForms_Conditions::sanitize( $raw );

		$this->assertCount( 1, $sanitized['groups'][0] );
		$this->assertSame( 'equals', $sanitized['groups'][0][0]['operator'] );
	}

	public function test_sanitize_drops_malformed_rules_and_defaults_action_to_show(): void {
		$raw = array(
			'enabled' => true,
			'action'  => 'explode',
			'groups'  => array(
				array(
					array(
						'field'    => '',
						'operator' => 'equals',
						'value'    => 'x',
					),
					'not-a-rule',
					array(
						'field'    => 'Topic Slug!',
						'operator' => 'equals',
						'value'    => "  <b>x</b>\n",
					),
				),
				'not-a-group',
				array(),
			),
		);

		$sanitized = SwiftForms_Conditions::sanitize( $raw );

		$this->assertSame( 'show', $sanitized['action'] );
		$this->assertCount( 1, $sanitized['groups'] );
		$this->assertSame( 'topicslug', $sanitized['groups'][0][0]['field'] );
		$this->assertSame( 'x', $sanitized['groups'][0][0]['value'] );
	}

	public function test_evaluate_rule_equals_is_case_sensitive_and_trims(): void {
		$rule = array(
			'field'    => 'topic',
			'operator' => 'equals',
			'value'    => 'Sales',
		);

		$this->assertTrue( SwiftForms_Conditions::evaluate_rule( $rule, array( 'topic' => '  Sales ' ) ) );
		$this->assertFalse( SwiftForms_Conditions::evaluate_rule( $rule, array( 'topic' => 'sales' ) ) );
		$this->assertFalse( SwiftForms_Conditions::evaluate_rule( $rule, array() ) );
	}

	public function test_evaluate_rule_contains_is_case_insensitive(): void {
		$rule = array(
			'field'    => 'message',
			'operator' => 'contains',
			'value'    => 'URGENT',
		);

		$this->assertTrue( SwiftForms_Conditions::evaluate_rule( $rule, array( 'message' => 'this is urgent please' ) ) );
		$this->assertFalse( SwiftForms_Conditions::evaluate_rule( $rule, array( 'message' => 'calm request' ) ) );
	}

	public function test_evaluate_rule_empty_operators(): void {
		$empty     = array(
			'field'    => 'phone',
			'operator' => 'empty',
			'value'    => '',
		);
		$not_empty = array(
			'field'    => 'phone',
			'operator' => 'not_empty',
			'value'    => '',
		);

		$this->assertTrue( SwiftForms_Conditions::evaluate_rule( $empty, array( 'phone' => '   ' ) ) );
		$this->assertTrue( SwiftForms_Conditions::evaluate_rule( $empty, array() ) );
		$this->assertFalse( SwiftForms_Conditions::evaluate_rule( $not_empty, array( 'phone' => '' ) ) );
		$this->assertTrue( SwiftForms_Conditions::evaluate_rule( $not_empty, array( 'phone' => '123' ) ) );
	}

	public function test_is_field_visible_ands_rules_within_group(): void {
		$conditions = $this->make_conditions(
			array(
				array(
					array(
						'field'    => 'topic',
						'operator' => 'equals',
						'value'    => 'support',
					),
					array(
						'field'    => 'email',
						'operator' => 'not_empty',
						'value'    => '',
					),
				),
			)
		);

		$this->assertTrue(
			SwiftForms_Conditions::is_field_visible(
				$conditions,
				array(
					'topic' => 'support',
					'email' => 'a@b.c',
				)
			)
		);
		$this->assertFalse(
			SwiftForms_Conditions::is_field_visible(
				$conditions,
				array(
					'topic' => 'support',
					'email' => '',
				)
			)
		);
	}

	public function test_is_field_visible_ors_groups(): void {
		$conditions = $this->make_conditions(
			array(
				array(
					array(
						'field'    => 'topic',
						'operator' => 'equals',
						'value'    => 'support',
					),
				),
				array(
					array(
						'field'    => 'topic',
						'operator' => 'equals',
						'value'    => 'sales',
					),
				),
			)
		);

		$this->assertTrue( SwiftForms_Conditions::is_field_visible( $conditions, array( 'topic' => 'sales' ) ) );
		$this->assertFalse( SwiftForms_Conditions::is_field_visible( $conditions, array( 'topic' => 'billing' ) ) );
	}

	public function test_is_field_visible_hide_action_inverts(): void {
		$conditions = $this->make_conditions(
			array(
				array(
					array(
						'field'    => 'topic',
						'operator' => 'equals',
						'value'    => 'spammy',
					),
				),
			),
			'hide'
		);

		$this->assertFalse( SwiftForms_Conditions::is_field_visible( $conditions, array( 'topic' => 'spammy' ) ) );
		$this->assertTrue( SwiftForms_Conditions::is_field_visible( $conditions, array( 'topic' => 'normal' ) ) );
	}

	public function test_is_field_visible_without_groups_is_visible(): void {
		$this->assertTrue( SwiftForms_Conditions::is_field_visible( array(), array() ) );
	}

	public function test_compute_visibility_resolves_chained_conditions(): void {
		// c shows when b is non-empty; b shows when a equals 'yes'. Hiding b
		// must therefore hide c too, even though c's rule looks only at b.
		$schema = array(
			'a' => array( 'conditions' => array() ),
			'b' => array(
				'conditions' => $this->make_conditions(
					array(
						array(
							array(
								'field'    => 'a',
								'operator' => 'equals',
								'value'    => 'yes',
							),
						),
					)
				),
			),
			'c' => array(
				'conditions' => $this->make_conditions(
					array(
						array(
							array(
								'field'    => 'b',
								'operator' => 'not_empty',
								'value'    => '',
							),
						),
					)
				),
			),
		);

		$visible = SwiftForms_Conditions::compute_visibility(
			$schema,
			array(
				'a' => 'no',
				'b' => 'filled before a changed',
				'c' => 'whatever',
			)
		);

		$this->assertTrue( $visible['a'] );
		$this->assertFalse( $visible['b'] );
		$this->assertFalse( $visible['c'], 'A field hidden by the chain must contribute an empty value to dependents.' );

		$visible = SwiftForms_Conditions::compute_visibility(
			$schema,
			array(
				'a' => 'yes',
				'b' => 'filled',
				'c' => 'whatever',
			)
		);

		$this->assertTrue( $visible['b'] );
		$this->assertTrue( $visible['c'] );
	}

	public function test_compute_visibility_terminates_on_circular_conditions(): void {
		// a shows when b is empty; b shows when a is empty. Both filled makes
		// this contradictory — the pass cap must settle it without hanging.
		$schema = array(
			'a' => array(
				'conditions' => $this->make_conditions(
					array(
						array(
							array(
								'field'    => 'b',
								'operator' => 'empty',
								'value'    => '',
							),
						),
					)
				),
			),
			'b' => array(
				'conditions' => $this->make_conditions(
					array(
						array(
							array(
								'field'    => 'a',
								'operator' => 'empty',
								'value'    => '',
							),
						),
					)
				),
			),
		);

		$visible = SwiftForms_Conditions::compute_visibility(
			$schema,
			array(
				'a' => 'x',
				'b' => 'y',
			)
		);

		$this->assertIsBool( $visible['a'] );
		$this->assertIsBool( $visible['b'] );
	}
}
