<?php
/**
 * The six form patterns.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pattern_Configs
 *
 * Contact, quote request, callback request, newsletter signup, event RSVP and feedback
 * (F025 part 1), in the inserter's "Forms" pattern category. They use the v1 field
 * blocks and core layout blocks only, are translatable, and carry no formId: the editor
 * gives each inserted copy its own. A registered pattern is not a live location for
 * submissions (C-3.11).
 *
 * The patterns are handed to the block builder, which registers them on init.
 */
class Pattern_Configs {

	/**
	 * Every pattern in the builder's "patterns" format.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		return [
			self::contact(),
			self::quote(),
			self::callback(),
			self::newsletter(),
			self::rsvp(),
			self::feedback(),
		];
	}

	/**
	 * Contact form.
	 *
	 * @return array<string, mixed>
	 */
	private static function contact() {
		$form = self::form(
			self::field(
				'text',
				[
					'name'         => 'name',
					'label'        => __( 'Name', 'swiftforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'name',
				]
			)
			. self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email', 'swiftforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'email',
				]
			)
			. self::field(
				'text',
				[
					'name'  => 'subject',
					'label' => __( 'Subject', 'swiftforms' ),
				]
			)
			. self::field(
				'textarea',
				[
					'name'     => 'message',
					'label'    => __( 'Message', 'swiftforms' ),
					'required' => true,
					'rows'     => 6,
				]
			)
			. self::submit( __( 'Send message', 'swiftforms' ) )
			. self::confirmation( __( "Thanks! We'll be in touch.", 'swiftforms' ) )
		);

