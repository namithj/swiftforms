<?php
/**
 * The submission pipeline.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Submission
 *
 * The C-3.3 pipeline, shared by the REST route and the no-JS handler (ADR-002). The caller has already run
 * the Gate (throttle, size, live location). In order:
 *
 *  1. resolve the CURRENT schema from stored content; check the source is open (published, viewable, no
 *     password without the cookie, theme active) -> closed;
 *  2. the render token: an expired one -> expired; one that does not verify is spam; a valid one names the
 *     schema the page was rendered with, and the fields are validated against THAT snapshot (C-3.8) while
 *     recipients, routing, limits and the confirmation always come from the current form;
 *  3. spam (honeypot, time trap, token, JS token, blocked words, filter);
 *  4. the per-form limiter -> rate_limited (counted only now that the schema resolved);
 *  5. the duplicate guard: the same payload from the same client within 10 seconds is answered as a success
 *     and stored once;
 *  6. validation against the validation schema -> invalid (spam skips it);
 *  7. storage (unless storage is off, or spam is discarded), THEN swiftforms_entry_saved, THEN the
 *     notification (a mail failure never loses the entry), THEN swiftforms_submission_complete;
 *  8. the confirmation.
 *
 * A spam submission gets the identical success answer: the same status, the same body bytes, no signal about
 * whether it was stored. Spam never reaches the hooks of step 7 or the mailer.
 */
class Submission {

	/**
	 * Replaces Mailer::notify() when set (tests; null uses the mailer of P5).
	 *
	 * @var callable|null
	 */
	public static $notifier = null;

