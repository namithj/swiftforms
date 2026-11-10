<?php
/**
 * Reference edges between sources.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Source_Edges
 *
 * Which sources pull in which others (a page shows a synced pattern, a template
 * shows a template part or a file pattern). Liveness (C-3.11) needs the reverse
 * direction: a wp_block is live only while a live source shows it. The edges are
 * derived data, rebuilt from content by Form_Index::rebuild(), and are kept in the
 * option swiftforms_source_edges (autoload no): a map from source to the sorted
 * list of sources it references. The option is created only when there is at least
 * one edge.
 */
class Source_Edges {

	/**
	 * Option that holds the edge map.
	 */
	const OPTION = 'swiftforms_source_edges';

	/**
	 * Whether changes stay in memory until commit() (a rebuild writes the option once).
	 *
	 * @var bool
	 */
	private static $deferred = false;

	/**
	 * Edge map for the rest of the request, or null when not loaded yet.
	 *
	 * @var array<string, string[]>|null
	 */
	private static $map = null;

	/**
	 * Drop the in-memory copy (tests, and after code that wrote the option behind our back).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$map = null;
	}

	/**
	 * The sources a source references.
	 *
	 * @param string $from Source reference.
	 * @return string[]
	 */
	public static function get( $from ) {
		$map = self::load();
		return $map[ $from ] ?? [];
	}

	/**
	 * Whether a source references anything.
	 *
	 * @param string $from Source reference.
	 * @return bool
	 */
	public static function has( $from ) {
		return [] !== self::get( $from );
	}

	/**
	 * Replace the references of a source.
	 *
	 * @param string   $from    Source reference.
	 * @param string[] $targets Sources it references (an empty list removes the entry).
	 * @return bool Whether the stored map changed.
	 */
	public static function set( $from, array $targets ) {
		$map     = self::load();
		$targets = array_values( array_unique( array_filter( array_map( 'strval', $targets ) ) ) );
		sort( $targets, SORT_STRING );

		if ( [] === $targets ) {
			if ( ! isset( $map[ $from ] ) ) {
				return false;
			}
			unset( $map[ $from ] );
		} else {
			if ( isset( $map[ $from ] ) && $map[ $from ] === $targets ) {
				return false;
			}
			$map[ $from ] = $targets;
		}

		self::save( $map );
		return true;
	}

	/**
	 * Remove the references of a source.
	 *
	 * @param string $from Source reference.
	 * @return bool Whether the stored map changed.
	 */
	public static function remove( $from ) {
		return self::set( $from, [] );
	}

	/**
	 * Sources that reference a source directly.
	 *
	 * @param string $to Source reference.
	 * @return string[] Sorted.
	 */
	public static function parents( $to ) {
		$parents = [];
		foreach ( self::load() as $from => $targets ) {
			if ( in_array( $to, $targets, true ) ) {
				$parents[] = (string) $from;
			}
		}
		sort( $parents, SORT_STRING );
		return $parents;
	}

	/**
	 * Every source reachable from a source by following references, up to a number of levels.
	 *
	 * @param string $from  Source reference.
	 * @param int    $limit Levels to follow.
	 * @return string[] Sorted, without the starting source.
	 */
	public static function descendants( $from, $limit = Block_Walker::DEPTH_LIMIT ) {
		$map      = self::load();
		$seen     = [ $from => true ];
		$frontier = [ $from ];
		for ( $level = 0; $level < $limit && $frontier; $level++ ) {
			$next = [];
			foreach ( $frontier as $source ) {
				foreach ( $map[ $source ] ?? [] as $target ) {
					if ( ! isset( $seen[ $target ] ) ) {
						$seen[ $target ] = true;
						$next[]          = $target;
					}
				}
			}
			$frontier = $next;
		}
		unset( $seen[ $from ] );
		$found = array_keys( $seen );
		sort( $found, SORT_STRING );
		return $found;
	}

	/**
	 * Start from an empty map that is only kept in memory until commit().
	 *
	 * @return void
	 */
	public static function begin() {
		self::$deferred = true;
		self::$map      = [];
	}

	/**
	 * Write the map to the option (once) and go back to writing on every change.
	 *
	 * @return void
	 */
	public static function commit() {
		self::$deferred = false;
		self::save( self::load() );
	}

	/**
	 * Forget every edge.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$map = [];
		if ( null !== get_option( self::OPTION, null ) ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Load the map.
	 *
	 * @return array<string, string[]>
	 */
	private static function load() {
		if ( null === self::$map ) {
			$stored    = get_option( self::OPTION, [] );
			self::$map = is_array( $stored ) ? $stored : [];
		}
		return self::$map;
	}

	/**
	 * Keep the map in memory and in the option.
	 *
	 * @param array<string, string[]> $map New map.
	 * @return void
	 */
	private static function save( array $map ) {
		ksort( $map, SORT_STRING );
		self::$map = $map;

		if ( self::$deferred ) {
			return;
		}
		if ( [] === $map ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $map, false );
	}
}
