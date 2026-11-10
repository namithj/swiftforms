<?php
/**
 * The Settings screen: a cassette-cmf settings page.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings_Page
 *
 * Forms → Settings is a cassette-cmf settings page drawn by its React renderer and saved through its REST route
 * (swiftforms-cmf/v1/settings/swiftforms). This is the only class that touches cassette-cmf for the screen; the rest of
 * the plugin reads the values through Settings.
 *
 * - Page id "swiftforms", so cassette-cmf stores one option per field, swiftforms_{section}_{key} (C-5). The page
 *   declares no schema_version, so cassette-cmf writes nothing besides those options.
 * - The page hangs under the Forms menu (slug "swiftforms", added by Admin_Menu, which must add the menu before
 *   cassette-cmf's admin_menu callback runs: both use priority 10 and Admin_Menu hooks first).
 * - The capability is the meta capability swiftforms_manage_settings, mapped by Access. cassette-cmf checks it on the
 *   page and on the route.
 * - Saving a value that changes nothing writes nothing (C-5.4): skip_unchanged().
 */
class Settings_Page {

	/**
	 * Page id; also the option prefix without the trailing underscore.
	 */
	const PAGE_ID = 'swiftforms';

	/**
	 * Menu slug (admin.php?page=swiftforms-settings).
	 */
	const MENU_SLUG = 'swiftforms-settings';

	/**
	 * Slug of the Forms menu the page hangs under.
	 */
	const PARENT_SLUG = 'swiftforms';

	/**
	 * Capability (a meta capability, mapped by Access).
	 */
	const CAPABILITY = 'swiftforms_manage_settings';

	/**
	 * Boot options that keep cassette-cmf's identifiers apart from any other copy on the site.
	 */
	const BOOT = [
		'asset_handle'   => 'swiftforms-cmf',
		'js_global'      => 'SwiftFormsCMFFields',
		'rest_namespace' => 'swiftforms-cmf/v1',
	];

	/**
	 * Largest value of an integer setting, by "section.key". Every other integer setting is capped by Settings::MAX_INT.
	 */
	const LIMITS = [
		'general.retention_days'        => 3650,
		'spam.min_seconds'              => 60,
		'spam.rate_limit_per_minute'    => 1000,
		'email.visitor_mail_daily_cap'  => 100000,
		'email.visitor_mail_hourly_cap' => 100000,
	];

	/**
	 * Longest text of a text setting.
	 */
	const MAX_TEXT = 20000;

	/**
	 * Integer settings where an empty submission means "none" (0).
	 */
	const EMPTY_IS_ZERO = [ 'email.logo_id' ];

	/**
	 * Hook the registration and the no-op guard.
	 *
	 * @return void
	 */
	public static function init() {
		// Priority 5, like the block builder: cassette-cmf wants its pages before init finishes and before rest_api_init.
		add_action( 'init', [ self::class, 'register' ], 5 );
		add_filter( 'cassette_cmf_before_save_field', [ self::class, 'skip_unchanged' ], 10, 3 );
		add_filter( 'cassette_cmf_settings_app_build_url', [ self::class, 'app_url' ] );
	}

	/**
	 * Where the built settings app is served from.
	 *
	 * cassette-cmf derives the URL from ABSPATH, which breaks when the plugin folder is a symlink or wp-content lives
	 * outside the WordPress folder; plugins_url() always knows.
	 *
	 * @return string
	 */
	public static function app_url() {
		return plugins_url( 'vendor-prefixed/pedalcms/cassette-cmf/build/', SWIFTFORMS_FILE );
	}

	/**
	 * Boot cassette-cmf with its own identifiers and register the page.
	 *
	 * @return void
	 */
	public static function register() {
		$cmf = '\\SwiftForms\\Vendor\\PedalCMS\\CassetteCMF\\CassetteCMF';
		if ( ! class_exists( $cmf ) ) {
			return;
		}

		// Boot before any other call, so the prefixed identifiers win.
		$cmf::boot( self::BOOT );
		$cmf::register_field_type( Settings_Email_Field::TYPE, Settings_Email_Field::class );
		$cmf::register_field_type( Settings_Color_Field::TYPE, Settings_Color_Field::class );
		$cmf::register_field_type( Settings_Image_Field::TYPE, Settings_Image_Field::class );
		$cmf::register_from_array( [ 'settings_pages' => [ self::page() ] ] );
	}

