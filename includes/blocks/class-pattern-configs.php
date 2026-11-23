<?php
/**
 * The six form patterns.
 *
 * @package BlockForms
 */

namespace BlockForms;

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
					'label'        => __( 'Name', 'blockforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'name',
				]
			)
			. self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email', 'blockforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'email',
				]
			)
			. self::field(
				'text',
				[
					'name'  => 'subject',
					'label' => __( 'Subject', 'blockforms' ),
				]
			)
			. self::field(
				'textarea',
				[
					'name'     => 'message',
					'label'    => __( 'Message', 'blockforms' ),
					'required' => true,
					'rows'     => 6,
				]
			)
			. self::submit( __( 'Send message', 'blockforms' ) )
			. self::confirmation( __( "Thanks! We'll be in touch.", 'blockforms' ) )
		);

		return self::pattern(
			'contact',
			__( 'Contact form', 'blockforms' ),
			__( 'Name, email, subject and message.', 'blockforms' ),
			[ __( 'contact', 'blockforms' ), __( 'message', 'blockforms' ) ],
			self::heading( __( 'Get in touch', 'blockforms' ) ) . $form
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
					'label'        => __( 'Name', 'blockforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'name',
				]
			)
			. self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email', 'blockforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'email',
				]
			)
			. self::field(
				'tel',
				[
					'name'         => 'phone',
					'label'        => __( 'Phone', 'blockforms' ),
					'width'        => '50',
					'autocomplete' => 'tel',
				]
			)
			. self::field(
				'text',
				[
					'name'         => 'company',
					'label'        => __( 'Company', 'blockforms' ),
					'width'        => '50',
					'autocomplete' => 'organization',
				]
			)
			. self::field(
				'select',
				[
					'name'              => 'service',
					'label'             => __( 'What do you need?', 'blockforms' ),
					'required'          => true,
					'placeholderOption' => __( 'Choose one', 'blockforms' ),
					'options'           => self::options( [ __( 'Design', 'blockforms' ), __( 'Development', 'blockforms' ), __( 'Consulting', 'blockforms' ), __( 'Something else', 'blockforms' ) ] ),
				]
			)
			. self::field(
				'radio',
				[
					'name'    => 'timeline',
					'label'   => __( 'When do you need it?', 'blockforms' ),
					'layout'  => 'inline',
					'options' => self::options( [ __( 'As soon as possible', 'blockforms' ), __( 'Within a month', 'blockforms' ), __( 'No rush', 'blockforms' ) ] ),
				]
			)
			. self::field(
				'textarea',
				[
					'name'     => 'details',
					'label'    => __( 'Project details', 'blockforms' ),
					'required' => true,
					'rows'     => 6,
				]
			)
			. self::submit( __( 'Request a quote', 'blockforms' ) )
			. self::confirmation( __( 'Thanks! We will send you a quote soon.', 'blockforms' ) )
		);

		return self::pattern(
			'quote-request',
			__( 'Quote request', 'blockforms' ),
			__( 'Contact details, what you need, when, and the details.', 'blockforms' ),
			[ __( 'quote', 'blockforms' ), __( 'estimate', 'blockforms' ) ],
			self::heading( __( 'Request a quote', 'blockforms' ) ) . $form
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
					'label'        => __( 'Name', 'blockforms' ),
					'required'     => true,
					'autocomplete' => 'name',
				]
			)
			. self::field(
				'tel',
				[
					'name'         => 'phone',
					'label'        => __( 'Phone number', 'blockforms' ),
					'required'     => true,
					'autocomplete' => 'tel',
				]
			)
			. self::field(
				'select',
				[
					'name'              => 'best_time',
					'label'             => __( 'Best time to call', 'blockforms' ),
					'placeholderOption' => __( 'Any time', 'blockforms' ),
					'options'           => self::options( [ __( 'Morning', 'blockforms' ), __( 'Afternoon', 'blockforms' ), __( 'Evening', 'blockforms' ) ] ),
				]
			)
			. self::field(
				'textarea',
				[
					'name'  => 'topic',
					'label' => __( 'What is it about?', 'blockforms' ),
					'rows'  => 3,
				]
			)
			. self::field(
				'consent',
				[
					'name' => 'consent',
					'text' => __( 'I agree to be called back on this number.', 'blockforms' ),
				]
			)
			. self::submit( __( 'Call me back', 'blockforms' ) )
			. self::confirmation( __( 'Thanks! We will call you back soon.', 'blockforms' ) )
		);

		return self::pattern(
			'callback-request',
			__( 'Callback request', 'blockforms' ),
			__( 'A name, a phone number and the best time to call.', 'blockforms' ),
			[ __( 'callback', 'blockforms' ), __( 'phone', 'blockforms' ) ],
			self::heading( __( 'Request a callback', 'blockforms' ) ) . $form
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
					'label'        => __( 'Email address', 'blockforms' ),
					'required'     => true,
					'placeholder'  => __( 'Your email address', 'blockforms' ),
					'autocomplete' => 'email',
				]
			)
			. self::field(
				'consent',
				[
					'name' => 'consent',
					'text' => __( 'I agree to receive the newsletter.', 'blockforms' ),
				]
			)
			. self::submit( __( 'Subscribe', 'blockforms' ) )
			. self::confirmation( __( 'Thanks for subscribing!', 'blockforms' ) ),
			[ 'labelPosition' => 'hidden' ]
		);

		return self::pattern(
			'newsletter-signup',
			__( 'Newsletter signup', 'blockforms' ),
			__( 'An email address, consent and a subscribe button.', 'blockforms' ),
			[ __( 'newsletter', 'blockforms' ), __( 'subscribe', 'blockforms' ) ],
			self::heading( __( 'Stay in the loop', 'blockforms' ) ) . $form
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
					'label'        => __( 'Name', 'blockforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'name',
				]
			)
			. self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email', 'blockforms' ),
					'required'     => true,
					'width'        => '50',
					'autocomplete' => 'email',
				]
			)
			. self::field(
				'radio',
				[
					'name'     => 'attending',
					'label'    => __( 'Will you be there?', 'blockforms' ),
					'required' => true,
					'layout'   => 'inline',
					'options'  => self::options( [ __( 'Yes', 'blockforms' ), __( 'No', 'blockforms' ), __( 'Maybe', 'blockforms' ) ] ),
				]
			)
			. self::field(
				'number',
				[
					'name'         => 'guests',
					'label'        => __( 'Number of guests', 'blockforms' ),
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
					'label' => __( 'Dietary needs', 'blockforms' ),
					'rows'  => 3,
				]
			)
			. self::submit( __( 'Send RSVP', 'blockforms' ) )
			. self::confirmation( __( 'Thanks for letting us know!', 'blockforms' ) )
		);

		return self::pattern(
			'event-rsvp',
			__( 'Event RSVP', 'blockforms' ),
			__( 'Who is coming, how many, and any dietary needs.', 'blockforms' ),
			[ __( 'event', 'blockforms' ), __( 'rsvp', 'blockforms' ) ],
			self::heading( __( 'RSVP', 'blockforms' ) ) . $form
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
					'label'    => __( 'How would you rate your experience?', 'blockforms' ),
					'required' => true,
					'layout'   => 'inline',
					'options'  => self::options( [ __( 'Excellent', 'blockforms' ), __( 'Good', 'blockforms' ), __( 'Fair', 'blockforms' ), __( 'Poor', 'blockforms' ) ] ),
				]
			)
			. self::field(
				'textarea',
				[
					'name'  => 'comments',
					'label' => __( 'Tell us more', 'blockforms' ),
					'rows'  => 5,
				]
			)
			. self::field(
				'email',
				[
					'name'         => 'email',
					'label'        => __( 'Email (optional)', 'blockforms' ),
					'help'         => __( 'Only if you would like a reply.', 'blockforms' ),
					'autocomplete' => 'email',
				]
			)
			. self::submit( __( 'Send feedback', 'blockforms' ) )
			. self::confirmation( __( 'Thank you for your feedback!', 'blockforms' ) )
		);

		return self::pattern(
			'feedback',
			__( 'Feedback form', 'blockforms' ),
			__( 'A rating, comments and an optional email.', 'blockforms' ),
			[ __( 'feedback', 'blockforms' ), __( 'survey', 'blockforms' ) ],
			self::heading( __( 'Share your feedback', 'blockforms' ) ) . $form
		);
	}

	// ---------------------------------------------------------------------
	// Markup helpers.
	// ---------------------------------------------------------------------

	/**
	 * A pattern in the builder's format.
	 *
	 * @param string   $slug        Slug below "blockforms/".
	 * @param string   $title       Title.
	 * @param string   $description Description.
	 * @param string[] $keywords    Search keywords.
	 * @param string   $content     Block markup.
	 * @return array<string, mixed>
	 */
	private static function pattern( $slug, $title, $description, array $keywords, $content ) {
		return [
			'slug'        => 'blockforms/' . $slug,
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
		return '<!-- wp:blockforms/form' . self::attrs( $attrs ) . ' -->' . $inner . '<!-- /wp:blockforms/form -->';
	}

	/**
	 * A self-closing field block.
	 *
	 * @param string               $type  Field type.
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string
	 */
	private static function field( $type, array $attrs ) {
		return '<!-- wp:blockforms/field-' . $type . self::attrs( $attrs ) . ' /-->';
	}

	/**
	 * The submit block.
	 *
	 * @param string $label Button text.
	 * @return string
	 */
	private static function submit( $label ) {
		return '<!-- wp:blockforms/submit' . self::attrs( [ 'label' => $label ] ) . ' /-->';
	}

	/**
	 * The confirmation block with one paragraph.
	 *
	 * @param string $text Message.
	 * @return string
	 */
	private static function confirmation( $text ) {
		return '<!-- wp:blockforms/confirmation -->' . self::paragraph( $text ) . '<!-- /wp:blockforms/confirmation -->';
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
