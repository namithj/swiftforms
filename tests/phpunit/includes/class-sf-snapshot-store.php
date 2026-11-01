<?php // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Test helper: plain file functions are the point; the class name SF_Snapshot_Store is fixed by the R00 plan.
/**
 * Golden-master snapshot files for the user-config regression suite.
 *
 * - A missing snapshot fails. SF_UPDATE_SNAPSHOTS=1 creates it.
 * - A mismatch fails with a unified diff.
 * - SF_UPDATE_SNAPSHOTS=1 is refused in CI.
 *
 * @package SwiftForms
 */

/**
 * Reads, compares and (in update mode) writes snapshot files.
 */
class SF_Snapshot_Store {

	/**
	 * Root directory of the snapshots.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Whether SF_UPDATE_SNAPSHOTS=1 is in effect.
	 *
	 * @var bool
	 */
	private $update;

	/**
	 * Snapshot paths (relative to the root) checked during this run.
	 *
	 * @var string[]
	 */
	private $seen = [];

	/**
	 * Number of files written in update mode.
	 *
	 * @var int
	 */
	private $written = 0;

	/**
	 * Constructor.
	 *
	 * @param string $root Snapshot root directory.
	 */
	public function __construct( $root ) {
		$this->root   = rtrim( $root, '/' );
		$this->update = '1' === getenv( 'SF_UPDATE_SNAPSHOTS' );
		if ( $this->update && ( getenv( 'CI' ) || getenv( 'GITHUB_ACTIONS' ) ) ) {
			throw new RuntimeException( 'SF_UPDATE_SNAPSHOTS=1 is forbidden in CI. Regenerate snapshots locally and list the diff under "Intended snapshot changes" in the release plan.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message.
		}
	}

	/**
	 * Is update mode on?
	 *
	 * @return bool
	 */
	public function is_update_mode() {
		return $this->update;
	}

	/**
	 * Number of snapshot files written (update mode only).
	 *
	 * @return int
	 */
	public function written() {
		return $this->written;
	}

	/**
	 * Compare actual text with the stored snapshot.
	 *
	 * @param string $relative Path relative to the root, for example "00-harness-selftest/apply.txt".
	 * @param string $actual   The text produced by this run.
	 * @return string|null A failure message, or null when the snapshot matches.
	 */
	public function check( $relative, $actual ) {
		$this->seen[] = $relative;
		$path         = $this->root . '/' . $relative;

		if ( $this->update ) {
			if ( ! is_file( $path ) || file_get_contents( $path ) !== $actual ) {
				if ( ! is_dir( dirname( $path ) ) ) {
					mkdir( dirname( $path ), 0777, true );
				}
				file_put_contents( $path, $actual );
				++$this->written;
			}
			return null;
		}

		if ( ! is_file( $path ) ) {
			return 'Missing snapshot ' . $relative . '. Run with SF_UPDATE_SNAPSHOTS=1 to create it.';
		}
		$expected = file_get_contents( $path );
		if ( $expected === $actual ) {
			return null;
		}
		return 'Snapshot ' . $relative . " changed\n" . self::diff( $expected, $actual );
	}

	/**
	 * Find snapshot files in a directory that this run did not produce.
	 *
	 * In update mode they are deleted. Otherwise they are reported.
	 *
	 * @param string $relative_dir Directory relative to the root.
	 * @return string[] Stale paths relative to the root.
	 */
	public function stale( $relative_dir ) {
		$dir   = $this->root . '/' . $relative_dir;
		$stale = [];
		if ( ! is_dir( $dir ) ) {
			return $stale;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$relative = ltrim( substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $this->root ) ), '/' );
			if ( ! in_array( $relative, $this->seen, true ) ) {
				$stale[] = $relative;
			}
		}
		sort( $stale );
		if ( $this->update ) {
			foreach ( $stale as $relative ) {
				unlink( $this->root . '/' . $relative );
			}
			return [];
		}
		return $stale;
	}

	/**
	 * Unified diff of two texts, capped at 80 lines, with carriage returns made visible.
	 *
	 * @param string $expected Stored text.
	 * @param string $actual   New text.
	 * @return string
	 */
	public static function diff( $expected, $actual ) {
		$differ = new SebastianBergmann\Diff\Differ(
			new SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder( "--- expected (snapshot)\n+++ actual (this run)\n" )
		);
		$diff   = $differ->diff( str_replace( "\r", "\u{240D}", $expected ), str_replace( "\r", "\u{240D}", $actual ) );
		$lines  = explode( "\n", $diff );
		if ( count( $lines ) > 80 ) {
			$omitted = count( $lines ) - 80;
			$lines   = array_slice( $lines, 0, 80 );
			$lines[] = '... (' . $omitted . ' more diff lines omitted)';
		}
		return implode( "\n", $lines );
	}
}
