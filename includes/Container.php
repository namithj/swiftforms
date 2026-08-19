<?php
/**
 * Minimal lazy service container.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms;

/**
 * Tiny service locator: register a factory once, resolve (and cache) lazily.
 * No runtime dependency is pulled in for this — it is ~30 lines of PHP.
 */
final class Container {

	/**
	 * Registered factories, keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved singletons, keyed by service id.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = array();

	/**
	 * Registers a factory for a service id.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Receives this container, returns the service instance.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Resolves a service, instantiating and caching it on first access.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 */
	public function get( string $id ) {
		if ( ! array_key_exists( $id, $this->resolved ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \RuntimeException( esc_html( "SwiftForms: no service registered for '{$id}'." ) );
			}

			$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );
		}

		return $this->resolved[ $id ];
	}
}
