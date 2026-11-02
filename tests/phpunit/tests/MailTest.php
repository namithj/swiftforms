<?php
/**
 * Tests for the notification mail, the HTML template and the visitor-mail limits.
 *
 * @package SwiftForms
 */

require_once dirname( __DIR__ ) . '/includes/class-sf-submission-test-case.php';

use SwiftForms\Clock;
use SwiftForms\Mail_Limits;
use SwiftForms\Mail_Notice;
use SwiftForms\Mailer;
use SwiftForms\Submission;
use SwiftForms\Submission_Context;

/**
 * Class MailTest
 *
 * @group mail
 */
class MailTest extends SF_Submission_Test_Case {

	/**
	 * Hash of the attributes of the form ctx() made last (the same form is reused for the same attributes).
	 *
	 * @var string
	 */
	private $form_key = '';

	/**
	 * Fresh mock mailer, per-minute limit off.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->form_key = '';
		reset_phpmailer_instance();
		$this->set_settings( [ 'spam' => [ 'rate_limit_per_minute' => 0 ] ] );
	}

	/**
	 * Restore the clock and the notifier.
	 *
	 * @return void
	 */
	public function tear_down() {
		Clock::unfreeze();
		Submission::$notifier = null;
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * A context for the default form with the given form attributes.
	 *
	 * @param array $attrs  Form attributes.
	 * @param array $values Field values (default good()).
	 * @param array $data   Context properties.
	 * @return Submission_Context
	 */
	private function ctx( array $attrs = [], array $values = [], array $data = [] ) {
		$key = md5( (string) wp_json_encode( $attrs ) );
		if ( $key !== $this->form_key || '' === $this->source ) {
			$this->make_form( null, $attrs );
			$this->form_key = $key;
		}
		return new Submission_Context(
			$this->schema(),
			null,
			array_merge(
				[
					'values'   => array_merge( $this->good(), $values ),
					'entry_id' => 7,
				],
				$data
			)
		);
	}

	/**
	 * Mails the mock mailer holds.
	 *
	 * @return array
	 */
	private function sent() {
		return array_map(
			static function ( $mail ) {
				$mail['body'] = quoted_printable_decode( $mail['body'] );
				return $mail;
			},
			tests_retrieve_phpmailer_instance()->mock_sent
		);
	}

	/**
	 * Addresses of a recipient list of a sent mail.
	 *
	 * @param array $list The PHPMailer list.
	 * @return string[]
	 */
	private function addresses( array $list ) {
		return array_map(
			static function ( $item ) {
				return $item[0];
			},
			$list
		);
	}

	/**
	 * Rows of the mail log.
	 *
	 * @return array
	 */
	private function mail_log() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT * FROM `' . SwiftForms\Database::table( 'log' ) . "` WHERE type = 'mail' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Test read.
	}

	/**
	 * The default notification goes to the admin email.
	 *
	 * @return void
	 */
	public function test_default_notification_to_admin_email() {
		Mailer::notify( $this->ctx() );

		$this->assertCount( 1, $this->sent() );
		$mail = $this->sent()[0];
		$this->assertSame( [ get_option( 'admin_email' ) ], $this->addresses( $mail['to'] ) );
		$this->assertStringStartsWith( 'New submission:', $mail['subject'] );
		$this->assertStringContainsString( 'Ada Lovelace', $mail['body'] );
		$this->assertStringContainsString( 'multipart/alternative', $mail['header'] );
	}

	/**
	 * The default notification goes to the comma list in the settings.
	 *
	 * @return void
	 */
	public function test_default_notification_to_settings_list() {
		$this->set_settings( [ 'email' => [ 'notification_to' => 'a@example.com, b@example.com' ] ] );
		Mailer::notify( $this->ctx() );

		$this->assertSame( [ 'a@example.com', 'b@example.com' ], $this->addresses( $this->sent()[0]['to'] ) );
	}

	/**
	 * With the default notification off and no others, nothing is sent.
	 *
	 * @return void
	 */
	public function test_default_notification_off() {
		Mailer::notify( $this->ctx( [ 'useDefaultNotification' => false ] ) );
		$this->assertSame( [], $this->sent() );
	}

