<?php
/**
 * Tests for SwiftForms\Throttle.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Throttle;

/**
 * Class ThrottleTest
 *
 * @group submission
 * @group security
 */
class ThrottleTest extends SF_Storage_Test_Case {

	/**
	 * Freeze the clock at the start of a minute.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Clock::freeze( 1900000020 );
	}

	/**
	 * The default limit is 60 and the filter can change it; nonsense is ignored.
	 *
	 * @return void
	 */
	public function test_limit_and_filter() {
		$this->assertSame( 60, Throttle::limit() );

		add_filter(
			'swiftforms_throttle_limit',
			static function () {
				return 5;
			}
		);
		$this->assertSame( 5, Throttle::limit() );
		remove_all_filters( 'swiftforms_throttle_limit' );

		foreach ( [ 0, -3, 'abc', null, [] ] as $bad ) {
			add_filter(
				'swiftforms_throttle_limit',
				static function () use ( $bad ) {
					return $bad;
				}
			);
			$this->assertSame( 60, Throttle::limit(), 'Ignored.' );
			remove_all_filters( 'swiftforms_throttle_limit' );
		}
	}

	/**
	 * The coarse limiter is a fixed window per client.
	 *
	 * @return void
	 */
	public function test_coarse_window() {
		for ( $i = 1; $i <= 60; $i++ ) {
			$this->assertTrue( Throttle::allow( 'client-a' ), "Request $i" );
		}
		$this->assertFalse( Throttle::allow( 'client-a' ), 'The 61st is over.' );
		$this->assertFalse( Throttle::allow( 'client-a' ) );
		$this->assertTrue( Throttle::allow( 'client-b' ), 'Another client is unaffected.' );

		Clock::freeze( 1900000020 + 59 );
		$this->assertFalse( Throttle::allow( 'client-a' ), 'Same window.' );

		Clock::freeze( 1900000020 + 60 );
		$this->assertTrue( Throttle::allow( 'client-a' ), 'A new window starts.' );
	}

	/**
	 * A lower filtered limit applies at once.
	 *
	 * @return void
	 */
	public function test_filtered_limit_applies() {
		add_filter(
			'swiftforms_throttle_limit',
			static function () {
				return 2;
			}
		);
		$this->assertTrue( Throttle::allow( 'c' ) );
		$this->assertTrue( Throttle::allow( 'c' ) );
		$this->assertFalse( Throttle::allow( 'c' ) );
	}

	/**
	 * The per-form limiter counts per form and client; 0 switches it off.
	 *
	 * @return void
	 */
	public function test_per_form_limiter() {
		$form_a = '11111111-1111-4111-8111-111111111111';
		$form_b = '22222222-2222-4222-8222-222222222222';

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( Throttle::allow_form( $form_a, 'c1', 5 ) );
		}
		$this->assertFalse( Throttle::allow_form( $form_a, 'c1', 5 ) );
		$this->assertTrue( Throttle::allow_form( $form_b, 'c1', 5 ), 'Another form.' );
		$this->assertTrue( Throttle::allow_form( $form_a, 'c2', 5 ), 'Another client.' );

		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertTrue( Throttle::allow_form( $form_a, 'c3', 0 ), 'Zero means off.' );
		}

		Clock::freeze( 1900000020 + 60 );
		$this->assertTrue( Throttle::allow_form( $form_a, 'c1', 5 ), 'Next window.' );
	}

	/**
	 * The duplicate guard: same form, client and payload within 10 seconds.
	 *
	 * @return void
	 */
	public function test_duplicate_guard() {
		$form = '11111111-1111-4111-8111-111111111111';

		$this->assertTrue( Throttle::claim( $form, 'c1', 'p1' ), 'First time.' );
		$this->assertFalse( Throttle::claim( $form, 'c1', 'p1' ), 'Same payload, same client.' );
		$this->assertTrue( Throttle::claim( $form, 'c2', 'p1' ), 'A different client is not a duplicate.' );
		$this->assertTrue( Throttle::claim( $form, 'c1', 'p2' ), 'A different payload.' );
		$this->assertTrue( Throttle::claim( '22222222-2222-4222-8222-222222222222', 'c1', 'p1' ), 'A different form.' );

		Clock::freeze( 1900000020 + 9 );
		$this->assertFalse( Throttle::claim( $form, 'c1', 'p1' ), 'Still inside 10 seconds.' );
		Clock::freeze( 1900000020 + 10 );
		$this->assertTrue( Throttle::claim( $form, 'c1', 'p1' ), 'Ten seconds later it is new again.' );
	}

	/**
	 * A released claim can be made again (the submission was invalid).
	 *
	 * @return void
	 */
	public function test_release() {
		$form = '11111111-1111-4111-8111-111111111111';
		$this->assertTrue( Throttle::claim( $form, 'c1', 'p1' ) );
		Throttle::release( $form, 'c1', 'p1' );
		$this->assertTrue( Throttle::claim( $form, 'c1', 'p1' ) );
	}

	/**
	 * Counters are never autoloaded options.
	 *
	 * @return void
	 */
	public function test_counters_are_not_autoloaded() {
		global $wpdb;

		Throttle::allow( 'client-z' );
		Throttle::allow_form( '11111111-1111-4111-8111-111111111111', 'client-z', 5 );
		Throttle::claim( '11111111-1111-4111-8111-111111111111', 'client-z', 'p' );

		$rows = $wpdb->get_results( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_swiftforms\\_%' OR option_name LIKE '\\_transient\\_timeout\\_swiftforms\\_%'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertContains( $row['autoload'], [ 'no', 'off' ], $row['option_name'] );
		}
	}
}
