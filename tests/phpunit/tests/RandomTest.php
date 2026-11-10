<?php
/**
 * Tests for SwiftForms\Random.
 *
 * @package SwiftForms
 */

use SwiftForms\Random;

/**
 * Class RandomTest
 */
class RandomTest extends WP_UnitTestCase {

	/**
	 * Always back to real randomness.
	 *
	 * @return void
	 */
	public function tear_down() {
		Random::unseed();
		parent::tear_down();
	}

	/**
	 * Seeding makes uuid4() and int() repeatable.
	 *
	 * @return void
	 */
	public function test_seed_makes_uuid4_and_int_deterministic() {
		Random::seed( 20261119 );
		$first = [ Random::uuid4(), Random::int( 1, 1000000 ), Random::uuid4() ];

		Random::seed( 20261119 );
		$second = [ Random::uuid4(), Random::int( 1, 1000000 ), Random::uuid4() ];

		$this->assertSame( $first, $second );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first[0] );
	}

	/**
	 * Bytes and tokens are never deterministic, even when seeded.
	 *
	 * @return void
	 */
	public function test_bytes_and_token_ignore_the_seed() {
		Random::seed( 7 );
		$bytes_a = Random::bytes( 16 );
		$token_a = Random::token();

		Random::seed( 7 );
		$bytes_b = Random::bytes( 16 );
		$token_b = Random::token();

		$this->assertSame( 16, strlen( $bytes_a ) );
		$this->assertSame( 32, strlen( $token_a ) );
		$this->assertNotSame( $bytes_a, $bytes_b );
		$this->assertNotSame( $token_a, $token_b );
	}

	/**
	 * Without a seed, UUIDs differ.
	 *
	 * @return void
	 */
	public function test_unseeded_uuid4_is_random() {
		$this->assertNotSame( Random::uuid4(), Random::uuid4() );
	}
}