	/**
	 * Several notifications with to/cc/bcc lists, in order; disabled ones are skipped.
	 *
	 * @return void
	 */
	public function test_several_notifications() {
		$ctx = $this->ctx(
			[
				'useDefaultNotification' => false,
				'notifications'          => [
					[
						'to'      => 'one@example.com, two@example.com',
						'cc'      => 'cc1@example.com,cc2@example.com',
						'bcc'     => 'bcc@example.com',
						'subject' => 'First {field:name}',
						'body'    => '<p>Hi {field:name}</p>',
					],
					[
						'enabled' => false,
						'to'      => 'off@example.com',
					],
					[
						'to'      => 'last@example.com',
						'subject' => 'Second',
						'body'    => 'Plain {field:name}',
						'format'  => 'plain',
					],
				],
			]
		);
		Mailer::notify( $ctx );

		$sent = $this->sent();
		$this->assertCount( 2, $sent );
		$this->assertSame( [ 'one@example.com', 'two@example.com' ], $this->addresses( $sent[0]['to'] ) );
		$this->assertSame( [ 'cc1@example.com', 'cc2@example.com' ], $this->addresses( $sent[0]['cc'] ) );
		$this->assertSame( [ 'bcc@example.com' ], $this->addresses( $sent[0]['bcc'] ) );
		$this->assertSame( 'First Ada Lovelace', $sent[0]['subject'] );
		$this->assertSame( [ 'last@example.com' ], $this->addresses( $sent[1]['to'] ) );
		$this->assertStringContainsString( 'text/plain', $sent[1]['header'] );
		$this->assertStringContainsString( 'Plain Ada Lovelace', $sent[1]['body'] );
	}

	/**
	 * A tag in "to" works for an email field and is dropped (and logged) for a text field.
	 *
	 * @return void
	 */
	public function test_tag_recipients() {
		$ctx = $this->ctx(
			[
				'useDefaultNotification' => false,
				'notifications'          => [
					[
						'to'   => '{field:email}, {field:name}, owner@example.com',
						'body' => 'x',
					],
				],
			],
			[ 'name' => 'someone@example.com' ]
		);
		Mailer::notify( $ctx );

		$this->assertSame( [ 'ada@example.com', 'owner@example.com' ], $this->addresses( $this->sent()[0]['to'] ) );
		$levels = wp_list_pluck( $this->mail_log(), 'level' );
		$this->assertContains( 'warning', $levels, 'The dropped entry is logged.' );
	}

	/**
	 * A notification without a usable recipient is skipped and logged.
	 *
	 * @return void
	 */
	public function test_no_recipient_skips() {
		$ctx = $this->ctx(
			[
				'useDefaultNotification' => false,
				'notifications'          => [
					[
						'to'   => '{field:name}, not-an-address',
						'body' => 'x',
					],
				],
			]
		);
		Mailer::notify( $ctx );

		$this->assertSame( [], $this->sent() );
		$this->assertNotEmpty( $this->mail_log() );
	}

	/**
	 * Reply-To is the bare address, never a display name from the visitor.
	 *
	 * @return void
	 */
	public function test_reply_to_is_bare() {
		$attrs = [
			'useDefaultNotification' => false,
			'notifications'          => [
				[
					'to'      => 'owner@example.com',
					'replyTo' => '{field:email}',
					'body'    => 'x',
				],
			],
		];
		Mailer::notify( $this->ctx( $attrs ) );
		$header = $this->sent()[0]['header'];
		$this->assertMatchesRegularExpression( '/^Reply-To: ada@example\.com\r?$/m', $header );

		reset_phpmailer_instance();
		$evil = $this->ctx( $attrs, [ 'email' => '"Evil" <a@b.c>, x@y.z' ] );
		Mailer::notify( $evil );
		$header = $this->sent()[0]['header'];
		$this->assertStringNotContainsString( 'Evil', $header );
		$this->assertStringNotContainsString( 'x@y.z', $header );
		$this->assertStringNotContainsString( 'a@b.c', $header );
	}

	/**
	 * From is the settings or the site, never the visitor.
	 *
	 * @return void
	 */
	public function test_from_is_never_the_visitor() {
		Mailer::notify( $this->ctx() );
		$header = $this->sent()[0]['header'];
		$this->assertStringContainsString( 'From: ', $header );
		$this->assertStringNotContainsString( 'ada@example.com', $header );
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		$this->assertStringContainsString( 'wordpress@' . $host, $header );

		reset_phpmailer_instance();
		$this->set_settings(
			[
				'email' => [
					'from_name'  => 'Shop',
					'from_email' => 'shop@example.com',
				],
			]
		);
		Mailer::notify( $this->ctx() );
		$this->assertMatchesRegularExpression( '/^From: Shop <shop@example\.com>/m', $this->sent()[0]['header'] );
	}

