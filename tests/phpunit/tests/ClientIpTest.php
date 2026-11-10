<?php
/**
 * Tests for SwiftForms\Client_Ip.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-storage-test-case.php';

use SwiftForms\Client_Ip;

/**
 * Class ClientIpTest
 *
 * @group submission
 * @group security
 */
class ClientIpTest extends SF_Storage_Test_Case {

	/**
	 * Clean server variables.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_REAL_IP'] );
	}

	/**
	 * The address is REMOTE_ADDR; spoofable headers are never trusted.
	 *
	 * @return void
	 */
	public function test_remote_addr_only() {
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.2';
		$_SERVER['HTTP_CLIENT_IP']        = '198.51.100.3';
		$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.4';
		$this->assertSame( '203.0.113.7', Client_Ip::address() );
		$this->assertSame( Client_Ip::key_for( '203.0.113.7' ), Client_Ip::client_key() );
	}

	/**
	 * A missing or invalid REMOTE_ADDR gives an empty address and a stable "unknown" key.
	 *
	 * @return void
	 */
	public function test_invalid_remote_addr() {
		foreach ( [ 'not an ip', '999.1.1.1', '', '1.2.3.4, 5.6.7.8' ] as $bad ) {
			$_SERVER['REMOTE_ADDR'] = $bad;
			$this->assertSame( '', Client_Ip::address(), $bad );
			$this->assertSame( Client_Ip::key_for( '' ), Client_Ip::client_key() );
		}
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( '', Client_Ip::address() );
	}

	/**
	 * The swiftforms_client_ip filter can supply the address; an invalid value falls back to REMOTE_ADDR.
	 *
	 * @return void
	 */
	public function test_filter_and_invalid_filter_value() {
		add_filter(
			'swiftforms_client_ip',
			static function () {
				return '198.51.100.99';
			}
		);
		$this->assertSame( '198.51.100.99', Client_Ip::address() );
		remove_all_filters( 'swiftforms_client_ip' );

		foreach ( [ 'garbage', '198.51.100.1, 198.51.100.2', '', false, null, [ '1.2.3.4' ], 12345 ] as $bad ) {
			add_filter(
				'swiftforms_client_ip',
				static function () use ( $bad ) {
					return $bad;
				}
			);
			$this->assertSame( '203.0.113.7', Client_Ip::address(), 'Invalid filter value falls back to REMOTE_ADDR.' );
			remove_all_filters( 'swiftforms_client_ip' );
		}
	}

	/**
	 * The filter receives REMOTE_ADDR.
	 *
	 * @return void
	 */
	public function test_filter_receives_remote_addr() {
		$seen = null;
		add_filter(
			'swiftforms_client_ip',
			static function ( $ip ) use ( &$seen ) {
				$seen = $ip;
				return $ip;
			}
		);
		Client_Ip::address();
		$this->assertSame( '203.0.113.7', $seen );
	}

	/**
	 * The key is a salted HMAC: 32 hex characters, no address in it, stable, different per address.
	 *
	 * @return void
	 */
	public function test_ipv4_key() {
		$key = Client_Ip::key_for( '203.0.113.7' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key );
		$this->assertSame( substr( hash_hmac( 'sha256', 'v4:203.0.113.7', wp_salt( 'nonce' ) ), 0, 32 ), $key );
		$this->assertNotSame( $key, Client_Ip::key_for( '203.0.113.8' ) );
	}

	/**
	 * IPv6 addresses count as their /64 prefix.
	 *
	 * @return void
	 */
	public function test_ipv6_is_bucketed_by_64() {
		$a = Client_Ip::key_for( '2001:db8:1234:5678::1' );
		$this->assertSame( $a, Client_Ip::key_for( '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd' ), 'Same /64.' );
		$this->assertSame( $a, Client_Ip::key_for( '2001:0db8:1234:5678:0:0:0:2' ), 'Notation does not matter.' );
		$this->assertNotSame( $a, Client_Ip::key_for( '2001:db8:1234:5679::1' ), 'Next /64.' );
		$this->assertNotSame( $a, Client_Ip::key_for( '2001:db8:1235:5678::1' ) );
	}

	/**
	 * An IPv4-mapped IPv6 address is the IPv4 address.
	 *
	 * @return void
	 */
	public function test_ipv4_mapped_ipv6() {
		$v4 = Client_Ip::key_for( '203.0.113.7' );
		$this->assertSame( $v4, Client_Ip::key_for( '::ffff:203.0.113.7' ) );
		$this->assertSame( $v4, Client_Ip::key_for( '0:0:0:0:0:ffff:cb00:7107' ) );
		$this->assertNotSame( $v4, Client_Ip::key_for( '::ffff:203.0.113.8' ) );
		$this->assertSame( 'v4:203.0.113.7', Client_Ip::normalise( '::ffff:203.0.113.7' ) );
	}

	/**
	 * Anything that is not an address normalises to "unknown".
	 *
	 * @return void
	 */
	public function test_normalise_unknown() {
		foreach ( [ '', 'x', null, 5, '1.2.3' ] as $bad ) {
			$this->assertSame( 'unknown', Client_Ip::normalise( $bad ) );
		}
	}

	/**
	 * The recent keys are bounded, not autoloaded, and drive the single-IP warning.
	 *
	 * @return void
	 */
	public function test_recent_clients_and_single_ip_warning() {
		global $wpdb;

		$this->assertFalse( Client_Ip::single_ip_suspected() );
		for ( $i = 0; $i < 9; $i++ ) {
			Client_Ip::record_accepted( 'same' );
		}
		$this->assertFalse( Client_Ip::single_ip_suspected(), 'Too few to tell.' );
		Client_Ip::record_accepted( 'same' );
		$this->assertTrue( Client_Ip::single_ip_suspected() );

		Client_Ip::record_accepted( 'other' );
		$this->assertFalse( Client_Ip::single_ip_suspected() );

		for ( $i = 0; $i < 40; $i++ ) {
			Client_Ip::record_accepted( 'k' . $i );
		}
		$recent = Client_Ip::recent();
		$this->assertCount( Client_Ip::RECENT_MAX, $recent );
		$this->assertSame( 'k39', end( $recent ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Client_Ip::RECENT_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, [ 'no', 'off' ] );
	}

	/**
	 * A corrupt option never fatals.
	 *
	 * @return void
	 */
	public function test_corrupt_recent_option() {
		update_option( Client_Ip::RECENT_OPTION, 'garbage', false );
		$this->assertSame( [], Client_Ip::recent() );
		update_option( Client_Ip::RECENT_OPTION, [ 'a', 5, null, 'b' ], false );
		$this->assertSame( [ 'a', 'b' ], Client_Ip::recent() );
	}
}
