<?php
/**
 * Block registration for SwiftForms.
 */

declare(strict_types=1);

class SwiftForms_Blocks {
    /**
     * Form embed block name.
     */
    private const FORM_BLOCK_NAME = 'swiftforms/form';

    /**
     * Field blocks that belong exclusively to the form builder CPT.
     *
     * @var string[]
     */
    private const FIELD_BLOCK_NAMES = array(
        'swiftforms/checkbox-field',
        'swiftforms/email-field',
        'swiftforms/file-field',
        'swiftforms/number-field',
        'swiftforms/select-field',
        'swiftforms/tel-field',
        'swiftforms/text-field',
        'swiftforms/textarea-field',
        'swiftforms/url-field',
    );

    /**
     * Non-field blocks allowed while composing a form post.
     *
     * @var string[]
     */
    private const FORM_BUILDER_BLOCK_NAMES = array(
        'core/buttons',
        'core/column',
        'core/columns',
        'core/group',
        'core/heading',
        'core/list',
        'core/paragraph',
        'core/separator',
        'core/spacer',
    );

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
        add_filter('allowed_block_types_all', array($this, 'filter_allowed_block_types'), 10, 2);

        foreach ($this->get_block_metadata_paths() as $metadata_path) {
            if (!file_exists($metadata_path . '/block.json')) {
                continue;
            }

            $metadata = wp_json_file_decode($metadata_path . '/block.json', array('associative' => true));
            $block_name = is_array($metadata) && isset($metadata['name']) ? (string) $metadata['name'] : '';

            if ('' !== $block_name && WP_Block_Type_Registry::get_instance()->is_registered($block_name)) {
                continue;
            }

            $block_args = array();

            if (self::FORM_BLOCK_NAME === $block_name) {
                $block_args['render_callback'] = array($this, 'render_form_block');
            }

            $block_type = register_block_type_from_metadata($metadata_path, $block_args);

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
     * Restricts field blocks to the form builder CPT while keeping the embed block available elsewhere.
     *
     * Tests to create:
     * - test_filter_allowed_block_types_keeps_builder_blocks_in_form_posts: Pass a form post editor context and expect field blocks plus builder whitelist blocks.
     * - test_filter_allowed_block_types_removes_field_blocks_outside_form_builder: Pass a page editor context and expect swiftforms/form to remain while field blocks are removed.
     *
     * Expected output:
     * - Form posts can compose forms from field and whitelist blocks.
     * - Other post types only see the form embed block, not the raw field blocks.
     *
     * @param bool|string[]           $allowed_block_types Allowed block types for the current editor.
     * @param WP_Block_Editor_Context $editor_context      Current editor context.
     *
     * @return string[]
     */
    public function filter_allowed_block_types(bool|array $allowed_block_types, WP_Block_Editor_Context $editor_context): array {
        $available_blocks = $this->resolve_available_block_types($allowed_block_types);
        $post = $editor_context->post ?? null;

        if ($post instanceof WP_Post && SwiftForms_CPTs::FORM_POST_TYPE === $post->post_type) {
            $allowed = array_merge(self::FIELD_BLOCK_NAMES, self::FORM_BUILDER_BLOCK_NAMES);

            return array_values(array_intersect($available_blocks, $allowed));
        }

        return array_values(array_diff($available_blocks, self::FIELD_BLOCK_NAMES));
    }

    /**
     * Server-renders the selected saved form inside the embed block.
     *
     * Tests to create:
     * - test_render_form_block_outputs_selected_form_content: Create a form post and expect its field block markup inside the rendered wrapper.
     * - test_render_form_block_returns_empty_string_for_invalid_form: Pass a missing formId and expect no output.
     *
     * Expected output:
     * - The embed block outputs the selected form post content inside the frontend form wrapper.
     *
     * @param array<string, mixed> $attributes Embed block attributes.
     */
    public function render_form_block(array $attributes): string {
        $form_id = isset($attributes['formId']) ? (int) $attributes['formId'] : 0;
        if ($form_id <= 0) {
            return '';
        }

        $form_post = get_post($form_id);
        if (!$form_post instanceof WP_Post || SwiftForms_CPTs::FORM_POST_TYPE !== $form_post->post_type) {
            return '';
        }

        $fields_markup = do_blocks((string) $form_post->post_content);
        $wrapper_attributes = 'class="wp-block-swiftforms-form swiftforms-form"';
        $form_settings = SwiftForms_CPTs::get_form_settings($form_id);
        $description = isset($attributes['description']) ? (string) $attributes['description'] : '';
        $admin_recipients = '' !== trim((string) $form_settings['adminRecipients'])
            ? (string) $form_settings['adminRecipients']
            : (isset($attributes['adminRecipients']) ? (string) $attributes['adminRecipients'] : '');
        $admin_subject = '' !== trim((string) $form_settings['adminSubject'])
            ? (string) $form_settings['adminSubject']
            : (isset($attributes['adminSubject']) ? (string) $attributes['adminSubject'] : '');
        $admin_template = '' !== trim((string) $form_settings['adminTemplate'])
            ? (string) $form_settings['adminTemplate']
            : (isset($attributes['adminTemplate']) ? (string) $attributes['adminTemplate'] : '');
        $autoresponder_subject = '' !== trim((string) $form_settings['autoresponderSubject'])
            ? (string) $form_settings['autoresponderSubject']
            : (isset($attributes['autoresponderSubject']) ? (string) $attributes['autoresponderSubject'] : '');
        $autoresponder_template = '' !== trim((string) $form_settings['autoresponderTemplate'])
            ? (string) $form_settings['autoresponderTemplate']
            : (isset($attributes['autoresponderTemplate']) ? (string) $attributes['autoresponderTemplate'] : '');
        $submit_label = '' !== trim((string) $form_settings['submitLabel'])
            ? (string) $form_settings['submitLabel']
            : (isset($attributes['submitLabel']) ? (string) $attributes['submitLabel'] : 'Send message');
        $success_message = '' !== trim((string) $form_settings['successMessage'])
            ? (string) $form_settings['successMessage']
            : (isset($attributes['successMessage']) ? (string) $attributes['successMessage'] : 'Form submitted successfully.');
        $enable_captcha = !empty($form_settings['enableCaptcha']) || !empty($attributes['enableCaptcha']);

        ob_start();
        ?>
        <form
            <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            data-admin-recipients="<?php echo esc_attr($admin_recipients); ?>"
            data-admin-subject="<?php echo esc_attr($admin_subject); ?>"
            data-admin-template="<?php echo esc_attr($admin_template); ?>"
            data-autoresponder-subject="<?php echo esc_attr($autoresponder_subject); ?>"
            data-autoresponder-template="<?php echo esc_attr($autoresponder_template); ?>"
            data-enable-captcha="<?php echo esc_attr($enable_captcha ? '1' : '0'); ?>"
            data-form-id="<?php echo esc_attr((string) $form_id); ?>"
            data-success-message="<?php echo esc_attr($success_message); ?>"
            data-swiftforms-form
            noValidate
        >
            <?php if ('' !== $description) : ?>
                <p class="swiftforms-form__description"><?php echo wp_kses_post($description); ?></p>
            <?php endif; ?>
            <div class="swiftforms-form__status" data-swiftforms-status aria-live="polite"></div>
            <div class="swiftforms-form__fields"><?php echo $fields_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            <input
                aria-hidden="true"
                autoComplete="off"
                class="swiftforms-form__honeypot"
                data-swiftforms-honeypot
                name="swiftforms_hp"
                style="display:none"
                tabindex="-1"
                type="text"
            />
            <button type="submit" class="swiftforms-form__submit"><?php echo esc_html($submit_label); ?></button>
        </form>
        <?php

        return (string) ob_get_clean();
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
            $this->plugin_path . 'src/blocks/fields/checkbox',
            $this->plugin_path . 'src/blocks/form',
            $this->plugin_path . 'src/blocks/fields/file',
            $this->plugin_path . 'src/blocks/fields/number',
            $this->plugin_path . 'src/blocks/fields/select',
            $this->plugin_path . 'src/blocks/fields/tel',
            $this->plugin_path . 'src/blocks/fields/text',
            $this->plugin_path . 'src/blocks/fields/email',
            $this->plugin_path . 'src/blocks/fields/textarea',
            $this->plugin_path . 'src/blocks/fields/url',
        );
    }

    /**
     * Resolves the available block names from the current editor allow-list.
     *
     * @param bool|string[] $allowed_block_types Allowed block types from the editor.
     *
     * @return string[]
     */
    private function resolve_available_block_types(bool|array $allowed_block_types): array {
        if (is_array($allowed_block_types)) {
            return array_values(array_unique($allowed_block_types));
        }

        if (true === $allowed_block_types) {
            return array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered());
        }

        return array();
    }
}