	/**
	 * CR and LF from a visitor value do not reach the headers.
	 *
	 * @return void
	 */
	public function test_header_injection() {
		$ctx = $this->ctx(
			[
				'notifications' => [
					[
						'to'      => 'owner@example.com',
						'subject' => 'Hello {field:name}',
						'body'    => 'x',
					],
				],
			],
			[ 'name' => "Ada\r\nBcc: victim@example.com" ]
		);
		Mailer::notify( $ctx );

		foreach ( $this->sent() as $mail ) {
			$this->assertSame( [], $mail['bcc'] );
			$this->assertStringNotContainsString( "\nBcc:", $mail['header'] );
			$this->assertStringNotContainsString( 'victim@example.com', implode( ' ', $this->addresses( $mail['to'] ) ) );
		}
		$this->assertStringNotContainsString( "\n", $this->sent()[1]['subject'] );
		$this->assertStringContainsString( 'Hello Ada', $this->sent()[1]['subject'] );
	}

	/**
	 * A stored body is made safe at send time.
	 *
	 * @return void
	 */
	public function test_kses_at_send_time() {
		$ctx = $this->ctx(
			[
				'useDefaultNotification' => false,
				'notifications'          => [
					[
						'to'   => 'owner@example.com',
						'body' => '<p onclick="x()">Hi</p><script>alert(1)</script>{field:name}',
					],
				],
			],
			[ 'name' => '<script>x</script>' ]
		);
		Mailer::notify( $ctx );

		$body = $this->sent()[0]['body'];
		$body = substr( $body, (int) strpos( $body, 'text/html' ) ); // The HTML part (the text part holds the decoded text).
		$this->assertStringNotContainsString( '<script', $body );
		$this->assertStringNotContainsString( 'onclick', $body );
		$this->assertStringContainsString( '&lt;script&gt;', $body, 'The visitor value is escaped text.' );
	}

	/**
	 * The template wraps HTML mail: logo from an attachment, accent, footer, lang and dir.
	 *
	 * @return void
	 */
	public function test_template_on() {
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->set_settings(
			[
				'email' => [
					'html_template' => true,
					'logo_id'       => $attachment,
					'accent_color'  => '#ff0066',
					'footer_text'   => '<em>Sent by <script>x</script>us</em>',
				],
			]
		);
		Mailer::notify( $this->ctx() );

		$body = $this->sent()[0]['body'];
		$this->assertStringContainsString( '<!DOCTYPE html>', $body );
		$this->assertStringContainsString( '#ff0066', $body );
		$this->assertStringContainsString( wp_get_attachment_image_url( $attachment, 'medium' ), $body );
		$this->assertStringContainsString( '<em>Sent by xus</em>', $body );
		$this->assertStringNotContainsString( '<script', $body, 'The footer passes kses.' );
		$this->assertStringContainsString( 'dir="ltr"', $body );
		$this->assertStringContainsString( get_option( 'blogname' ), $body );
	}

	/**
	 * Without the template the body goes out as it is; a bad accent colour falls back.
	 *
	 * @return void
	 */
	public function test_template_off_and_bad_accent() {
		$this->set_settings( [ 'email' => [ 'html_template' => false ] ] );
		Mailer::notify( $this->ctx() );
		$this->assertStringNotContainsString( '<!DOCTYPE', $this->sent()[0]['body'] );
		$this->assertStringContainsString( 'Ada Lovelace', $this->sent()[0]['body'] );

		$this->assertStringContainsString( SwiftForms\Mail_Template::DEFAULT_ACCENT, SwiftForms\Mail_Template::wrap( 'x' ) );
	}

	/**
	 * HTML mail carries a plain-text alternative, and the phpmailer_init callback is removed again.
	 *
	 * @return void
	 */
	public function test_alt_body_and_callback_removed() {
		$before = isset( $GLOBALS['wp_filter']['phpmailer_init'] ) ? count( $GLOBALS['wp_filter']['phpmailer_init']->callbacks ) : 0;
		Mailer::notify( $this->ctx() );

		$body = $this->sent()[0]['body'];
		$this->assertStringContainsString( 'text/plain', $body, 'multipart/alternative has a text part.' );
		$this->assertStringContainsString( 'Name: Ada Lovelace', $body );
		$after = isset( $GLOBALS['wp_filter']['phpmailer_init'] ) ? count( $GLOBALS['wp_filter']['phpmailer_init']->callbacks ) : 0;
		$this->assertSame( $before, $after );
		$this->assertFalse( has_action( 'wp_mail_failed' ) );
	}

	/**
	 * Spam sends nothing, not even to the site owner.
	 *
	 * @return void
	 */
	public function test_spam_sends_nothing() {
		Mailer::notify( $this->ctx( [], [], [ 'is_spam' => true ] ) );
		$this->assertSame( [], $this->sent() );
	}

