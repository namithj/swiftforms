<?php
/**
 * Randomness seam.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Random
 *
 * Security values (tokens, keys) always come from random_bytes() and can never
 * be seeded. Only uuid4() and int() can be made deterministic, and only in
 * tests, so snapshots stay stable.
 */
class Random {

	/**
	 * Whether the deterministic sequence is active.
	 *
	 * @var bool
	 */
	private static $seeded = false;

	/**
	 * Cryptographically secure random bytes. Never seeded.
	 *
	 * @param int $length Number of bytes.
	 * @return string
	 */
	public static function bytes( $length ) {
		return random_bytes( max( 1, (int) $length ) );
	}

	/**
	 * Random hex token. Never seeded.
	 *
	 * @param int $bytes Number of random bytes (the token is twice as long).
	 * @return string
	 */
	public static function token( $bytes = 16 ) {
		return bin2hex( self::bytes( $bytes ) );
	}

	/**
	 * Random UUID v4.
	 *
	 * For form IDs only. Never use it for a security value: when tests seed
	 * this class it becomes predictable.
	 *
	 * @return string
	 */
	public static function uuid4() {
		if ( ! self::$seeded ) {
			return wp_generate_uuid4();
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand -- A seedable generator is the point of the test seam.
		$uuid = sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff )
		);
		// phpcs:enable WordPress.WP.AlternativeFunctions.rand_mt_rand

		return $uuid;
	}

	/**
	 * Random integer in an inclusive range.
	 *
	 * @param int $min Lowest value.
	 * @param int $max Highest value.
	 * @return int
	 */
	public static function int( $min, $max ) {
		if ( self::$seeded ) {
			return mt_rand( (int) $min, (int) $max ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Seeded test sequence.
		}
		return random_int( (int) $min, (int) $max );
	}

	/**
	 * Make uuid4() and int() deterministic. Tests only.
	 *
	 * @param int $seed Seed value.
	 * @return void
	 */
	public static function seed( $seed ) {
		if ( ! ( ( defined( 'SWIFTFORMS_TESTS' ) && SWIFTFORMS_TESTS ) || defined( 'SWIFTFORMS_TEST_NOW' ) ) ) {
			_doing_it_wrong( __METHOD__, 'Random::seed() is for tests only.', '0.1.0' );
			return;
		}
		mt_srand( (int) $seed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Seeded test sequence.
		self::$seeded = true;
	}

	/**
	 * Back to real randomness.
	 *
	 * @return void
	 */
	public static function unseed() {
		self::$seeded = false;
	}
}
