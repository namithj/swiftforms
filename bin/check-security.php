<?php // phpcs:disable WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.PrefixAllGlobals, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.DevelopmentFunctions -- Command-line tool: it prints plain text, reads local files and has its own small function names.
/**
 * Security lint (F004): a token scan of the plugin's PHP.
 *
 *   php bin/check-security.php                 scan includes/ and swiftforms.php
 *   php bin/check-security.php path [path ...] scan files or folders
 *   php bin/check-security.php --self-test     run the fixtures in tests/security-lint/fixtures
 *
 * Rules (a violation exits with status 1):
 *   R1  register_rest_route() without a permission_callback (only Rest_Public::register() may pass it on in $args)
 *   R2  __return_true outside includes/rest/class-rest-public.php (the public route allow-list)
 *   R3  wp_ajax_nopriv_ or admin_post_nopriv_ handlers (SwiftForms has no handler for guests)
 *   R4  wp_ajax_ or admin_post_ handlers in a file without both a nonce check and a capability check
 *   R5  $wpdb->query/get_results/get_row/get_var/get_col whose query is not $wpdb->prepare(...)
 *
 * Allow-lists, each with the reason:
 *   - wp_ajax_swiftforms_rest_nonce: cookie-authenticated, logged-in only, changes nothing and returns a nonce for
 *     the REST header, so it has neither a nonce nor a capability check.
 *   - R5: a line (or the line above) carrying the comment "sf-allow-unprepared" for a query without any input.
 *
 * Comments are ignored, so a sentence that mentions a rule does not trip it.
 *
 * @package SwiftForms
 */

const SF_LINT_ALLOWED_AJAX    = [ 'wp_ajax_swiftforms_rest_nonce' ];
const SF_LINT_PUBLIC_ROUTES   = 'includes/rest/class-rest-public.php';
const SF_LINT_QUERY_METHODS   = [ 'query', 'get_results', 'get_row', 'get_var', 'get_col' ];
const SF_LINT_NONCE_FUNCTIONS = [ 'check_ajax_referer', 'check_admin_referer', 'wp_verify_nonce' ];

/**
 * Scan one file's source.
 *
 * @param string $source Source code.
 * @param string $name   Path relative to the plugin root, with forward slashes.
 * @return array<int, array{rule:string, line:int, message:string}>
 */
function sf_lint_source( $source, $name ) {
	$raw    = explode( "\n", $source );
	$tokens = [];
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_INLINE_HTML ], true ) ) {
				continue;
			}
			$tokens[] = [ $token[0], $token[1], $token[2] ];
		} else {
			$tokens[] = [ null, $token, end( $tokens ) ? end( $tokens )[2] : 1 ];
		}
	}

	$found     = [];
	$count     = count( $tokens );
	$has_nonce = false;
	$has_cap   = false;
	$handlers  = [];
	$is_public = substr( $name, -strlen( SF_LINT_PUBLIC_ROUTES ) ) === SF_LINT_PUBLIC_ROUTES;

	for ( $i = 0; $i < $count; $i++ ) {
		$text = $tokens[ $i ][1];
		$line = $tokens[ $i ][2];
		$id   = $tokens[ $i ][0];
		$bare = trim( $text, '\'"' );

		if ( T_STRING === $id && in_array( $text, SF_LINT_NONCE_FUNCTIONS, true ) ) {
			$has_nonce = true;
		}
		if ( ( T_STRING === $id && 'current_user_can' === $text ) || ( T_STRING === $id && 'Access' === $text && isset( $tokens[ $i + 2 ] ) && 'can' === $tokens[ $i + 2 ][1] ) ) {
			$has_cap = true;
		}

		// R2.
		if ( '__return_true' === $bare && ! $is_public ) {
			$found[] = sf_lint_hit( 'R2', $line, '__return_true is allowed only in ' . SF_LINT_PUBLIC_ROUTES . '.' );
		}

		// R3 and the handler list for R4: the hook name is a string.
		if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
			if ( 0 === strpos( $bare, 'wp_ajax_nopriv_' ) || 0 === strpos( $bare, 'admin_post_nopriv_' ) ) {
				$found[] = sf_lint_hit( 'R3', $line, 'A handler for guests (' . $bare . ') is not allowed.' );
			} elseif ( ( 0 === strpos( $bare, 'wp_ajax_' ) || 0 === strpos( $bare, 'admin_post_' ) ) && ! in_array( $bare, SF_LINT_ALLOWED_AJAX, true ) ) {
				$handlers[] = [ $bare, $line ];
			}
		}

		// R1.
		if ( T_STRING === $id && 'register_rest_route' === $text && isset( $tokens[ $i + 1 ] ) && '(' === $tokens[ $i + 1 ][1] && ! sf_lint_is_definition( $tokens, $i ) && ! $is_public ) {
			$depth  = 0;
			$has_pc = false;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( '(' === $tokens[ $j ][1] || '[' === $tokens[ $j ][1] ) {
					++$depth;
				} elseif ( ')' === $tokens[ $j ][1] || ']' === $tokens[ $j ][1] ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( 'permission_callback' === trim( $tokens[ $j ][1], '\'"' ) ) {
					$has_pc = true;
				}
			}
			if ( ! $has_pc ) {
				$found[] = sf_lint_hit( 'R1', $line, 'register_rest_route() without a permission_callback.' );
			}
		}

		// R5: a call of a query method on the global database object.
		if ( T_VARIABLE === $id && '$wpdb' === $text && isset( $tokens[ $i + 3 ] ) && '->' === $tokens[ $i + 1 ][1] && in_array( $tokens[ $i + 2 ][1], SF_LINT_QUERY_METHODS, true ) && '(' === $tokens[ $i + 3 ][1] ) {
			$prepared = isset( $tokens[ $i + 6 ] ) && '$wpdb' === $tokens[ $i + 4 ][1] && '->' === $tokens[ $i + 5 ][1] && 'prepare' === $tokens[ $i + 6 ][1];
			$allowed  = false !== strpos( (string) ( $raw[ $line - 1 ] ?? '' ), 'sf-allow-unprepared' ) || false !== strpos( (string) ( $raw[ $line - 2 ] ?? '' ), 'sf-allow-unprepared' );
			if ( ! $prepared && ! $allowed ) {
				$found[] = sf_lint_hit( 'R5', $line, '$wpdb->' . $tokens[ $i + 2 ][1] . '() without $wpdb->prepare().' );
			}
		}
	}

	// R4.
	if ( $handlers && ! ( $has_nonce && $has_cap ) ) {
		foreach ( $handlers as $handler ) {
			$found[] = sf_lint_hit( 'R4', $handler[1], $handler[0] . ' handler in a file without both a nonce check and a capability check.' );
		}
	}

	return $found;
}