	/**
	 * The filter can change or skip a mail; the action fires after a send.
	 *
	 * @return void
	 */
	public function test_filter_and_action() {
		$seen = [];
		add_filter(
			'swiftforms_before_send_mail',
			static function ( $mail, $ctx, $notification ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Documents the filter signature.
				$mail['subject'] = 'Changed';
				return $mail;
			},
			10,
			3
		);
		add_action(
			'swiftforms_mail_sent',
			static function ( $sent, $mail, $ctx ) use ( &$seen ) {
				$seen[] = [ $sent, $mail['subject'], $ctx instanceof Submission_Context ];
			},
			10,
			3
		);
		Mailer::notify( $this->ctx() );
		$this->assertSame( 'Changed', $this->sent()[0]['subject'] );
		$this->assertSame( [ [ true, 'Changed', true ] ], $seen );

		remove_all_filters( 'swiftforms_before_send_mail' );
		add_filter( 'swiftforms_before_send_mail', '__return_false' );
		reset_phpmailer_instance();
		$seen = [];
		Mailer::notify( $this->ctx() );
		$this->assertSame( [], $this->sent() );
		$this->assertSame( [], $seen, 'A skipped mail fires no action.' );
	}

	/**
	 * A failing wp_mail() is logged, the action reports it, and nothing is thrown.
	 *
	 * @return void
	 */
	public function test_failure_is_logged() {
		add_filter( 'pre_wp_mail', '__return_false' );
		$sent = null;
		add_action(
			'swiftforms_mail_sent',
			static function ( $ok ) use ( &$sent ) {
				$sent = $ok;
			}
		);
		Mailer::notify( $this->ctx() );

		$this->assertFalse( $sent );
		$rows = array_filter(
			$this->mail_log(),
			static function ( $row ) {
				return 'error' === $row['level'];
			}
		);
		$this->assertNotEmpty( $rows );
		$row = array_values( $rows )[0];
		$this->assertStringContainsString( get_option( 'admin_email' ), $row['context'] );
		$this->assertStringContainsString( '"entry":7', $row['context'] );
	}

	/**
	 * Visitor-addressed mail: once per address per form per 10 minutes.
	 *
	 * @return void
	 */
	public function test_visitor_per_address_limit() {
		$attrs = $this->visitor_attrs();
		Mailer::notify( $this->ctx( $attrs ) );
		$this->assertCount( 1, $this->sent() );

		Mailer::notify( $this->ctx( $attrs, [], [ 'entry_id' => 8 ] ) );
		$this->assertCount( 1, $this->sent(), 'The same address, same form, within 10 minutes.' );

		Mailer::notify( $this->ctx( $attrs, [ 'email' => 'other@example.com' ] ) );
		$this->assertCount( 2, $this->sent() );
	}

	/**
	 * The address is never stored in a transient name.
	 *
	 * @return void
	 */
	public function test_address_is_hashed() {
		Mailer::notify( $this->ctx( $this->visitor_attrs() ) );
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%swiftforms_ml%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotEmpty( $names );
		foreach ( $names as $name ) {
			$this->assertStringNotContainsString( 'ada', $name );
		}
	}

	/**
	 * The daily cap per form, the banner state, and the admin mail still goes out.
	 *
	 * @return void
	 */
	public function test_visitor_daily_cap() {
		$this->set_settings( [ 'email' => [ 'visitor_mail_daily_cap' => 2 ] ] );
		$attrs = $this->visitor_attrs( true );

		foreach ( [ 'a', 'b', 'c' ] as $i => $who ) {
			Mailer::notify( $this->ctx( $attrs, [ 'email' => $who . '@example.com' ] ) );
		}

		$to_visitors = array_filter(
			$this->sent(),
			static function ( $mail ) {
				return 'Thanks' === $mail['subject'];
			}
		);
		$this->assertCount( 2, $to_visitors );
		$admin = array_filter(
			$this->sent(),
			static function ( $mail ) {
				return 'Thanks' !== $mail['subject'];
			}
		);
		$this->assertCount( 3, $admin, 'The default (admin) notification is never limited.' );

		$state = Mail_Limits::notice_state();
		$this->assertSame( 'daily', $state['cap'] );
		$this->assertSame( 2, $state['limit'] );
		$levels = wp_list_pluck( $this->mail_log(), 'level' );
		$this->assertContains( 'warning', $levels );
	}