	/**
	 * Run the pipeline.
	 *
	 * @param array<string, mixed>  $input     Keys: form_id, source, token, hp (`_sf_hp`), t (`_sf_t`), sf (the posted field array), page_hint (a URL the page may have been served from).
	 * @param string                $transport rest or nojs.
	 * @param \WP_User|null         $user      The authenticated user (valid cookie and nonce), or null for a guest.
	 * @return Submission_Result
	 */
	public static function handle( array $input, $transport = 'rest', $user = null ) {
		$transport = 'nojs' === $transport ? 'nojs' : 'rest';
		$form_id   = isset( $input['form_id'] ) && is_string( $input['form_id'] ) ? $input['form_id'] : '';
		$source    = isset( $input['source'] ) && is_string( $input['source'] ) ? $input['source'] : '';
		$posted    = isset( $input['sf'] ) && is_array( $input['sf'] ) ? $input['sf'] : [];

		// 1. The current form.
		$schema = Schema_Resolver::resolve( $form_id, $source );
		if ( null === $schema || ! self::is_open( $schema ) ) {
			return Submission_Result::make( Submission_Result::CLOSED );
		}

		// 2. The token.
		$token      = Render_Token::verify( $input['token'] ?? null, $form_id, $source, array_merge( [ $schema->hash ], Form_Index::hash_history( $form_id ), Schema_Store::recent_hashes( $form_id, Render_Token::MAX_CANDIDATES - 1 ) ) );
		$validation = $schema;
		if ( in_array( $token['state'], [ 'valid', 'expired' ], true ) && $token['schema_hash'] !== $schema->hash ) {
			$snapshot = Schema_Store::get( $token['schema_hash'] );
			if ( null !== $snapshot ) {
				$validation = $snapshot;
			}
		}

		$page        = self::page( $source, isset( $input['page_hint'] ) && is_string( $input['page_hint'] ) ? $input['page_hint'] : '' );
		$ctx         = new Submission_Context(
			$schema,
			$validation,
			[
				'user'       => $user instanceof \WP_User && $user->ID > 0 ? $user : null,
				'client_key' => Client_Ip::client_key(),
				'transport'  => $transport,
				'page_url'   => $page[0],
				'page_title' => $page[1],
			]
		);
		$ctx->values = Validator::collect( $validation, $posted, $ctx );

		if ( 'expired' === $token['state'] ) {
			return self::with_context( Submission_Result::make( Submission_Result::EXPIRED ), $ctx );
		}

		// 3. Spam.
		$verdict          = Spam_Guard::check(
			$ctx,
			[
				'hp' => $input['hp'] ?? '',
				't'  => $input['t'] ?? null,
			],
			$token
		);
		$ctx->is_spam     = $verdict['spam'];
		$ctx->spam_reason = $verdict['reason'];
		$ctx->status      = $verdict['spam'] ? 'spam' : 'new';
		if ( $ctx->is_spam ) {
			Log::add( 'error', 'debug', 'Submission judged spam.', [ 'reason' => $ctx->spam_reason ], $form_id );
		}

		// 4. The per-form limiter.
		if ( ! Throttle::allow_form( $form_id, $ctx->client_key, (int) Settings::get_value( 'spam.rate_limit_per_minute', 5 ) ) ) {
			return self::with_context( Submission_Result::make( Submission_Result::RATE_LIMITED ), $ctx );
		}

		// 5. The duplicate guard.
		$payload = hash( 'sha256', Form_Schema::canonical_json( $ctx->values ) . ( $ctx->is_spam ? '|s' : '' ) );
		if ( ! Throttle::claim( $form_id, $ctx->client_key, $payload ) ) {
			return self::with_context( Submission_Result::success( self::confirmation( $ctx ) ), $ctx );
		}

		// 6. Validation.
		if ( ! $ctx->is_spam ) {
			$errors = Validator::validate( $validation, $ctx->values, $ctx );
			if ( [] !== $errors ) {
				Throttle::release( $form_id, $ctx->client_key, $payload );
				return self::with_context( Submission_Result::invalid( $errors ), $ctx );
			}
		}

		// 7. Storage, then the hooks and the mail.
		if ( self::should_store( $schema, $ctx->is_spam ) ) {
			Schema_Store::save( $schema );
			if ( $validation !== $schema ) {
				Schema_Store::save( $validation );
			}
			$ctx->entry_id = Entries_Repository::insert( $ctx );
		}

		if ( ! $ctx->is_spam ) {
			Client_Ip::record_accepted( $ctx->client_key );

			if ( $ctx->entry_id > 0 ) {
				/**
				 * Fires after an entry has been stored, before any notification is sent.
				 *
				 * Not fired for spam, and not fired when the form does not store entries. The entry is
				 * already in the database: whatever you do here cannot lose it, and an exception you
				 * throw is not caught, so catch your own.
				 *
				 * @since 1.0.0
				 *
				 * @param int                $entry_id The new entry's ID.
				 * @param Submission_Context $context  The submission: the current form, the schema the fields were validated against, the plain-text values (field name => string, or list of strings), the user (or null), the page.
				 */
				do_action( 'swiftforms_entry_saved', $ctx->entry_id, $ctx );
			}

			self::notify( $ctx );
		}

		$result = Submission_Result::success( self::confirmation( $ctx ), $ctx->entry_id );

		if ( ! $ctx->is_spam ) {
			/**
			 * Fires when a clean submission has been fully processed: stored (if the form stores entries),
			 * the notification attempted, and the confirmation built.
			 *
			 * Not fired for spam. Runs for forms that do not store entries too ($context->entry_id is 0
			 * then). Use it for integrations that should see every accepted submission. Failures here do
			 * not undo anything, and an exception you throw is not caught.
			 *
			 * @since 1.0.0
			 *
			 * @param Submission_Context $context The submission (see swiftforms_entry_saved).
			 * @param Submission_Result  $result  What the visitor is about to receive: ->confirmation is { type: message, html } or { type: redirect, url }. Read it; changes are ignored.
			 */
			do_action( 'swiftforms_submission_complete', $ctx, $result );
		}

		return self::with_context( $result, $ctx );
	}

	/**
	 * Whether a form is open for submissions right now.
	 *
	 * The Gate has already confirmed the index calls the pair live. Here the things only the request can
	 * tell: a post must be published and viewable and, when password-protected, unlocked by this visitor's
	 * cookie; a form shown through a synced pattern or a file pattern is open when at least one of the pages
	 * that show it is; a template, part or widget must be active (the loader refuses other themes' templates).
	 *
	 * @param Form_Schema $schema Current schema.
	 * @return bool
	 */
	public static function is_open( Form_Schema $schema ) {
		$state = Source_Loader::state( $schema->source );
		if ( null === $state ) {
			return false;
		}

		if ( 'post' === $state['kind'] && 'wp_block' !== $state['post_type'] ) {
			return self::post_is_open( $state );
		}

		if ( 'post' === $state['kind'] ) {
			if ( 'publish' !== $state['status'] ) {
				return false;
			}
			return self::shown_by_open_page( $schema );
		}
		if ( 'pattern' === $state['kind'] ) {
			return self::shown_by_open_page( $schema );
		}

		return true === $state['active'];
	}

	/**
	 * Whether a post source is published, viewable and unlocked.
	 *
	 * @param array<string, mixed> $state Source_Loader state of a post.
	 * @return bool
	 */
	private static function post_is_open( array $state ) {
		if ( 'publish' !== $state['status'] || true !== $state['viewable'] ) {
			return false;
		}
		return ! ( true === $state['password'] && post_password_required( (int) $state['post_id'] ) );
	}

