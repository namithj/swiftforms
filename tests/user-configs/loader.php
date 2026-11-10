<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Fixture loader: the class name SF_User_Config_Loader is fixed by the R00 plan; exceptions are developer-facing.
/**
 * User-config loader for the regression safety net (R00).
 *
 * Lists the numbered configuration files, validates and loads them, and applies
 * one to the (throwaway) database as raw stored data, the way a real existing
 * site would have it. It never writes through SwiftForms code.
 *
 * It has no PHPUnit dependency, so bin/ scripts and apply-cli.php (WP-CLI) use it too.
 *
 * @package SwiftForms
 */

require_once __DIR__ . '/fixtures/builder.php';
require_once __DIR__ . '/fixtures/blocks.php';
require_once __DIR__ . '/fixtures/values.php';

/**
 * Loads and applies user configurations.
 */
class SF_User_Config_Loader {

	/**
	 * The UTC moment every config is "now": 2026-11-19T10:00:00Z, the v1.0.0 date.
	 */
	const FROZEN_TIME = 1795082400;

	/**
	 * Seed for mt_rand() and SwiftForms\Random.
	 */
	const SEED = 20261119;

	/**
	 * The first post ID and user ID a config gets, counting up in the order the config lists them.
	 *
	 * Auto-increment IDs depend on how many runs came before, and values such as a hidden field's {post:id}
	 * or {user:id} are stored in entries, so a config pins its IDs. The bases are far above anything the
	 * auto-increment counter reaches.
	 */
	const POST_ID_BASE = 900000;
	const USER_ID_BASE = 800000;

