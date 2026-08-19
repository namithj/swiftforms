<?php
/**
 * Tests for personal-data export and erasure.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests;

use SwiftForms\PostTypes;
use SwiftForms\Privacy;

final class PrivacyTest extends TestCase {

	public function test_registers_complete_suggested_policy_content(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		set_current_screen( 'options-privacy.php' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the callback's documented admin_init context without firing unrelated admin hooks.
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		( new Privacy() )->register_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );
		$reflection = new \ReflectionClass( \WP_Privacy_Policy_Content::class );
		$content    = $reflection->getStaticPropertyValue( 'policy_content' );
		$suggestion = end( $content );

		$this->assertSame( 'SwiftForms', $suggestion['plugin_name'] );
		$this->assertStringContainsString( 'indefinite retention', $suggestion['policy_text'] );
		$this->assertStringContainsString( 'Akismet', $suggestion['policy_text'] );
		$this->assertStringContainsString( 'Cloudflare Turnstile', $suggestion['policy_text'] );
		$this->assertStringContainsString( 'webhook', $suggestion['policy_text'] );
	}

	public function test_eraser_processes_remaining_entries_after_the_first_batch(): void {
		$entry_ids = array();

		for ( $index = 0; $index < 51; ++$index ) {
			$entry_id = self::factory()->post->create(
				array(
					'post_type'   => PostTypes::ENTRY_POST_TYPE,
					'post_status' => 'private',
				)
			);
			update_post_meta( $entry_id, 'smartlogix_swiftforms_field_email', 'person@example.com' );
			$entry_ids[] = $entry_id;
		}

		$privacy      = new Privacy();
		$first_result = $privacy->erase_personal_data( 'person@example.com', 1 );
		$next_result  = $privacy->erase_personal_data( 'person@example.com', 2 );

		$this->assertFalse( $first_result['done'] );
		$this->assertTrue( $next_result['done'] );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'      => PostTypes::ENTRY_POST_TYPE,
					'post_status'    => 'any',
					'post__in'       => $entry_ids,
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			)
		);
	}
}
