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
	 * @param string $slug Config slug, for example "00-harness-selftest".
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
			],
			$config
		);

		foreach ( $config['skip'] as $scenario => $reason ) {
			if ( ! in_array( $scenario, self::SCENARIOS, true ) ) {
				throw new UnexpectedValueException( 'Config ' . $slug . ' skips an unknown scenario "' . $scenario . '".' );
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
		self::$post_ids   = [];
		self::$user_ids   = [];
		self::$post_types = [];

		if ( $environment ) {
			self::install_environment( $config );
		}

		self::apply_site( $config['site'] );
		self::apply_users( $config['users'] );
		self::apply_posts( $config['posts'] );

		foreach ( self::effective_options( $config, $variant ) as $name => $value ) {
			self::write_option( $name, self::resolve_references( $value ) );
		}

		self::apply_tables( $config['tables'] );

		foreach ( $config['mu_plugins'] as $snippet ) {
			$path = __DIR__ . '/mu/' . basename( $snippet );
			if ( ! is_file( $path ) ) {
				throw new RuntimeException( 'Unknown mu-plugin snippet: ' . $snippet );
			}
			require $path;
		}

		/**
		 * Fires after a user config has been written.
		 *
		 * Test-only. R01 hooks the form-index rebuild in here; the real rebuild is
		 * SwiftForms' own code, run exactly as on a real site after an update.
		 *
		 * @param array $config The loaded config.
		 */
		do_action( 'swiftforms_user_config_applied', $config ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- Test-only hook, documented above.
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
	 * Post key to {id, post_type, edit_url}, the shape apply-cli.php prints.
	 *
	 * @return array<string, array{id:int,post_type:string,edit_url:string}>
	 */
	public static function posts_summary() {
		$summary = [];
		foreach ( self::$post_ids as $key => $id ) {
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

		self::add_filter(
			'pre_wp_mail',
			static function ( $short_circuit, $atts ) {
				self::$mails[] = $atts;
				return true;
			},
			10,
			2
		);
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
		if ( isset( $site['locale'] ) && 'en_US' !== $site['locale'] ) {
			update_option( 'WPLANG', $site['locale'] );
		}
		$timezone = $site['timezone_string'] ?? ( $site['timezone'] ?? '' );
		if ( '' !== $timezone ) {
			update_option( 'timezone_string', $timezone );
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
	 * Create the users: a list of roles, or a map of key => role.
	 *
	 * @param array $users The config's "users" block.
	 */
	private static function apply_users( array $users ) {
		foreach ( $users as $key => $role ) {
			$key = is_int( $key ) ? $role : $key;
			$id  = wp_insert_user(
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
			self::$user_ids[ $key ] = (int) $id;
		}
	}

	/**
	 * Insert the posts as stored data.
	 *
	 * Fixture content is stored data, not a save: kses filters and SwiftForms'
	 * wp_insert_post_data callbacks are detached for the insert, and the stored
	 * post_content is read back from the database and compared byte for byte.
	 *
	 * @param array $posts The config's "posts" block.
	 */
	private static function apply_posts( array $posts ) {
		global $wpdb;

		kses_remove_filters();
		$detached = self::detach_swiftforms_callbacks( 'wp_insert_post_data' );

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
				$id = wp_insert_post( wp_slash( $args ), true );
				if ( is_wp_error( $id ) ) {
					throw new RuntimeException( 'Could not insert post ' . $key . ': ' . $id->get_error_message() );
				}
				$stored = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $stored !== $content ) {
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
	 * Insert raw rows into SwiftForms tables.
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
			foreach ( $rows as $row ) {
				if ( false === $wpdb->insert( $full, $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					throw new RuntimeException( 'Could not insert a row into ' . $full . ': ' . $wpdb->last_error );
				}
			}
		}
	}

	/**
	 * Replace "{{post:key}}" and "{{user:key}}" in strings, recursively.
	 *
	 * Unknown keys are left as they are.
	 *
	 * @param mixed $value Any value.
	 * @return mixed
	 */
	private static function resolve_references( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ __CLASS__, 'resolve_references' ], $value );
		}
		if ( ! is_string( $value ) || false === strpos( $value, '{{' ) ) {
			return $value;
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