	/**
	 * The page configuration.
	 *
	 * @return array<string, mixed>
	 */
	public static function page() {
		return [
			'id'          => self::PAGE_ID,
			'page_title'  => __( 'SwiftForms settings', 'swiftforms' ),
			'menu_title'  => __( 'Settings', 'swiftforms' ),
			'capability'  => self::CAPABILITY,
			'menu_slug'   => self::MENU_SLUG,
			'parent_slug' => self::PARENT_SLUG,
			'render_mode' => 'react',
			'fields'      => [
				[
					'name'   => 'swiftforms_settings_box',
					'type'   => 'metabox',
					'label'  => __( 'Settings', 'swiftforms' ),
					'fields' => [
						[
							'name' => 'swiftforms_settings_tabs',
							'type' => 'tabs',
							'tabs' => [
								[
									'id'     => 'general',
									'label'  => __( 'General', 'swiftforms' ),
									'fields' => self::general_fields(),
								],
								[
									'id'     => 'email',
									'label'  => __( 'Email', 'swiftforms' ),
									'fields' => self::email_fields(),
								],
								[
									'id'     => 'spam',
									'label'  => __( 'Spam', 'swiftforms' ),
									'fields' => self::spam_fields(),
								],
								[
									'id'     => 'privacy',
									'label'  => __( 'Privacy', 'swiftforms' ),
									'fields' => self::privacy_fields(),
								],
								[
									'id'     => 'advanced',
									'label'  => __( 'Advanced', 'swiftforms' ),
									'fields' => self::advanced_fields(),
								],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * The General tab.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function general_fields() {
		return [
			self::checkbox( 'general.store_entries', __( 'Save entries in the database', 'swiftforms' ), __( 'Turn this off if you only want the email.', 'swiftforms' ) ),
			[
				'name'        => 'general_store_entries_notice',
				'type'        => 'custom_html',
				'content'     => '<div class="notice notice-warning inline"><p>' . esc_html__( 'If an email fails, the submission is lost.', 'swiftforms' ) . '</p></div>',
				'conditional' => [
					'relation' => 'AND',
					'rules'    => [
						[
							'field'    => 'general_store_entries',
							'operator' => '==',
							'value'    => '0',
						],
					],
				],
			],
			self::checkbox( 'general.load_styles', __( 'Load the form styles', 'swiftforms' ), __( 'Turn this off if your theme styles the forms itself.', 'swiftforms' ) ),
			self::html_text( 'general.default_success_message', __( 'Default success message', 'swiftforms' ), __( 'Shown when a form has no confirmation of its own. Basic formatting is allowed.', 'swiftforms' ) ),
		];
	}

	/**
	 * The Email tab.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function email_fields() {
		return [
			self::text( 'email.from_name', __( 'From name', 'swiftforms' ) ),
			self::emails( 'email.from_email', __( 'From email', 'swiftforms' ), __( 'Leave empty to use the site default.', 'swiftforms' ), true ),
			self::emails( 'email.notification_to', __( 'Send notifications to', 'swiftforms' ), __( 'One or more addresses, separated by commas. Leave empty to use the site admin email.', 'swiftforms' ), false ),
			self::checkbox( 'email.html_template', __( 'Use the HTML email template', 'swiftforms' ) ),
			self::field(
				'email.logo_id',
				Settings_Image_Field::TYPE,
				__( 'Logo', 'swiftforms' ),
				[
					// The upload control treats anything but '' as "has a file"; 0 would show "Remove logo".
					'default'      => '',
					'description'  => __( 'Shown at the top of HTML emails.', 'swiftforms' ),
					'library_type' => 'image',
					'button_text'  => __( 'Choose logo', 'swiftforms' ),
					'remove_text'  => __( 'Remove logo', 'swiftforms' ),
				]
			),
			self::field(
				'email.accent_color',
				Settings_Color_Field::TYPE,
				__( 'Accent colour', 'swiftforms' ),
				[
					'description' => __( 'Used for the button and links in HTML emails. Leave empty for the default.', 'swiftforms' ),
					'default'     => '',
				]
			),
			self::html_text( 'email.footer_text', __( 'Email footer', 'swiftforms' ), __( 'Basic formatting is allowed.', 'swiftforms' ) ),
			self::number( 'email.visitor_mail_daily_cap', __( 'Emails to visitors per form, per day', 'swiftforms' ), __( 'A limit on mail sent to addresses visitors typed. The entry is always saved.', 'swiftforms' ) ),
			self::number( 'email.visitor_mail_hourly_cap', __( 'Emails to visitors on the whole site, per hour', 'swiftforms' ) ),
		];
	}

	/**
	 * The Spam tab.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function spam_fields() {
		return [
			self::checkbox( 'spam.honeypot', __( 'Hidden trap field', 'swiftforms' ), __( 'Adds a field that people cannot see. Bots that fill it in are treated as spam.', 'swiftforms' ) ),
			self::checkbox( 'spam.js_token', __( 'Require JavaScript proof', 'swiftforms' ), __( 'Submissions sent without the browser script are treated as spam.', 'swiftforms' ) ),
			self::number( 'spam.min_seconds', __( 'Fastest plausible submission (seconds)', 'swiftforms' ), __( 'Forms sent faster than this are treated as spam. 0 turns the check off.', 'swiftforms' ) ),
			self::number( 'spam.rate_limit_per_minute', __( 'Submissions per minute, per form and visitor', 'swiftforms' ), __( '0 turns the limit off.', 'swiftforms' ) ),
			self::field(
				'spam.blocked_words',
				'textarea',
				__( 'Blocked words', 'swiftforms' ),
				[
					'description' => __( 'One word or phrase per line. Matched as plain text, ignoring case.', 'swiftforms' ),
					'rows'        => 5,
					'validation'  => [ 'max' => self::MAX_TEXT ],
				]
			),
			self::field(
				'spam.spam_action',
				'select',
				__( 'What to do with spam', 'swiftforms' ),
				[
					'options' => [
						'store'   => __( 'Keep it in the Spam list', 'swiftforms' ),
						'discard' => __( 'Throw it away', 'swiftforms' ),
					],
				]
			),
		];
	}

	/**
	 * The Privacy tab. The visitor IP, browser and retention settings live here, with the personal-data tools.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function privacy_fields() {
		return [
			self::checkbox( 'general.store_ip', __( 'Save the visitor IP address', 'swiftforms' ) ),
			self::checkbox( 'general.store_user_agent', __( 'Save the visitor browser (user agent)', 'swiftforms' ) ),
			self::number( 'general.retention_days', __( 'Delete entries after (days)', 'swiftforms' ), __( '0 keeps entries until you delete them.', 'swiftforms' ) ),
			self::checkbox( 'privacy.exporter', __( 'Include entries in WordPress personal data exports', 'swiftforms' ) ),
			self::checkbox( 'privacy.eraser', __( 'Include entries in WordPress personal data erasure', 'swiftforms' ) ),
		];
	}

	/**
	 * The Advanced tab. advanced.delete_on_uninstall stays off the page until 1.1.0 (its default is in Settings).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function advanced_fields() {
		return [
			self::checkbox( 'advanced.debug_log', __( 'Write detailed diagnostics to the log', 'swiftforms' ), __( 'Only for troubleshooting. The log never holds what visitors typed.', 'swiftforms' ) ),
		];
	}

	/**
	 * A field of the page: the name is "{section}_{key}", the default comes from Settings.
	 *
	 * @param string               $path   "section.key".
	 * @param string               $type   Field type.
	 * @param string               $label  Label.
	 * @param array<string, mixed> $extra  More configuration.
	 * @return array<string, mixed>
	 */
	private static function field( $path, $type, $label, array $extra = [] ) {
		return array_merge(
			[
				'name'    => str_replace( '.', '_', $path ),
				'type'    => $type,
				'label'   => $label,
				'default' => Settings::default_of( $path ),
			],
			$extra
		);
	}

	/**
	 * A checkbox setting.
	 *
	 * @param string $path  "section.key".
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @return array<string, mixed>
	 */
	private static function checkbox( $path, $label, $help = '' ) {
		return self::field( $path, 'checkbox', $label, '' === $help ? [] : [ 'description' => $help ] );
	}

	/**
	 * A single-line text setting.
	 *
	 * @param string $path  "section.key".
	 * @param string $label Label.
	 * @return array<string, mixed>
	 */
	private static function text( $path, $label ) {
		return self::field( $path, 'text', $label, [ 'validation' => [ 'max' => self::MAX_TEXT ] ] );
	}

	/**
	 * A setting that holds one email address, or a comma-separated list.
	 *
	 * @param string $path   "section.key".
	 * @param string $label  Label.
	 * @param string $help   Help text.
	 * @param bool   $single One address only.
	 * @return array<string, mixed>
	 */
	private static function emails( $path, $label, $help, $single ) {
		return self::field(
			$path,
			Settings_Email_Field::TYPE,
			$label,
			[
				'description' => $help,
				'single'      => $single,
				'validation'  => [ 'max' => self::MAX_TEXT ],
			]
		);
	}

	/**
	 * A multi-line setting that may hold basic HTML (links, bold): kept with wp_kses_post() on every save.
	 *
	 * @param string $path  "section.key".
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @return array<string, mixed>
	 */
	private static function html_text( $path, $label, $help ) {
		return self::field(
			$path,
			'textarea',
			$label,
			[
				'description' => $help,
				'allow_html'  => true,
				'rows'        => 4,
				'validation'  => [ 'max' => self::MAX_TEXT ],
			]
		);
	}

	/**
	 * A whole-number setting from 0.
	 *
	 * @param string $path  "section.key".
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @return array<string, mixed>
	 */
	private static function number( $path, $label, $help = '' ) {
		$extra = [
			'min'  => 0,
			'max'  => self::LIMITS[ $path ] ?? Settings::MAX_INT,
			'step' => 1,
		];
		if ( '' !== $help ) {
			$extra['description'] = $help;
		}
		return self::field( $path, 'number', $label, $extra );
	}

	/**
	 * Skip a submitted value that changes nothing (C-5.4), so a save of the values just read writes no option.
	 *
	 * Runs before cassette-cmf sanitises. A value is skipped when it is a valid value for its key and equals what the
	 * plugin reads today: an absent option with the default submitted stays absent, and a present option keeps its
	 * exact bytes (also where sanitising would have rewritten them, as with HTML saved by an older tool). A change, an
	 * invalid value, or a value for a key this class does not know goes on to be sanitised and validated.
	 *
	 * @param mixed  $value   Submitted value, in the shape the classic form would post.
	 * @param string $field   Field name.
	 * @param string $context Page id.
	 * @return mixed The value, or null to skip the field.
	 */
	public static function skip_unchanged( $value, $field = '', $context = '' ) {
		if ( self::PAGE_ID !== $context ) {
			return $value;
		}
		$path = Settings::path_of( Settings::PREFIX . $field );
		if ( null === $path ) {
			return $value;
		}

		$default = Settings::default_of( $path );
		if ( is_int( $default ) && is_string( $value ) && '' !== $value && is_numeric( $value ) && false !== strpos( $value, '.' ) ) {
			$value = (string) (int) floor( (float) $value ); // A whole number setting never keeps a fraction.
		}

		$candidate = in_array( $path, self::EMPTY_IS_ZERO, true ) && '' === $value ? 0 : Settings::coerce( $path, $value, $default );
		if ( null !== $candidate && Settings::get_value( $path ) === $candidate ) {
			return null;
		}
		return $value;
	}
}