/**
 * Whether the token at $i is the name in "function register_rest_route(" or a method call.
 *
 * @param array $tokens Tokens.
 * @param int   $i      Index of the name.
 * @return bool
 */
function sf_lint_is_definition( array $tokens, $i ) {
	$before = $tokens[ $i - 1 ][1] ?? '';
	return in_array( $before, [ 'function', '->', '::' ], true );
}

/**
 * A violation record.
 *
 * @param string $rule    Rule ID.
 * @param int    $line    Line.
 * @param string $message Message.
 * @return array{rule:string, line:int, message:string}
 */
function sf_lint_hit( $rule, $line, $message ) {
	return [
		'rule'    => $rule,
		'line'    => (int) $line,
		'message' => $message,
	];
}

/**
 * PHP files under the given paths.
 *
 * @param string[] $paths Files or folders.
 * @return string[]
 */
function sf_lint_files( array $paths ) {
	$files = [];
	foreach ( $paths as $path ) {
		if ( is_dir( $path ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		} elseif ( is_file( $path ) ) {
			$files[] = $path;
		}
	}
	sort( $files );
	return $files;
}

/**
 * Run the fixtures. A fixture names the rules it must trip on its first line: "// expect: R1,R5" (or "// expect: none").
 *
 * @param string $root Plugin root.
 * @return array{fixtures:int, failures:string[]}
 */
function sf_lint_self_test( $root ) {
	$failures = [];
	$fixtures = glob( $root . '/tests/security-lint/fixtures/*.txt' );
	$fixtures = $fixtures ? $fixtures : [];
	if ( ! $fixtures ) {
		$failures[] = 'No fixtures found.';
	}
	foreach ( $fixtures as $fixture ) {
		$source = (string) file_get_contents( $fixture );
		if ( 1 !== preg_match( '#// expect: ([A-Z0-9,]+|none)#', $source, $match ) ) {
			$failures[] = basename( $fixture ) . ': no expect line.';
			continue;
		}
		$expected = 'none' === $match[1] ? [] : explode( ',', $match[1] );
		$actual   = array_values( array_unique( array_column( sf_lint_source( $source, 'includes/fixture.php' ), 'rule' ) ) );
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			$failures[] = basename( $fixture ) . ': expected [' . implode( ',', $expected ) . '] got [' . implode( ',', $actual ) . ']';
		}
	}
	return [
		'fixtures' => count( $fixtures ),
		'failures' => $failures,
	];
}

/**
 * Scan files and folders.
 *
 * @param string[] $paths Files or folders.
 * @param string   $root  Plugin root, stripped from the names.
 * @return array{files:int, hits:string[]} Hits are "file:line RULE message".
 */
function sf_lint_run( array $paths, $root ) {
	$files = sf_lint_files( $paths );
	$hits  = [];
	foreach ( $files as $file ) {
		$name = ltrim( str_replace( [ $root, '\\' ], [ '', '/' ], $file ), '/' );
		foreach ( sf_lint_source( (string) file_get_contents( $file ), $name ) as $hit ) {
			$hits[] = $name . ':' . $hit['line'] . ' ' . $hit['rule'] . ' ' . $hit['message'];
		}
	}
	return [
		'files' => count( $files ),
		'hits'  => $hits,
	];
}

// Run as a command; when a test includes this file, only the functions are defined.
if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$sf_root = dirname( __DIR__ );
	$sf_args = array_slice( $argv, 1 );

	if ( in_array( '--self-test', $sf_args, true ) ) {
		$sf_result = sf_lint_self_test( $sf_root );
		foreach ( $sf_result['failures'] as $sf_failure ) {
			fwrite( STDERR, $sf_failure . "\n" );
		}
		if ( $sf_result['failures'] ) {
			exit( 1 );
		}
		fwrite( STDOUT, 'Security lint self-test: ' . $sf_result['fixtures'] . " fixtures OK.\n" );
		exit( 0 );
	}

	$sf_result = sf_lint_run( $sf_args ? $sf_args : [ $sf_root . '/includes', $sf_root . '/swiftforms.php' ], $sf_root );
	foreach ( $sf_result['hits'] as $sf_hit ) {
		fwrite( STDERR, $sf_hit . "\n" );
	}
	if ( $sf_result['hits'] ) {
		fwrite( STDERR, count( $sf_result['hits'] ) . " security lint violation(s).\n" );
		exit( 1 );
	}
	fwrite( STDOUT, 'Security lint: ' . $sf_result['files'] . " files OK.\n" );
	exit( 0 );
}
