<?php
/**
 * File: src/shared/includes/class-service-container.php
 * Lightweight dependency injection container for Client Sync.
 *
 * Manages service creation and lifecycle. Supports both transient and
 * singleton bindings. Existing code can continue using `new ClassName()`
 * while new code gradually adopts container-based resolution.
 *
 * Usage:
 *   $container = Service_Container::get_instance();
 *   $container->singleton( Database_Manager::class, fn() => new Database_Manager() );
 *   $db = $container->make( Database_Manager::class );
 *
 * @package    ClientSync
 */

namespace DependentMedia\ClientSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Service_Container {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Factory callables keyed by abstract name.
	 *
	 * @var array<string, callable>
	 */
	private $bindings = [];

	/**
	 * Resolved singleton instances.
	 *
	 * @var array<string, object>
	 */
	private $instances = [];

	/**
	 * Which bindings are singletons.
	 *
	 * @var array<string, bool>
	 */
	private $singletons = [];

	/**
	 * Get the global container instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a transient binding (new instance each time).
	 *
	 * @param string   $abstract The class or interface name to bind.
	 * @param callable $factory  A callable that returns the instance.
	 */
	public function bind( string $abstract, callable $factory ): void {
		$this->bindings[ $abstract ] = $factory;
		unset( $this->singletons[ $abstract ], $this->instances[ $abstract ] );
	}

	/**
	 * Register a singleton binding (resolved once, reused thereafter).
	 *
	 * @param string   $abstract The class or interface name to bind.
	 * @param callable $factory  A callable that returns the instance.
	 */
	public function singleton( string $abstract, callable $factory ): void {
		$this->bindings[ $abstract ]   = $factory;
		$this->singletons[ $abstract ] = true;
		unset( $this->instances[ $abstract ] );
	}

	/**
	 * Register an already-constructed instance as a singleton.
	 *
	 * @param string $abstract The class or interface name.
	 * @param object $instance The pre-built instance.
	 */
	public function instance( string $abstract, object $instance ): void {
		$this->instances[ $abstract ]  = $instance;
		$this->singletons[ $abstract ] = true;
	}

	/**
	 * Resolve an instance from the container.
	 *
	 * @param string $abstract The class or interface name to resolve.
	 * @return object The resolved instance.
	 * @throws \RuntimeException If the binding is not registered.
	 */
	public function make( string $abstract ): object {
		// Return cached singleton if already resolved.
		if ( isset( $this->instances[ $abstract ] ) ) {
			return $this->instances[ $abstract ];
		}

		if ( ! isset( $this->bindings[ $abstract ] ) ) {
			throw new \RuntimeException( "Service not registered: {$abstract}" );
		}

		$instance = ( $this->bindings[ $abstract ] )( $this );

		// Cache singletons for future calls.
		if ( ! empty( $this->singletons[ $abstract ] ) ) {
			$this->instances[ $abstract ] = $instance;
		}

		return $instance;
	}

	/**
	 * Check if a binding is registered.
	 *
	 * @param string $abstract The class or interface name.
	 * @return bool
	 */
	public function has( string $abstract ): bool {
		return isset( $this->bindings[ $abstract ] ) || isset( $this->instances[ $abstract ] );
	}

	/**
	 * Reset the container (primarily for testing).
	 */
	public function flush(): void {
		$this->bindings   = [];
		$this->instances  = [];
		$this->singletons = [];
	}

	/**
	 * Reset the singleton instance (primarily for testing).
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}
}
