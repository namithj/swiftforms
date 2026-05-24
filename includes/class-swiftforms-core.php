<?php
/**
 * Core plugin bootstrap.
 */

declare(strict_types=1);

class SwiftForms_Core {
    /**
     * Shared service container.
     *
     * @var ArrayObject<string, mixed>
     */
    private ArrayObject $container;

    /**
     * Tracks whether init has already executed.
     */
    private bool $initialized = false;

    /**
     * Sets up the shared service container.
     *
     * Tests to create:
     * - test_constructor_seeds_container_services: Pass predefined services and expect them in the container.
     *
     * Expected output:
     * - The container contains the injected services before init runs.
     *
     * @param array<string, mixed> $services Optional services keyed by container id.
     */
    public function __construct(array $services = array()) {
        $this->container = new ArrayObject($services);
    }

    /**
     * Initializes CPTs, blocks, AJAX handlers, and translations.
     *
     * Tests to create:
     * - test_init_registers_cpts: Inject a CPT double, call init(), and expect register() to run once.
     * - test_init_registers_blocks: Inject a block double, call init(), and expect register_blocks() to run once.
     * - test_init_registers_ajax_hooks: Call init() and expect both submission AJAX hooks to resolve to handle_submission().
     *
     * Expected output:
     * - Both registration collaborators are invoked.
     * - wp_ajax_swiftforms_submit and wp_ajax_nopriv_swiftforms_submit are attached.
     */
    public function init(): void {
        if ($this->initialized) {
            return;
        }

        $this->load_textdomain();
        $this->get_cpts()->register();
        $this->get_blocks()->register_blocks();
        $this->register_ajax_actions();

        $this->initialized = true;
    }

    /**
     * Returns the shared service container.
     *
     * Tests to create:
     * - test_get_container_returns_same_instance: Call get_container() twice and expect the same ArrayObject instance.
     * - test_get_container_persists_runtime_changes: Store a value in the returned container and expect it to be available on the next read.
     *
     * Expected output:
     * - Both calls return the same container object.
     * - Runtime container mutations are preserved.
     *
     * @return ArrayObject<string, mixed>
     */
    public function get_container(): ArrayObject {
        return $this->container;
    }

    /**
     * Loads translations for the plugin domain.
     *
     * Tests to create:
     * - test_load_textdomain_uses_swiftforms_domain: Call load_textdomain() and expect no warnings while the swiftforms domain is targeted.
     *
     * Expected output:
     * - The swiftforms text domain is loaded from the plugin languages directory.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'swiftforms',
            false,
            dirname(plugin_basename(SWIFTFORMS_FILE)) . '/languages'
        );
    }

    /**
     * Registers AJAX handlers for authenticated and public submissions.
     *
     * Tests to create:
     * - test_register_ajax_actions_attaches_both_hooks: Call register_ajax_actions() and expect both hooks to resolve to the same submissions handler.
     *
     * Expected output:
     * - Logged-in and logged-out submission actions are available.
     */
    public function register_ajax_actions(): void {
        $handler = array($this->get_submissions(), 'handle_submission');

        add_action('wp_ajax_swiftforms_submit', $handler);
        add_action('wp_ajax_nopriv_swiftforms_submit', $handler);
    }

    /**
     * Returns the CPT registrar service.
     */
    private function get_cpts(): SwiftForms_CPTs {
        $service = $this->get_service(
            'cpts',
            static fn (): SwiftForms_CPTs => new SwiftForms_CPTs()
        );

        return $service;
    }

    /**
     * Returns the block registrar service.
     */
    private function get_blocks(): SwiftForms_Blocks {
        $service = $this->get_service(
            'blocks',
            static fn (): SwiftForms_Blocks => new SwiftForms_Blocks(SWIFTFORMS_PATH)
        );

        return $service;
    }

    /**
     * Returns the submissions service.
     */
    private function get_submissions(): SwiftForms_Submissions {
        $service = $this->get_service(
            'submissions',
            static fn (): SwiftForms_Submissions => new SwiftForms_Submissions()
        );

        return $service;
    }

    /**
     * Lazily resolves and caches services inside the container.
     *
     * @template T of object
     *
     * @param string   $key     Service container key.
     * @param callable $factory Service factory.
     *
     * @return T
     */
    private function get_service(string $key, callable $factory): object {
        if (!$this->container->offsetExists($key)) {
            $this->container->offsetSet($key, $factory());
        }

        /** @var T $service */
        $service = $this->container->offsetGet($key);

        return $service;
    }
}