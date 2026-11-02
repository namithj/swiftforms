<?php
/**
 * Server side of the client/server validation parity check.
 *
 * tests/fixtures/validation-cases.json is run here against Validator::check() and in
 * tests/js/validation.test.js against the view module's rules. A case marked with a
 * "clientExpect" is a rule the browser does not check (option membership).
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Field_Types;
use SwiftForms\Validator;

/**
 * Class ValidationParityTest
 *
 * @group frontend
 */
class ValidationParityTest extends SF_Storage_Test_Case {

	/**
	 * The shared cases as a data provider.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases() {
		$json  = file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/validation-cases.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$cases = json_decode( (string) $json, true );
		$out   = [];
		foreach ( (array) $cases as $case ) {
			$out[ $case['name'] ] = [ $case ];
		}
		return $out;
	}

	/**
	 * Validator::check() gives the fixture's message key.
	 *
	 * @dataProvider cases
	 *
	 * @param array<string, mixed> $case Fixture case.
	 * @return void
	 */
	public function test_server_matches_fixture( array $case ) {
		$type  = (string) $case['field']['type'];
		$field = array_merge( [ 'type' => $type ], Field_Types::defaults( $type ), [ 'name' => 'f' ], $case['field'] );
		$value = isset( $case['valueRepeat'] ) ? str_repeat( $case['valueRepeat']['char'], $case['valueRepeat']['times'] ) : $case['value'];

		$this->assertSame( $case['expect'], Validator::check( $field, $value, '11111111-1111-4111-8111-111111111111' ) );
	}

	/**
	 * Every message key the client uses exists in the template map it receives.
	 *
	 * @return void
	 */
	public function test_message_templates_cover_every_fixture_key() {
		$messages = Validator::messages();
		foreach ( self::cases() as $case ) {
			if ( null !== $case[0]['expect'] ) {
				$this->assertArrayHasKey( $case[0]['expect'], $messages, $case[0]['name'] );
			}
		}
	}
}