	/**
	 * The hourly cap per site holds across forms.
	 *
	 * @return void
	 */
	public function test_visitor_hourly_cap() {
		Clock::freeze( 1900000000 );
		$this->set_settings( [ 'email' => [ 'visitor_mail_hourly_cap' => 1 ] ] );
		$attrs = $this->visitor_attrs();

		Mailer::notify( $this->ctx( $attrs, [ 'email' => 'a@example.com' ] ) );
		Mailer::notify( $this->ctx( $attrs, [ 'email' => 'b@example.com' ] ) ); // Another form (make_form makes a new one).
		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'hourly', Mail_Limits::notice_state()['cap'] );

		Clock::freeze( 1900000000 + 3700 );
		Mailer::notify( $this->ctx( $attrs, [ 'email' => 'b@example.com' ] ) );
		$this->assertCount( 2, $this->sent(), 'A new hour.' );
	}

	/**
	 * Visitor-addressed bodies are cut to 500 characters per value.
	 *
	 * @return void
	 */
	public function test_visitor_body_is_truncated() {
		Mailer::notify( $this->ctx( $this->visitor_attrs(), [ 'message' => str_repeat( 'x', 900 ) ] ) );
		$this->assertStringNotContainsString( str_repeat( 'x', 501 ), $this->sent()[0]['body'] );
		$this->assertStringContainsString( str_repeat( 'x', 500 ), $this->sent()[0]['body'] );
	}

	/**
	 * The banner shows on SwiftForms screens after a cap hit and can be dismissed.
	 *
	 * @return void
	 */
	public function test_banner() {
		$out   = function () {
			ob_start();
			Mail_Notice::render();
			return ob_get_clean();
		};
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		set_current_screen( 'toplevel_page_swiftforms' );
		$this->assertSame( '', $out(), 'No cap was hit.' );

		update_option(
			Mail_Limits::NOTICE_OPTION,
			[
				'time'  => Clock::now(),
				'cap'   => 'daily',
				'form'  => 'x',
				'limit' => 100,
			],
			false
		);
		$this->assertStringContainsString( '100 mails per day', $out() );
		$this->assertContains( $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'swiftforms_mail_cap_notice'" ), [ 'no', 'off' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		set_current_screen( 'dashboard' );
		$this->assertSame( '', $out(), 'Other screens show nothing.' );

		set_current_screen( 'toplevel_page_swiftforms' );
		update_user_meta( $admin, Mail_Notice::META, Clock::now() );
		$this->assertSame( '', $out(), 'Dismissed.' );

		update_user_meta( $admin, Mail_Notice::META, Clock::now() - 5 );
		update_option(
			Mail_Limits::NOTICE_OPTION,
			[
				'time'  => Clock::now() - 2 * DAY_IN_SECONDS,
				'cap'   => 'daily',
				'form'  => 'x',
				'limit' => 100,
			],
			false
		);
		delete_user_meta( $admin, Mail_Notice::META );
		$this->assertSame( '', $out(), 'Older than 24 hours.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( '', $out(), 'Needs swiftforms_manage_settings.' );
	}

	/**
	 * The dismiss handler needs a nonce.
	 *
	 * @return void
	 */
	public function test_dismiss_needs_nonce() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->capture_exits();
		$this->expectException( SF_Test_Die::class );
		Mail_Notice::dismiss();
	}

	/**
	 * Mail goes out after the entry row exists; a failing wp_mail() never loses the entry (end to end).
	 *
	 * @return void
	 */
	public function test_end_to_end_entry_exists_before_mail() {
		$this->make_form();
		$seen = null;
		add_filter(
			'pre_wp_mail',
			function () use ( &$seen ) {
				$seen = count( $this->entries() );
				return false; // The mail "fails".
			}
		);

		$res = $this->submit( $this->good() );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, $seen, 'The entry row existed when wp_mail() ran.' );
		$this->assertCount( 1, $this->entries() );
		$this->assertContains( 'error', wp_list_pluck( $this->mail_log(), 'level' ) );
	}

	/**
	 * Attributes: no default notification, one notification to the visitor's email field.
	 *
	 * @param bool $with_admin Keep the default (admin) notification.
	 * @return array
	 */
	private function visitor_attrs( $with_admin = false ) {
		return [
			'useDefaultNotification' => $with_admin,
			'notifications'          => [
				[
					'to'      => '{field:email}',
					'subject' => 'Thanks',
					'body'    => '{all_fields}',
				],
			],
		];
	}
	/**
	 * A line break in a value is one line break in the plain-text alternative.
	 *
	 * @return void
	 */
	public function test_plain_text_keeps_single_line_breaks() {
		$this->assertSame( "Message: one\ntwo", \SwiftForms\Mail_Template::plain_text( "<p>Message: one<br>\ntwo</p>" ) );
	}
}
