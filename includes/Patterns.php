<?php
/**
 * Starter block patterns for the form builder.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

/**
 * Registers a `swf-forms` pattern category and one starter pattern per
 * file in `patterns/`, scoped to the `swf_form` post type so they only
 * show up in the form builder's pattern inserter.
 */
final class Patterns implements Registrable {

	public const CATEGORY = 'swf-forms';

	public function register(): void {
		add_action( 'init', array( $this, 'register_patterns' ) );
	}

	public function register_patterns(): void {
		register_block_pattern_category(
			self::CATEGORY,
			array( 'label' => __( 'SwiftForms', 'swiftforms' ) )
		);

		foreach ( $this->patterns() as $slug => $definition ) {
			$file = SWF_PLUGIN_PATH . 'patterns/' . $definition['file'];

			if ( ! file_exists( $file ) ) {
				continue;
			}

			register_block_pattern(
				'swf/' . $slug,
				array(
					'title'       => $definition['title'],
					'description' => $definition['description'],
					'categories'  => array( self::CATEGORY ),
					'postTypes'   => array( PostTypes::FORM_POST_TYPE ),
					'blockTypes'  => array( 'core/post-content' ),
					'content'     => (string) require $file,
				)
			);
		}
	}

	/**
	 * @return array<string, array{title: string, description: string, file: string}>
	 */
	private function patterns(): array {
		return array(
			'contact-form'       => array(
				'title'       => __( 'Contact Form', 'swiftforms' ),
				'description' => __( 'Name, email, and message.', 'swiftforms' ),
				'file'        => 'contact-form.php',
			),
			'quote-request'      => array(
				'title'       => __( 'Quote Request', 'swiftforms' ),
				'description' => __( 'Collects project details for a quote.', 'swiftforms' ),
				'file'        => 'quote-request.php',
			),
			'feedback-survey'    => array(
				'title'       => __( 'Feedback Survey', 'swiftforms' ),
				'description' => __( 'A short rating and comments survey.', 'swiftforms' ),
				'file'        => 'feedback-survey.php',
			),
			'event-registration' => array(
				'title'       => __( 'Event Registration', 'swiftforms' ),
				'description' => __( 'Multi-step registration with attendee details.', 'swiftforms' ),
				'file'        => 'event-registration.php',
			),
		);
	}
}