	/**
	 * Every scenario id a config may list in "skip".
	 */
	const SCENARIOS = [ 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11', 'S12', 'S13', 'S14', 'S15', 'S16' ];

	/**
	 * Post key to ID, for the config applied last.
	 *
	 * @var array<string, int>
	 */
	private static $post_ids = [];

	/**
	 * User key to ID, for the config applied last.
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = [];

	/**
	 * Post key to post type, for the config applied last.
	 *
	 * @var array<string, string>
	 */
	private static $post_types = [];

	/**
	 * Mails captured by the pre_wp_mail filter.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $mails = [];

	/**
	 * HTTP fixtures of the active config: URL => response array.
	 *
	 * @var array<string, array>
	 */
	private static $http_fixtures = [];

	/**
	 * Temporary uploads directory of the active config, or an empty string.
	 *
	 * @var string
	 */
	private static $uploads_dir = '';

	/**
	 * Callbacks added by install_environment(), so reset() can remove them.
	 *
	 * @var array<int, array{0:string,1:callable,2:int}>
	 */
	private static $env_filters = [];

	/**
	 * Whether install_environment() is in force.
	 *
	 * @var bool
	 */
	private static $env_installed = false;

	/**
	 * Theme directories before this class registered ours.
	 *
	 * @var array|null
	 */
	private static $theme_dirs_before = null;

	/**
	 * The global $locale before a config changed it: [ was set, value ], or null when no config changed it.
	 *
	 * @var array{0:bool,1:mixed}|null
	 */
	private static $locale_before = null;

	/**
	 * Directory that holds the configs, fixtures, themes and snapshots.
	 *
	 * @return string
	 */
	public static function dir() {
		return __DIR__;
	}

	/**
	 * List the slugs of every numbered config, in numeric order.
	 *
	 * @return string[]
	 */
	public static function list() {
		$slugs = [];
		foreach ( glob( __DIR__ . '/[0-9][0-9]-*.php' ) as $file ) {
			$slugs[] = basename( $file, '.php' );
		}
		sort( $slugs, SORT_STRING );
		return $slugs;
	}

	/**
	 * Load one config, validate its shape and fill in the optional keys.
	 *
	 * @param string $slug Config slug, for example "01-fresh-defaults".
	 * @return array
	 */
	public static function load( $slug ) {
		$file = __DIR__ . '/' . $slug . '.php';
		if ( ! preg_match( '/^[0-9]{2}-[a-z0-9-]+$/', $slug ) || ! is_file( $file ) ) {
			throw new InvalidArgumentException( 'Unknown user config: ' . $slug );
		}
		$config = include $file;
		if ( ! is_array( $config ) || ( $config['slug'] ?? null ) !== $slug ) {
			throw new UnexpectedValueException( 'Config file ' . $slug . ' must return an array whose "slug" matches its file name.' );
		}
		foreach ( [ 'slug', 'represents', 'guards', 'site', 'options', 'posts' ] as $required ) {
			if ( ! array_key_exists( $required, $config ) ) {
				throw new UnexpectedValueException( 'Config ' . $slug . ' is missing the required key "' . $required . '".' );
			}
		}
		if ( ! is_string( $config['represents'] ) || '' === $config['represents'] || ! is_array( $config['guards'] ) || ! is_array( $config['site'] ) || ! is_array( $config['options'] ) || ! is_array( $config['posts'] ) ) {
			throw new UnexpectedValueException( 'Config ' . $slug . ' has a key of the wrong type (represents: string; guards, site, options, posts: arrays).' );
		}

		$config = array_replace(
			[
				'tables'        => [],
				'users'         => [],
				'mu_plugins'    => [],
				'submissions'   => [],
				'http_fixtures' => [],
				'variants'      => [],
				'skip'          => [],
				'mail_capture'  => 'wp_mail',
				'privacy_email' => '',
			],
			$config
		);

		if ( ! in_array( $config['mail_capture'], [ 'wp_mail', 'phpmailer' ], true ) ) {
			throw new UnexpectedValueException( 'Config ' . $slug . ' has an unknown mail_capture "' . $config['mail_capture'] . '" (wp_mail or phpmailer).' );
		}

		foreach ( $config['skip'] as $scenario => $reason ) {
			if ( ! in_array( $scenario, self::SCENARIOS, true ) ) {
				throw new UnexpectedValueException( 'Config ' . $slug . ' skips an unknown scenario "' . $scenario . '".' );
			}
			if ( in_array( $scenario, [ 'S12', 'S13' ], true ) ) {
				throw new UnexpectedValueException( 'Config ' . $slug . ' lists "' . $scenario . '" in skip: S12 and S13 run in the browser (tests/e2e/user-configs.spec.js, bin/run-e2e-configs.mjs) for every config and are not listed.' );
			}
			if ( ! is_string( $reason ) || ! preg_match( '/^pending: R\d\d$/', $reason ) ) {
				throw new UnexpectedValueException( 'Config ' . $slug . ' skip "' . $scenario . '" must be "pending: Rnn", got ' . wp_json_encode( $reason ) . '.' );
			}
		}
		return $config;
	}

	/**
	 * Write a config to the database as raw stored data.
	 *
	 * Order: site settings, users, posts, options, tables, mu-plugin snippets,
	 * then the "swiftforms_user_config_applied" action. Placeholders
	 * "{{post:key}}" and "{{user:key}}" in post content and in option values
	 * become the IDs created earlier in the same call (they use double braces
	 * so they never collide with SwiftForms' own "{post:id}" merge tags).
	 *
	 * @param array  $config      Loaded config.
	 * @param string $variant     Variant name, or an empty string for the base.
	 * @param bool   $environment Install the per-config test environment (mail capture, blocked HTTP, frozen time, example.org). apply-cli.php passes false: wp-env is a real site.
	 */
	public static function apply( array $config, $variant = '', $environment = true ) {
		self::remove_applied();
		self::$post_ids   = [];
		self::$user_ids   = [];
		self::$post_types = [];

		if ( $environment ) {
			self::install_environment( $config );
		}

		self::reset_stored_state();
		self::apply_site( $config['site'] );
		self::apply_roles( (array) ( $config['roles'] ?? [] ) );
		self::apply_users( $config['users'] );
		self::apply_posts( $config['posts'] );

		foreach ( self::effective_options( $config, $variant ) as $name => $value ) {
			if ( null === $value ) {
				continue; // A variant sets an option to null to leave it absent.
			}
			self::write_option( $name, self::resolve_references( $value, true ) );
		}

		// core keeps the placement of widgets in memory after the first read; a site's request starts with it empty.
		$GLOBALS['_wp_sidebars_widgets'] = null;

		self::apply_tables( $config['tables'] );

		foreach ( $config['mu_plugins'] as $snippet ) {
			$path = __DIR__ . '/mu/' . basename( $snippet );
			if ( ! is_file( $path ) ) {
				throw new RuntimeException( 'Unknown mu-plugin snippet: ' . $snippet );
			}
			require_once $path; // Once per process: a variant applies the same config again, and the hooks a snippet adds are still in force.
		}

		/**
		 * Fires after a user config has been written, before the form index is rebuilt.
		 *
		 * Test-only. The rebuild that follows is SwiftForms' own code, run exactly as on a
		 * real site after an update (swiftforms_db_version_changed).
		 *
		 * @param array $config The loaded config.
		 */
		do_action( 'swiftforms_user_config_applied', $config ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- Test-only hook, documented above.

		if ( class_exists( 'SwiftForms\\Form_Index' ) ) {
			SwiftForms\Form_Index::rebuild();
		}
	}

	/**
	 * Remove the posts and users an earlier apply() in this process created (a variant is the same config applied again).
	 */
	private static function remove_applied() {
		if ( ! self::$post_ids && ! self::$user_ids ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array_reverse( self::$post_ids ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( self::$user_ids as $id ) {
			wp_delete_user( (int) $id );
		}
	}

	/**
	 * Start from the stored state of a site that has the six tables and nothing else.
	 *
	 * The plugin installs itself on init, so a test database (or wp-env) already holds
	 * swiftforms_db_version, the install marker and a migration log row. A config
	 * describes the whole stored state, so every swiftforms_* option is removed and
	 * every SwiftForms table is emptied (created first when it is missing) before the
	 * config is written. Rows are deleted, not truncated: TRUNCATE commits, and the
	 * PHPUnit run relies on its transaction to leave the database as it found it.
	 */
	private static function reset_stored_state() {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'swiftforms\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();

		if ( class_exists( 'SwiftForms\\Settings' ) ) {
			SwiftForms\Settings::flush_cache();
		}
		if ( class_exists( 'SwiftForms\\Form_Index' ) ) {
			SwiftForms\Form_Index::flush_cache();
		}
		if ( class_exists( 'SwiftForms\\Database' ) ) {
			SwiftForms\Database::install();
			foreach ( SwiftForms\Database::tables() as $table ) {
				$wpdb->query( 'DELETE FROM `' . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Own table; name built from $wpdb->prefix and a fixed list.
			}
		}
	}

	/**
	 * Post key to ID map for the config applied last.
	 *
	 * @return array<string, int>
	 */
	public static function ids() {
		return self::$post_ids;
	}

	/**
	 * User key to ID map for the config applied last.
	 *
	 * @return array<string, int>
	 */
	public static function user_ids() {
		return self::$user_ids;
	}

	/**
	 * Post key to {id, post_type, edit_url}, the shape apply-cli.php prints (attachments are left out: the editor does not open them).
	 *
	 * @return array<string, array{id:int,post_type:string,edit_url:string}>
	 */
	public static function posts_summary() {
		$summary = [];
		foreach ( self::$post_ids as $key => $id ) {
			if ( 'attachment' === self::$post_types[ $key ] ) {
				continue; // Not something the block editor opens (config 06's logo).
			}
			$summary[ $key ] = [
				'id'        => $id,
				'post_type' => self::$post_types[ $key ],
				'edit_url'  => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			];
		}
		return $summary;
	}

	/**
	 * Mails captured by the pre_wp_mail filter since the last reset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function mails() {
		return self::$mails;
	}

	/**
	 * Forget the mails captured so far.
	 */
	public static function forget_mails() {
		self::$mails = [];
	}

	/**
	 * Resolve the effective options of a config, optionally for a variant.
	 *
	 * @param array  $config  Loaded config.
	 * @param string $variant Variant name, or an empty string for the base.
	 * @return array
	 */
	public static function effective_options( array $config, $variant = '' ) {
		$options = $config['options'];
		if ( '' !== $variant ) {
			if ( ! isset( $config['variants'][ $variant ] ) ) {
				throw new InvalidArgumentException( 'Unknown variant: ' . $variant );
			}
			$options = array_replace( $options, $config['variants'][ $variant ] );
		}
		return $options;
	}

	/**
	 * Write an option exactly as given.
	 *
	 * A swiftforms_* option must have no sanitize_option_<name> filter: if one is
	 * attached, update_option() would silently rewrite the fixture, so the write
	 * is refused and the filter is named.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Option value.
	 */
	public static function write_option( $name, $value ) {
		if ( 0 === strpos( $name, 'swiftforms_' ) && has_filter( 'sanitize_option_' . $name ) ) {
			throw new RuntimeException(
				'Refusing to write ' . $name . ': a sanitize_option_' . $name . ' filter is attached (' . self::describe_filter( 'sanitize_option_' . $name ) . '), so the stored bytes would not be the fixture. A user config is stored data, not a settings save.'
			);
		}
		// The row may exist with an equal value, in which case update_option() is a no-op.
		update_option( $name, $value );
	}

	/**
	 * Install the per-config test environment.
	 *
	 * Mail is captured, external HTTP is blocked (or answered from the config's
	 * http_fixtures), uploads go to a temporary directory, time is frozen at
	 * FROZEN_TIME, randomness is seeded, and home and siteurl are example.org.
	 * reset() undoes all of it.
	 *
	 * @param array $config Loaded config.
	 */
	public static function install_environment( array $config ) {
		if ( self::$env_installed ) {
			self::reset();
		}
		self::$env_installed     = true;
		self::$mails             = [];
		self::$http_fixtures     = (array) $config['http_fixtures'];
		self::$uploads_dir       = sys_get_temp_dir() . '/sf-uploads-' . $config['slug'] . '-' . getmypid();
		self::$env_filters       = [];
		self::$theme_dirs_before = null;

		if ( 'phpmailer' === $config['mail_capture'] ) {
			// wp_mail() runs for real (the sent mail goes to the core test suite's mock mailer), so a config's wp_mail_from and phpmailer_init hooks act on it; the final PHPMailer state is recorded after them.
			self::add_filter(
				'phpmailer_init',
				static function ( $mailer ) {
					self::$mails[] = [
						'via'          => 'phpmailer_init',
						'from'         => $mailer->From, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'from_name'    => $mailer->FromName, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'sender'       => $mailer->Sender, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'mailer'       => $mailer->Mailer, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'to'           => $mailer->getToAddresses(),
						'cc'           => $mailer->getCcAddresses(),
						'bcc'          => $mailer->getBccAddresses(),
						'reply_to'     => $mailer->getReplyToAddresses(),
						'subject'      => $mailer->Subject, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'content_type' => $mailer->ContentType, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'charset'      => $mailer->CharSet, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'body'         => $mailer->Body, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'alt_body'     => $mailer->AltBody, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
						'headers'      => $mailer->getCustomHeaders(),
					];
				},
				PHP_INT_MAX
			);
		} else {
			self::add_filter(
				'pre_wp_mail',
				static function ( $short_circuit, $atts ) {
					self::$mails[] = $atts;
					return true;
				},
				10,
				2
			);
		}
		self::add_filter(
			'pre_http_request',
			static function ( $short_circuit, $args, $url ) {
				if ( isset( self::$http_fixtures[ $url ] ) ) {
					return self::$http_fixtures[ $url ];
				}
				return new WP_Error( 'sf_blocked_http', 'External HTTP is blocked in the user-config suite: ' . $url );
			},
			10,
			3
		);
		self::add_filter(
			'upload_dir',
			static function ( $dirs ) {
				$dirs['basedir'] = self::$uploads_dir;
				$dirs['baseurl'] = 'http://example.org/sf-uploads';
				$dirs['path']    = self::$uploads_dir . $dirs['subdir'];
				$dirs['url']     = 'http://example.org/sf-uploads' . $dirs['subdir'];
				return $dirs;
			}
		);

		if ( class_exists( 'SwiftForms\Clock' ) && ( ( defined( 'SWIFTFORMS_TESTS' ) && SWIFTFORMS_TESTS ) || defined( 'SWIFTFORMS_TEST_NOW' ) ) ) {
			SwiftForms\Clock::freeze( self::FROZEN_TIME );
			SwiftForms\Random::seed( self::SEED );
		}
		mt_srand( self::SEED ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Deterministic fixtures.

		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
	}

	/**
	 * Undo install_environment(), and forget the applied config.
	 */
	public static function reset() {
		foreach ( self::$env_filters as $entry ) {
			remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		self::$env_filters = [];

		if ( class_exists( 'SwiftForms\Clock' ) ) {
			SwiftForms\Clock::unfreeze();
			SwiftForms\Random::unseed();
		}
		if ( '' !== self::$uploads_dir && is_dir( self::$uploads_dir ) ) {
			self::remove_dir( self::$uploads_dir );
		}
		if ( null !== self::$theme_dirs_before ) {
			$GLOBALS['wp_theme_directories'] = self::$theme_dirs_before;
			self::$theme_dirs_before         = null;
			wp_clean_themes_cache();
		}

		if ( null !== self::$locale_before ) {
			if ( self::$locale_before[0] ) {
				$GLOBALS['locale'] = self::$locale_before[1];
			} else {
				unset( $GLOBALS['locale'] );
			}
			self::$locale_before = null;
		}

		self::$uploads_dir   = '';
		self::$mails         = [];
		self::$http_fixtures = [];
		self::$post_ids      = [];
		self::$user_ids      = [];
		self::$post_types    = [];
		self::$env_installed = false;
	}

	/**
	 * Site-wide settings: locale, timezone, permalinks and the active theme.
	 *
	 * @param array $site The config's "site" block.
	 */
	private static function apply_site( array $site ) {
		if ( ! empty( $site['multisite'] ) && ! is_multisite() ) {
			throw new RuntimeException( 'This config is a network install: run it with WP_TESTS_MULTISITE=1 (bin/test-user-configs.sh does for config 19).' );
		}
		if ( ! empty( $site['network_active'] ) && defined( 'SWIFTFORMS_FILE' ) ) {
			// Network-activated: the plugin is in the network's list of active plugins, the way a network admin leaves it.
			update_site_option( 'active_sitewide_plugins', [ plugin_basename( SWIFTFORMS_FILE ) => self::FROZEN_TIME ] );
		}
		if ( isset( $site['locale'] ) && 'en_US' !== $site['locale'] ) {
			// WordPress clears WPLANG when the language pack is not installed; a fixture site has the option stored and the locale in force.
			$keep = static function () use ( $site ) {
				return $site['locale'];
			};
			add_filter( 'sanitize_option_WPLANG', $keep, 99 );
			update_option( 'WPLANG', $site['locale'] );
			remove_filter( 'sanitize_option_WPLANG', $keep, 99 );
			if ( null === self::$locale_before ) {
				self::$locale_before = [ isset( $GLOBALS['locale'] ), $GLOBALS['locale'] ?? null ];
			}
			$GLOBALS['locale'] = $site['locale']; // get_locale() keeps its answer in this global after the first call.
		}
		$timezone = $site['timezone_string'] ?? ( $site['timezone'] ?? '' );
		if ( '' !== $timezone ) {
			update_option( 'timezone_string', $timezone );
		}
		foreach ( [ 'date_format', 'time_format', 'start_of_week', 'show_on_front', 'page_for_posts' ] as $name ) {
			if ( isset( $site[ $name ] ) ) {
				update_option( $name, $site[ $name ] );
			}
		}
		if ( isset( $site['permalinks'] ) ) {
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( $site['permalinks'] );
			$wp_rewrite->flush_rules( false );
		}
		if ( ! empty( $site['theme'] ) ) {
			if ( null === self::$theme_dirs_before ) {
				self::$theme_dirs_before = isset( $GLOBALS['wp_theme_directories'] ) ? (array) $GLOBALS['wp_theme_directories'] : [];
			}
			register_theme_directory( __DIR__ . '/themes' );
			wp_clean_themes_cache();
			if ( ! wp_get_theme( $site['theme'], __DIR__ . '/themes' )->exists() ) {
				throw new RuntimeException( 'Fixture theme not found: ' . $site['theme'] );
			}
			switch_theme( $site['theme'] );
		}
	}

	/**
	 * Create custom roles (stored data: they sit in the wp_user_roles option of a real site).
	 *
	 * @param array $roles Role slug => [ 'name' => display name, 'caps' => [ capability => true ] ].
	 */
	private static function apply_roles( array $roles ) {
		foreach ( $roles as $slug => $role ) {
			remove_role( (string) $slug );
			add_role( (string) $slug, (string) ( $role['name'] ?? $slug ), (array) ( $role['caps'] ?? [] ) );
		}
	}

	/**
	 * Create the users: a list of roles, or a map of key => role. The role "admin" means administrator.
	 *
	 * @param array $users The config's "users" block.
	 */
	private static function apply_users( array $users ) {
		foreach ( $users as $key => $role ) {
			$key = is_int( $key ) ? $role : $key;
			// "admin" is the config's name for the administrator role (WordPress has no role called admin).
			$role = 'admin' === $role ? 'administrator' : $role;
			$id   = wp_insert_user(
				[
					'user_login' => 'sf-' . $key,
					'user_pass'  => 'sf-test-password',
					'user_email' => 'sf-' . $key . '@example.org',
					'role'       => $role,
				]
			);
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( 'Could not create user ' . $key . ': ' . $id->get_error_message() );
			}
			// Pin the ID (see POST_ID_BASE): a plain update of the primary key, which neither commits nor moves the auto-increment counter.
			$fixed = self::USER_ID_BASE + count( self::$user_ids ) + 1;
			global $wpdb;
			$wpdb->update( $wpdb->users, [ 'ID' => $fixed ], [ 'ID' => (int) $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->usermeta, [ 'user_id' => $fixed ], [ 'user_id' => (int) $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_user_cache( (int) $id );
			clean_user_cache( $fixed );
			self::$user_ids[ $key ] = $fixed;
		}
	}

	/**
	 * Insert the posts as stored data.
	 *
	 * Fixture content is stored data, not a save: kses filters and SwiftForms'
	 * save callbacks (wp_insert_post_data, and the form-index hooks on
	 * wp_after_insert_post and transition_post_status) are detached for the insert, and the stored
	 * post_content is read back from the database and compared byte for byte.
	 *
	 * @param array $posts The config's "posts" block.
	 */
	private static function apply_posts( array $posts ) {
		global $wpdb;

		kses_remove_filters();
		$detached = array_merge(
			self::detach_swiftforms_callbacks( 'wp_insert_post_data' ),
			self::detach_swiftforms_callbacks( 'wp_after_insert_post' ),
			self::detach_swiftforms_callbacks( 'transition_post_status' )
		);

		try {
			foreach ( $posts as $post ) {
				$key     = (string) $post['key'];
				$content = self::resolve_references( (string) ( $post['content'] ?? '' ) );
				$args    = [
					'post_type'    => $post['post_type'] ?? 'post',
					'post_status'  => $post['status'] ?? 'publish',
					'post_title'   => $post['title'] ?? $key,
					'post_name'    => $post['slug'] ?? $key,
					'post_content' => $content,
				];
				if ( isset( $post['author'], self::$user_ids[ $post['author'] ] ) ) {
					$args['post_author'] = self::$user_ids[ $post['author'] ];
				}
				if ( isset( $post['mime'] ) ) {
					$args['post_mime_type'] = (string) $post['mime'];
				}
				if ( isset( $post['password'] ) ) {
					$args['post_password'] = (string) $post['password'];
				}
				if ( isset( $post['date'] ) ) {
					// "date" is GMT; the local date follows from the site timezone set above.
					$args['post_date_gmt'] = (string) $post['date'];
					$args['post_date']     = get_date_from_gmt( (string) $post['date'] );
				}
				$args['import_id'] = self::POST_ID_BASE + count( self::$post_ids ) + 1;
				$id                = wp_insert_post( wp_slash( $args ), true );
				if ( is_wp_error( $id ) ) {
					throw new RuntimeException( 'Could not insert post ' . $key . ': ' . $id->get_error_message() );
				}
				foreach ( (array) ( $post['meta'] ?? [] ) as $meta_key => $meta_value ) {
					update_post_meta( $id, $meta_key, $meta_value );
				}
				foreach ( (array) ( $post['terms'] ?? [] ) as $taxonomy => $terms ) {
					wp_set_object_terms( $id, $terms, $taxonomy );
				}
				$stored = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( (string) $stored !== $content ) {
					throw new RuntimeException( 'Post ' . $key . ' was not stored byte-identically (a filter rewrote the fixture content).' );
				}
				self::$post_ids[ $key ]   = (int) $id;
				self::$post_types[ $key ] = (string) $args['post_type'];
			}
		} finally {
			self::reattach_callbacks( $detached );
			kses_init();
		}
	}

	/**
	 * Insert raw rows into SwiftForms tables ("{{post:key}}" in a string column becomes the post ID).
	 *
	 * @param array $tables Table name (without prefix) => list of rows.
	 */
	private static function apply_tables( array $tables ) {
		global $wpdb;
		foreach ( $tables as $table => $rows ) {
			$full = $wpdb->prefix . $table;
			if ( $full !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				throw new RuntimeException( 'Table ' . $full . ' does not exist yet; the config cannot store rows in it.' );
			}
			// Rows go in as multi-row INSERTs of up to 500 rows with the same columns (a config may hold thousands).
			$batch   = [];
			$columns = '';
			$flush   = static function () use ( &$batch, &$columns, $wpdb, $full ) {
				if ( ! $batch ) {
					return;
				}
				$names  = array_keys( $batch[0] );
				$tuples = [];
				foreach ( $batch as $row ) {
					$cells = [];
					foreach ( $row as $value ) {
						if ( null === $value ) {
							$cells[] = 'NULL';
						} elseif ( is_int( $value ) ) {
							$cells[] = (string) $value;
						} else {
							$cells[] = "'" . $wpdb->_real_escape( (string) $value ) . "'"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped with the connection's own escaper.
						}
					}
					$tuples[] = '(' . implode( ',', $cells ) . ')';
				}
				$sql = 'INSERT INTO `' . $full . '` (`' . implode( '`,`', $names ) . '`) VALUES ' . implode( ',', $tuples );
				if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixture rows, escaped above.
					throw new RuntimeException( 'Could not insert rows into ' . $full . ': ' . $wpdb->last_error );
				}
				$batch = [];
			};
			foreach ( $rows as $row ) {
				$row = self::resolve_references( $row );
				$key = implode( ',', array_keys( $row ) );
				if ( $batch && ( $key !== $columns || count( $batch ) >= 500 ) ) {
					$flush();
				}
				$columns = $key;
				$batch[] = $row;
			}
			$flush();
		}
	}

	/**
	 * Replace "{{post:key}}" and "{{user:key}}" in strings, recursively.
	 *
	 * Unknown keys are left as they are. In an option value (typed), a string that is exactly one
	 * placeholder becomes the integer ID, because a setting such as email.logo_id is an integer.
	 *
	 * @param mixed $value Any value.
	 * @param bool  $typed Whether the value is an option value.
	 * @return mixed
	 */
	private static function resolve_references( $value, $typed = false ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::resolve_references( $item, $typed );
			}
			return $value;
		}
		if ( ! is_string( $value ) || false === strpos( $value, '{{' ) ) {
			return $value;
		}
		if ( $typed && 1 === preg_match( '/^\{\{(post|user):([a-z0-9_-]+)\}\}$/i', $value, $exact ) ) {
			$map = 'post' === $exact[1] ? self::$post_ids : self::$user_ids;
			return isset( $map[ $exact[2] ] ) ? $map[ $exact[2] ] : $value;
		}
		return preg_replace_callback(
			'/\{\{(post|user):([a-z0-9_-]+)\}\}/i',
			static function ( $m ) {
				$map = 'post' === $m[1] ? self::$post_ids : self::$user_ids;
				return isset( $map[ $m[2] ] ) ? (string) $map[ $m[2] ] : $m[0];
			},
			$value
		);
	}

	/**
	 * Detach every callback on a hook that lives in the SwiftForms\ namespace.
	 *
	 * The prefixed frameworks (SwiftForms\Vendor\) are left alone.
	 *
	 * @param string $hook Hook name.
	 * @return array<int, array{0:string,1:mixed,2:int,3:int}> Hook, callback, priority, accepted args.
	 */
	private static function detach_swiftforms_callbacks( $hook ) {
		global $wp_filter;
		$detached = [];
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return $detached;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( self::is_swiftforms_callback( $entry['function'] ) ) {
					$detached[] = [ $hook, $entry['function'], (int) $priority, (int) $entry['accepted_args'] ];
				}
			}
		}
		foreach ( $detached as $item ) {
			remove_filter( $item[0], $item[1], $item[2] );
		}
		return $detached;
	}

	/**
	 * Put detached callbacks back with their original priority and accepted args.
	 *
	 * @param array $detached Result of detach_swiftforms_callbacks().
	 */
	private static function reattach_callbacks( array $detached ) {
		foreach ( $detached as $item ) {
			add_filter( $item[0], $item[1], $item[2], $item[3] );
		}
	}

	/**
	 * Whether a callback is a function, method or closure in the SwiftForms\ namespace.
	 *
	 * The prefixed frameworks (SwiftForms\Vendor\) are not ours, and which of them
	 * hook anything depends on the PHP version, so they never count.
	 *
	 * @param mixed $callback A WordPress callback.
	 * @return bool
	 */
	public static function is_swiftforms_callback( $callback ) {
		$name = '';
		if ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			$scope      = $reflection->getClosureScopeClass();
			$name       = $reflection->getNamespaceName() . '\\' . ( $scope ? $scope->getName() : '' );
		} elseif ( is_array( $callback ) ) {
			$name = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		} elseif ( is_object( $callback ) ) {
			$name = get_class( $callback );
		} elseif ( is_string( $callback ) ) {
			$name = $callback;
		}
		$name = ltrim( $name, '\\' );
		return 0 === strpos( $name, 'SwiftForms\\' ) && 0 !== strpos( $name, 'SwiftForms\\Vendor\\' );
	}

	/**
	 * Name the callbacks on a hook, for error messages.
	 *
	 * @param string $hook Hook name.
	 * @return string
	 */
	private static function describe_filter( $hook ) {
		global $wp_filter;
		$names = [];
		foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$function = $entry['function'];
				if ( is_array( $function ) ) {
					$function = ( is_object( $function[0] ) ? get_class( $function[0] ) : $function[0] ) . '::' . $function[1];
				} elseif ( $function instanceof Closure ) {
					$function = 'Closure';
				}
				$names[] = $function . ' @' . $priority;
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Add a filter and remember it for reset().
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of arguments.
	 */
	private static function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		add_filter( $hook, $callback, $priority, $accepted_args );
		self::$env_filters[] = [ $hook, $callback, $priority ];
	}

	/**
	 * Delete a directory tree.
	 *
	 * @param string $dir Directory.
	 */
	private static function remove_dir( $dir ) {
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
