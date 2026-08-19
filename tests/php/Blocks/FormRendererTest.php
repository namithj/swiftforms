<?php
/**
 * Tests for the public form wrapper.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Tests\Blocks;

use SwiftForms\PostTypes;
use SwiftForms\Tests\TestCase;

final class FormRendererTest extends TestCase {

	public function test_rendered_form_fails_visibly_and_safely_without_javascript(): void {
		$form_id = self::factory()->post->create(
			array(
				'post_type'   => PostTypes::FORM_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Contact',
			)
		);

		$html = do_blocks(
			sprintf( '<!-- wp:smartlogix-swiftforms/form {"formId":%d} /-->', $form_id )
		);

		$this->assertStringContainsString( 'data-swf-script-required', $html );
		$this->assertStringContainsString( 'JavaScript is required to submit this form.', $html );
		$this->assertStringContainsString( 'class="swf-form__submit" disabled', $html );
	}
}