		return self::pattern(
			'contact',
			__( 'Contact form', 'swiftforms' ),
			__( 'Name, email, subject and message.', 'swiftforms' ),
			[ __( 'contact', 'swiftforms' ), __( 'message', 'swiftforms' ) ],
			self::heading( __( 'Get in touch', 'swiftforms' ) ) . $form
		);
	}

	/**
	 * Quote request.
	 *
	 * @return array<string, mixed>
	 */
	private static function quote() {
		$form = self::form(
			self::field(
				'text',
				[
					'name'         => 'name',
					'label'        => __( 'Name', 'swiftforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'name',
				]
			)
			. self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email', 'swiftforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'email',
				]
			)
			. self::field(
				'tel',
				[
					'name'         => 'phone',
					'label'        => __( 'Phone', 'swiftforms' ),
					'width'        => '50',
					'autocomplete' => 'tel',
				]
			)
			. self::field(
				'text',
				[
					'name'         => 'company',
					'label'        => __( 'Company', 'swiftforms' ),
					'width'        => '50',
					'autocomplete' => 'organization',
				]
			)
			. self::field(
				'select',
				[
					'name'              => 'service',
					'label'             => __( 'What do you need?', 'swiftforms' ),
					'required'          => true,
					'placeholderOption' => __( 'Choose one', 'swiftforms' ),
					'options'           => self::options( [ __( 'Design', 'swiftforms' ), __( 'Development', 'swiftforms' ), __( 'Consulting', 'swiftforms' ), __( 'Something else', 'swiftforms' ) ] ),
				]
			)
			. self::field(
				'radio',
				[
					'name'    => 'timeline',
					'label'   => __( 'When do you need it?', 'swiftforms' ),
					'layout'  => 'inline',
					'options' => self::options( [ __( 'As soon as possible', 'swiftforms' ), __( 'Within a month', 'swiftforms' ), __( 'No rush', 'swiftforms' ) ] ),
				]
			)
			. self::field(
				'textarea',
				[
					'name'     => 'details',
					'label'    => __( 'Project details', 'swiftforms' ),
					'required' => true,
					'rows'     => 6,
				]
			)
			. self::submit( __( 'Request a quote', 'swiftforms' ) )
			. self::confirmation( __( 'Thanks! We will send you a quote soon.', 'swiftforms' ) )
		);

		return self::pattern(
			'quote-request',
			__( 'Quote request', 'swiftforms' ),
			__( 'Contact details, what you need, when, and the details.', 'swiftforms' ),
			[ __( 'quote', 'swiftforms' ), __( 'estimate', 'swiftforms' ) ],
			self::heading( __( 'Request a quote', 'swiftforms' ) ) . $form
		);
	}

	/**
	 * Callback request.
	 *
	 * @return array<string, mixed>
	 */
	private static function callback() {
		$form = self::form(
			self::field(
				'text',
				[
					'name'         => 'name',
					'label'        => __( 'Name', 'swiftforms' ),
					'required'     => true,
					'autocomplete' => 'name',
				]
			)
			. self::field(
				'tel',
				[
					'name'         => 'phone',
					'label'        => __( 'Phone number', 'swiftforms' ),
					'required'     => true,
					'autocomplete' => 'tel',
				]
			)
			. self::field(
				'select',
				[
					'name'              => 'best_time',
					'label'             => __( 'Best time to call', 'swiftforms' ),
					'placeholderOption' => __( 'Any time', 'swiftforms' ),
					'options'           => self::options( [ __( 'Morning', 'swiftforms' ), __( 'Afternoon', 'swiftforms' ), __( 'Evening', 'swiftforms' ) ] ),
				]
			)
			. self::field(
				'textarea',
				[
					'name'  => 'topic',
					'label' => __( 'What is it about?', 'swiftforms' ),
					'rows'  => 3,
				]
			)
			. self::field(
				'consent',
				[
					'name' => 'consent',
					'text' => __( 'I agree to be called back on this number.', 'swiftforms' ),
				]
			)
			. self::submit( __( 'Call me back', 'swiftforms' ) )
			. self::confirmation( __( 'Thanks! We will call you back soon.', 'swiftforms' ) )
		);

		return self::pattern(
			'callback-request',
			__( 'Callback request', 'swiftforms' ),
			__( 'A name, a phone number and the best time to call.', 'swiftforms' ),
			[ __( 'callback', 'swiftforms' ), __( 'phone', 'swiftforms' ) ],
			self::heading( __( 'Request a callback', 'swiftforms' ) ) . $form
		);
	}

	/**
	 * Newsletter signup.
	 *
	 * @return array<string, mixed>
	 */
	private static function newsletter() {
		$form = self::form(
			self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email address', 'swiftforms' ),
					'required'     => true,
					'placeholder'  => __( 'Your email address', 'swiftforms' ),
					'autocomplete' => 'email',
				]
			)
			. self::field(
				'consent',
				[
					'name' => 'consent',
					'text' => __( 'I agree to receive the newsletter.', 'swiftforms' ),
				]
			)
			. self::submit( __( 'Subscribe', 'swiftforms' ) )
			. self::confirmation( __( 'Thanks for subscribing!', 'swiftforms' ) ),
			[ 'labelPosition' => 'hidden' ]
		);

		return self::pattern(
			'newsletter-signup',
			__( 'Newsletter signup', 'swiftforms' ),
			__( 'An email address, consent and a subscribe button.', 'swiftforms' ),
			[ __( 'newsletter', 'swiftforms' ), __( 'subscribe', 'swiftforms' ) ],
			self::heading( __( 'Stay in the loop', 'swiftforms' ) ) . $form
		);
	}

	/**
	 * Event RSVP.
	 *
	 * @return array<string, mixed>
	 */
	private static function rsvp() {
		$form = self::form(
			self::field(
				'text',
				[
					'name'         => 'name',
					'label'        => __( 'Name', 'swiftforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'name',
				]
			)
			. self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email', 'swiftforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'email',
				]
			)
			. self::field(
				'radio',
				[
					'name'     => 'attending',
					'label'    => __( 'Will you be there?', 'swiftforms' ),
					'required' => true,
					'layout'   => 'inline',
					'options'  => self::options( [ __( 'Yes', 'swiftforms' ), __( 'No', 'swiftforms' ), __( 'Maybe', 'swiftforms' ) ] ),
				]
			)
			. self::field(
				'number',
				[
					'name'         => 'guests',
					'label'        => __( 'Number of guests', 'swiftforms' ),
					'width'        => '50',
					'min'          => '0',
					'max'          => '10',
					'step'         => '1',
					'defaultValue' => '1',
				]
			)
			. self::field(
				'textarea',
				[
					'name'  => 'dietary',
					'label' => __( 'Dietary needs', 'swiftforms' ),
					'rows'  => 3,
				]
			)
			. self::submit( __( 'Send RSVP', 'swiftforms' ) )
			. self::confirmation( __( 'Thanks for letting us know!', 'swiftforms' ) )
		);

		return self::pattern(
			'event-rsvp',
			__( 'Event RSVP', 'swiftforms' ),
			__( 'Who is coming, how many, and any dietary needs.', 'swiftforms' ),
			[ __( 'event', 'swiftforms' ), __( 'rsvp', 'swiftforms' ) ],
			self::heading( __( 'RSVP', 'swiftforms' ) ) . $form
		);
	}

	/**
	 * Feedback.
	 *
	 * @return array<string, mixed>
	 */
	private static function feedback() {
		$form = self::form(
			self::field(
				'radio',
				[
					'name'     => 'rating',
					'label'    => __( 'How would you rate your experience?', 'swiftforms' ),
					'required' => true,
					'layout'   => 'inline',
					'options'  => self::options( [ __( 'Excellent', 'swiftforms' ), __( 'Good', 'swiftforms' ), __( 'Fair', 'swiftforms' ), __( 'Poor', 'swiftforms' ) ] ),
				]
			)
			. self::field(
				'textarea',
				[
					'name'  => 'comments',
					'label' => __( 'Tell us more', 'swiftforms' ),
					'rows'  => 5,
				]
			)
			. self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email (optional)', 'swiftforms' ),
					'help'         => __( 'Only if you would like a reply.', 'swiftforms' ),
					'autocomplete' => 'email',
				]
			)
			. self::submit( __( 'Send feedback', 'swiftforms' ) )
			. self::confirmation( __( 'Thank you for your feedback!', 'swiftforms' ) )
		);

		return self::pattern(
			'feedback',
			__( 'Feedback form', 'swiftforms' ),
			__( 'A rating, comments and an optional email.', 'swiftforms' ),
			[ __( 'feedback', 'swiftforms' ), __( 'survey', 'swiftforms' ) ],
			self::heading( __( 'Share your feedback', 'swiftforms' ) ) . $form
		);
	}

	// ---------------------------------------------------------------------
	// Markup helpers.
	// ---------------------------------------------------------------------

	/**
	 * A pattern in the builder's format.
	 *
	 * @param string   $slug        Slug below "swiftforms/".
	 * @param string   $title       Title.
	 * @param string   $description Description.
	 * @param string[] $keywords    Search keywords.
	 * @param string   $content     Block markup.
	 * @return array<string, mixed>
	 */
	private static function pattern( $slug, $title, $description, array $keywords, $content ) {
		return [
			'slug'        => 'swiftforms/' . $slug,
			'title'       => $title,
			'description' => $description,
			'categories'  => [ Block_Configs::CATEGORY ],
			'keywords'    => $keywords,
			'content'     => $content,
		];
	}

	/**
	 * A form block around inner markup. No formId: the editor gives the inserted copy one.
	 *
	 * @param string               $inner Inner block markup.
	 * @param array<string, mixed> $attrs Form attributes.
	 * @return string
	 */
	private static function form( $inner, array $attrs = [] ) {
		return '<!-- wp:swiftforms/form' . self::attrs( $attrs ) . ' -->' . $inner . '<!-- /wp:swiftforms/form -->';
	}

	/**
	 * A self-closing field block.
	 *
	 * @param string               $type  Field type.
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string
	 */
	private static function field( $type, array $attrs ) {
		return '<!-- wp:swiftforms/field-' . $type . self::attrs( $attrs ) . ' /-->';
	}

	/**
	 * The submit block.
	 *
	 * @param string $label Button text.
	 * @return string
	 */
	private static function submit( $label ) {
		return '<!-- wp:swiftforms/submit' . self::attrs( [ 'label' => $label ] ) . ' /-->';
	}

	/**
	 * The confirmation block with one paragraph.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	private static function confirmation( $text ) {
		return '<!-- wp:swiftforms/confirmation -->' . self::paragraph( $text ) . '<!-- /wp:swiftforms/confirmation -->';
	}

	/**
	 * A core paragraph.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function paragraph( $text ) {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * A core heading (level 2).
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function heading( $text ) {
		return '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $text ) . '</h2><!-- /wp:heading -->';
	}

	/**
	 * Options whose value is their label.
	 *
	 * @param string[] $labels Option labels.
	 * @return array<int, array<string, string>>
	 */
	private static function options( array $labels ) {
		$options = [];
		foreach ( $labels as $label ) {
			$options[] = [
				'value' => $label,
				'label' => $label,
			];
		}
		return $options;
	}

	/**
	 * Attribute JSON for a block comment, with a leading space; empty when there are none.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string
	 */
	private static function attrs( array $attrs ) {
		return [] === $attrs ? '' : ' ' . serialize_block_attributes( $attrs );
	}
}