	/**
	 * Whether any live place that shows the form (from the index) is open to this visitor.
	 *
	 * @param Form_Schema $schema Schema.
	 * @return bool
	 */
	private static function shown_by_open_page( Form_Schema $schema ) {
		$row    = Form_Index::get_row( $schema->form_id );
		$places = ( is_array( $row ) && isset( $row['locations']['places'] ) && is_array( $row['locations']['places'] ) ) ? $row['locations']['places'] : [];
		if ( [] === $places ) {
			return false;
		}
		foreach ( $places as $place ) {
			$state = is_string( $place ) ? Source_Loader::state( $place ) : null;
			if ( null === $state ) {
				continue;
			}
			if ( 'post' !== $state['kind'] || self::post_is_open( $state ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this submission is stored.
	 *
	 * @param Form_Schema $schema Current schema.
	 * @param bool        $is_spam Whether it is spam.
	 * @return bool
	 */
	private static function should_store( Form_Schema $schema, $is_spam ) {
		$override = $schema->attributes['storeEntries'] ?? 'default';
		if ( 'yes' === $override ) {
			$store = true;
		} elseif ( 'no' === $override ) {
			$store = false;
		} else {
			$store = true === Settings::get_value( 'general.store_entries', true );
		}
		if ( $store && $is_spam && 'discard' === Settings::get_value( 'spam.spam_action', 'store' ) ) {
			return false;
		}
		return $store;
	}

	/**
	 * Hand the submission to the mailer (P5). A failure is logged and never loses the entry.
	 *
	 * @param Submission_Context $ctx Submission.
	 * @return void
	 */
	private static function notify( Submission_Context $ctx ) {
		$notifier = self::$notifier;
		if ( null === $notifier ) {
			if ( ! class_exists( __NAMESPACE__ . '\\Mailer' ) ) {
				return;
			}
			$notifier = [ __NAMESPACE__ . '\\Mailer', 'notify' ];
		}
		try {
			call_user_func( $notifier, $ctx );
		} catch ( \Throwable $e ) {
			Log::add(
				'mail',
				'error',
				'The notification failed: ' . get_class( $e ),
				[ 'entry' => $ctx->entry_id ],
				$ctx->form->form_id
			);
		}
	}

	/**
	 * The confirmation, falling back to the default message when building it fails.
	 *
	 * @param Submission_Context $ctx Submission.
	 * @return array<string, string>
	 */
	private static function confirmation( Submission_Context $ctx ) {
		try {
			return Confirmation::build( $ctx );
		} catch ( \Throwable $e ) {
			Log::add( 'error', 'error', 'The confirmation failed: ' . get_class( $e ), [], $ctx->form->form_id );
			return [
				'type' => 'message',
				'html' => '<p>' . wp_kses_post( (string) Settings::get_value( 'general.default_success_message', '' ) ) . '</p>',
			];
		}
	}

	/**
	 * Attach the context and the visitor's values to a result.
	 *
	 * @param Submission_Result  $result Result.
	 * @param Submission_Context $ctx    Submission.
	 * @return Submission_Result
	 */
	private static function with_context( Submission_Result $result, Submission_Context $ctx ) {
		$result->context = $ctx;
		$result->values  = $ctx->values;
		return $result;
	}

	/**
	 * The page URL and title as far as the server can verify them.
	 *
	 * A form in a post is on that post. For other sources the request's own URL (the Referer of a REST call,
	 * the URL a no-JS form was posted to) is used only when it points at this site.
	 *
	 * @param string $source Source reference.
	 * @param string $hint   URL the request says it came from.
	 * @return array{0:string, 1:string} URL and title.
	 */
	private static function page( $source, $hint ) {
		$parsed = Source_Ref::parse( $source );
		if ( null !== $parsed && 'post' === $parsed['type'] ) {
			$post = get_post( $parsed['post_id'] );
			if ( $post instanceof \WP_Post && 'wp_block' !== $post->post_type ) {
				$url = get_permalink( $post );
				return [ is_string( $url ) ? $url : '', trim( wp_strip_all_tags( $post->post_title ) ) ];
			}
		}

		$url  = esc_url_raw( $hint, [ 'http', 'https' ] );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $url || ! is_string( $host ) || ! is_string( $home ) || strtolower( $host ) !== strtolower( $home ) ) {
			return [ '', '' ];
		}
		return [ $url, '' ];
	}
}
