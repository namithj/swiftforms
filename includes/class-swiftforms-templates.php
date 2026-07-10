<?php
/**
 * Starter form templates registered as block patterns.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * Registers ready-made form layouts as block patterns for the form editor.
 *
 * Scoping the patterns to the form post type gives new form posts the block
 * editor's built-in "Choose a pattern" modal with zero custom UI.
 */
class SwiftForms_Templates {

	/**
	 * Pattern category slug shared by all SwiftForms templates.
	 */
	public const CATEGORY = 'swiftforms';

	/**
	 * Registers the pattern category and every shipped template.
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		register_block_pattern_category(
			self::CATEGORY,
			array( 'label' => __( 'SwiftForms', 'swiftforms' ) )
		);

		foreach ( $this->get_templates() as $slug => $template ) {
			register_block_pattern(
				'swiftforms/' . $slug,
				array(
					'blockTypes'  => array( 'core/post-content' ),
					'categories'  => array( self::CATEGORY ),
					'content'     => $template['content'],
					'description' => $template['description'],
					'postTypes'   => array( SwiftForms_CPTs::FORM_POST_TYPE ),
					'title'       => $template['title'],
				)
			);
		}
	}

	/**
	 * Returns the shipped templates keyed by slug.
	 *
	 * Content lives in includes/templates/*.php files that each return a
	 * serialized block string built from real field block save() output.
	 *
	 * @return array<string, array{title: string, description: string, content: string}>
	 */
	public function get_templates(): array {
		$templates = array(
			'contact-form'       => array(
				'title'       => __( 'Contact form', 'swiftforms' ),
				'description' => __( 'Name, email, and message — the classic contact form.', 'swiftforms' ),
				'file'        => 'contact-form.php',
			),
			'quote-request'      => array(
				'title'       => __( 'Quote request', 'swiftforms' ),
				'description' => __( 'Qualify leads with project type, budget, and timeline fields.', 'swiftforms' ),
				'file'        => 'quote-request.php',
			),
			'feedback-survey'    => array(
				'title'       => __( 'Feedback survey', 'swiftforms' ),
				'description' => __( 'A short satisfaction survey with a follow-up field that appears for unhappy answers.', 'swiftforms' ),
				'file'        => 'feedback-survey.php',
			),
			'event-registration' => array(
				'title'       => __( 'Event registration', 'swiftforms' ),
				'description' => __( 'Two-step registration with attendee details, date, and meal choice.', 'swiftforms' ),
				'file'        => 'event-registration.php',
			),
		);

		$resolved = array();

		foreach ( $templates as $slug => $template ) {
			$path = SWIFTFORMS_PATH . 'includes/templates/' . $template['file'];

			if ( ! file_exists( $path ) ) {
				continue;
			}

			$content = require $path;

			if ( ! is_string( $content ) || '' === $content ) {
				continue;
			}

			$resolved[ $slug ] = array(
				'title'       => $template['title'],
				'description' => $template['description'],
				'content'     => $content,
			);
		}

		return $resolved;
	}
}
