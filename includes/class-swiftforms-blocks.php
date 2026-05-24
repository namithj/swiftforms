<?php
/**
 * Block registration for SwiftForms.
 */

declare(strict_types=1);

class SwiftForms_Blocks {
    /**
     * Absolute plugin path.
     */
    private string $plugin_path;

    /**
     * Registered frontend view script handles.
     *
     * @var string[]
     */
    private array $frontend_script_handles = array();

    /**
     * Stores the plugin path used to locate block metadata.
     *
     * Tests to create:
     * - test_constructor_sets_plugin_path: Instantiate the class and expect get_block_metadata_paths() to resolve from the provided base path.
     *
     * Expected output:
     * - Metadata discovery uses the injected plugin root.
     */
    public function __construct(string $plugin_path) {
        $this->plugin_path = rtrim($plugin_path, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Registers all supported block types from block.json metadata.
     *
     * Tests to create:
     * - test_register_blocks_registers_form_block: Call register_blocks() and expect the block registry to contain swiftforms/form.
     * - test_register_blocks_registers_text_field_block: Call register_blocks() and expect the block registry to contain swiftforms/fields/text.
     * - test_register_blocks_registers_email_field_block: Call register_blocks() and expect the block registry to contain swiftforms/fields/email.
    * - test_enqueue_frontend_settings_attaches_runtime_data: Register blocks, enqueue scripts, and expect the form view script to receive ajax URL and nonce settings.
     *
     * Expected output:
     * - All shipped metadata directories produce registered blocks.
     */
    public function register_blocks(): void {
        foreach ($this->get_block_metadata_paths() as $metadata_path) {
            if (!file_exists($metadata_path . '/block.json')) {
                continue;
            }

            $metadata = wp_json_file_decode($metadata_path . '/block.json', array('associative' => true));
            $block_name = is_array($metadata) && isset($metadata['name']) ? (string) $metadata['name'] : '';

            if ('' !== $block_name && WP_Block_Type_Registry::get_instance()->is_registered($block_name)) {
                continue;
            }

            $block_type = register_block_type_from_metadata($metadata_path);

            if ($block_type instanceof WP_Block_Type && !empty($block_type->view_script_handles)) {
                $this->frontend_script_handles = array_values(
                    array_unique(
                        array_merge($this->frontend_script_handles, $block_type->view_script_handles)
                    )
                );
            }
        }

        if (!empty($this->frontend_script_handles)) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_settings'));
        }
    }

    /**
     * Injects frontend runtime configuration before the form view script executes.
     *
     * Tests to create:
     * - test_enqueue_frontend_settings_attaches_runtime_data: Enqueue the registered form view script and expect window.swiftformsSettings to be injected.
     *
     * Expected output:
     * - The frontend script receives admin-ajax.php, the AJAX action, and a nonce.
     */
    public function enqueue_frontend_settings(): void {
        $config = wp_json_encode(
            array(
                'action' => 'swiftforms_submit',
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('swiftforms_ajax'),
            )
        );

        if (!$config) {
            return;
        }

        foreach ($this->frontend_script_handles as $handle) {
            wp_add_inline_script($handle, 'window.swiftformsSettings = ' . $config . ';', 'before');
        }
    }

    /**
     * Returns metadata directories for the first shipped block set.
     *
     * Tests to create:
     * - test_get_block_metadata_paths_lists_known_blocks: Call get_block_metadata_paths() and expect form, text, and email directories.
     *
     * Expected output:
     * - The array contains the three scaffolded block metadata paths.
     *
     * @return string[]
     */
    public function get_block_metadata_paths(): array {
        return array(
            $this->plugin_path . 'src/blocks/form',
            $this->plugin_path . 'src/blocks/fields/file',
            $this->plugin_path . 'src/blocks/fields/text',
            $this->plugin_path . 'src/blocks/fields/email',
            $this->plugin_path . 'src/blocks/fields/textarea',
            $this->plugin_path . 'src/blocks/fields/url',
        );
    }
}