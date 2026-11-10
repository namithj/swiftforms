<?php
/**
 * Tests for SwiftForms\Uuid.
 *
 * @package SwiftForms
 */

use SwiftForms\Uuid;

/**
 * Class UuidTest
 *
 * @group resolver
 */
class UuidTest extends WP_UnitTestCase {

	/**
	 * Version 5 matches the reference values (computed with Python's uuid.uuid5 and RFC 4122 appendix B style vectors).
	 *
	 * @return void
	 */
	public function test_v5_known_vectors() {
		$dns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
		$url = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

		$this->assertSame( '2ed6657d-e927-568b-95e1-2665a8aea6a2', Uuid::v5( 'www.example.com', $dns ) );
		$this->assertSame( '886313e1-3b8a-5372-9b90-0c9aee199e5d', Uuid::v5( 'python.org', $dns ) );
		$this->assertSame( '0a300ee9-f9e4-5697-a51a-efc7fafaba67', Uuid::v5( 'http://example.com/', $url ) );
	}

	/**
	 * The default namespace is the SwiftForms one (C-2.7).
	 *
	 * @return void
	 */
	public function test_default_namespace() {
		$this->assertSame( '6f3c2a3e-5d1b-4c1e-9b8a-53776966746f', Uuid::NAMESPACE );
		$this->assertSame( '95184f16-6a02-5231-8eb9-3910f27ec348', Uuid::v5( 'missing|post:7|1' ) );
		$this->assertSame( '7fe58aa6-264e-51ac-866c-ffd129939ebc', Uuid::v5( 'café' ) );
		$this->assertSame( Uuid::v5( 'x' ), Uuid::v5( 'x', Uuid::NAMESPACE ) );
		$this->assertNotSame( Uuid::v5( 'x' ), Uuid::v5( 'y' ) );
	}

	/**
	 * Output is always a valid, lowercase, version 5 UUID.
	 *
	 * @return void
	 */
	public function test_output_shape() {
		foreach ( [ '', 'a', str_repeat( 'long', 100 ), "bin\0ary" ] as $name ) {
			$uuid = Uuid::v5( $name );
			$this->assertTrue( Uuid::is_valid( $uuid ), $uuid );
			$this->assertSame( '5', $uuid[14] );
			$this->assertContains( $uuid[19], [ '8', '9', 'a', 'b' ] );
		}
	}

	/**
	 * Only lowercase canonical UUIDs are valid.
	 *
	 * @return void
	 */
	public function test_is_valid() {
		$this->assertTrue( Uuid::is_valid( '11111111-1111-4111-8111-111111111111' ) );
		$this->assertFalse( Uuid::is_valid( '11111111-1111-4111-8111-11111111111G' ) );
		$this->assertFalse( Uuid::is_valid( '11111111-1111-4111-8111-11111111111A' ) );
		$this->assertFalse( Uuid::is_valid( "11111111-1111-4111-8111-111111111111\n" ) );
		$this->assertFalse( Uuid::is_valid( '{11111111-1111-4111-8111-111111111111}' ) );
		$this->assertFalse( Uuid::is_valid( '11111111111141118111111111111111' ) );
		$this->assertFalse( Uuid::is_valid( '' ) );
		$this->assertFalse( Uuid::is_valid( null ) );
		$this->assertFalse( Uuid::is_valid( 5 ) );
		$this->assertFalse( Uuid::is_valid( [] ) );
	}
}
