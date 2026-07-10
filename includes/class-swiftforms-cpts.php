<?php
/**
 * Custom post type registration.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

/**
 * Registers the form and submission post types and their admin surfaces.
 */
class SwiftForms_CPTs {
	/**
	 * Form post type slug.
	 */
	public const FORM_POST_TYPE = 'swiftforms_form';

	/**
	 * Form settings meta key.
	 */
	public const FORM_SETTINGS_META_KEY = '_sf_settings';

	/**
	 * Submission post type slug.
	 *
	 * The spec name `swiftforms_submission` exceeds WordPress' 20 character limit,
	 * so the runtime slug uses the closest valid form.
	 */
	public const SUBMISSION_POST_TYPE = 'swiftform_entry';

	/**
	 * Maps field block names to the submission field type they produce.
	 *
	 * Note the textarea block deliberately maps to `text`: its saved markup
	 * ships `data-field-type="text"`, so that's what the frontend submits.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_BLOCK_TYPES = array(
		'swiftforms/checkbox-field' => 'checkbox',
		'swiftforms/date-field'     => 'date',
		'swiftforms/email-field'    => 'email',
		'swiftforms/hidden-field'   => 'hidden',
		'swiftforms/file-field'     => 'file',
		'swiftforms/number-field'   => 'number',
		'swiftforms/radio-field'    => 'radio',
		'swiftforms/select-field'   => 'select',
		'swiftforms/tel-field'      => 'tel',
		'swiftforms/text-field'     => 'text',
		'swiftforms/textarea-field' => 'text',
		'swiftforms/url-field'      => 'url',
	);

	/**
	 * Default slugs per field block, mirroring each block.json attribute default.
	 *
	 * Needed because serialized block comments omit attributes that still hold
	 * their default value, and schema derivation must work even when the block
	 * registry isn't populated (e.g. direct unit-test calls).
	 *
	 * @var array<string, string>
	 */
	private const FIELD_DEFAULT_SLUGS = array(
		'swiftforms/checkbox-field' => 'consent',
		'swiftforms/date-field'     => 'date_field',
		'swiftforms/hidden-field'   => 'hidden_field',
		'swiftforms/email-field'    => 'email',
		'swiftforms/file-field'     => 'attachment',
		'swiftforms/number-field'   => 'number_field',
		'swiftforms/radio-field'    => 'radio_field',
		'swiftforms/select-field'   => 'select_field',
		'swiftforms/tel-field'      => 'phone',
		'swiftforms/text-field'     => 'text_field',
		'swiftforms/textarea-field' => 'message',
		'swiftforms/url-field'      => 'website',
	);

	/**
	 * Derives the authoritative field configuration from a form post's blocks.
	 *
	 * This is the server-side source of truth for validation: submitted field
	 * rows carry their own required/min/max/options claims, but those are
	 * client-controlled and must never be trusted for real traffic.
	 *
	 * @param int $form_id Form post ID.
	 *
	 * @return array<string, array<string, mixed>> Field config keyed by slug.
	 */
	public static function get_form_field_schema( int $form_id ): array {
		$form = get_post( $form_id );

		if ( ! $form instanceof WP_Post || self::FORM_POST_TYPE !== $form->post_type ) {
			return array();
		}

		$schema = array();
		self::collect_field_blocks( parse_blocks( (string) $form->post_content ), $schema );

		return $schema;
	}

	/**
	 * Recursively walks parsed blocks collecting field configuration.
	 *
	 * Recursion matters because the form builder allows wrapping fields in
	 * group/columns blocks.
	 *
	 * @param array<int, array<string, mixed>>    $blocks Parsed blocks.
	 * @param array<string, array<string, mixed>> $schema Accumulating schema, keyed by slug.
	 */
	private static function collect_field_blocks( array $blocks, array &$schema ): void {
		foreach ( $blocks as $block ) {
			$block_name = (string) ( $block['blockName'] ?? '' );
			$type       = self::FIELD_BLOCK_TYPES[ $block_name ] ?? '';

			if ( '' !== $type ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$slug  = sanitize_key( (string) ( $attrs['slug'] ?? '' ) );

				if ( '' === $slug ) {
					$slug = self::FIELD_DEFAULT_SLUGS[ $block_name ];
				}

				$schema[ $slug ] = array(
					'type'       => $type,
					'required'   => ! empty( $attrs['required'] ),
					'min'        => isset( $attrs['min'] ) ? (string) $attrs['min'] : '',
					'max'        => isset( $attrs['max'] ) ? (string) $attrs['max'] : '',
					'step'       => isset( $attrs['step'] ) ? (string) $attrs['step'] : '',
					'options'    => isset( $attrs['options'] ) ? $attrs['options'] : '',
					'conditions' => SwiftForms_Conditions::sanitize( $attrs['conditions'] ?? array() ),
				);
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_field_blocks( $block['innerBlocks'], $schema );
			}
		}
	}

	/**
	 * Registers the form and submission custom post types.
	 */
	public function register(): void {
		if ( ! post_type_exists( self::FORM_POST_TYPE ) ) {
			register_post_type( self::FORM_POST_TYPE, $this->get_form_args() );
		}

		if ( ! post_type_exists( self::SUBMISSION_POST_TYPE ) ) {
			register_post_type( self::SUBMISSION_POST_TYPE, $this->get_submission_args() );
		}

		$this->register_form_settings_meta();

		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_form_editor_assets' ) );
		add_filter( 'manage_edit-' . self::SUBMISSION_POST_TYPE . '_columns', array( $this, 'filter_submission_columns' ) );
		add_action( 'manage_' . self::SUBMISSION_POST_TYPE . '_posts_custom_column', array( $this, 'render_submission_column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::SUBMISSION_POST_TYPE, array( $this, 'add_submission_metabox' ) );
		add_filter( 'bulk_actions-edit-' . self::SUBMISSION_POST_TYPE, array( $this, 'filter_submission_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . self::SUBMISSION_POST_TYPE, array( $this, 'handle_submission_bulk_actions' ), 10, 3 );
		add_action( 'restrict_manage_posts', array( $this, 'render_form_filter_dropdown' ) );
		add_action( 'parse_query', array( $this, 'apply_form_filter_query' ) );
		add_action( 'before_delete_post', array( $this, 'delete_submission_uploads' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_field_value_search' ) );
		add_action( 'admin_menu', array( $this, 'add_unread_count_bubble' ), 100 );
		add_filter( 'post_class', array( $this, 'add_unread_row_class' ), 10, 3 );
		add_action( 'admin_head-edit.php', array( $this, 'print_unread_row_styles' ) );
		add_filter( 'manage_edit-' . self::FORM_POST_TYPE . '_columns', array( $this, 'filter_form_columns' ) );
		add_action( 'manage_' . self::FORM_POST_TYPE . '_posts_custom_column', array( $this, 'render_form_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_duplicate_row_action' ), 10, 2 );
		add_action( 'admin_post_swiftforms_duplicate_form', array( $this, 'handle_duplicate_form_action' ) );
	}

	/**
	 * Registers form settings meta so the block editor sidebar can edit it directly.
	 */
	public function register_form_settings_meta(): void {
		register_post_meta(
			self::FORM_POST_TYPE,
			self::FORM_SETTINGS_META_KEY,
			array(
				'auth_callback'     => static fn (): bool => current_user_can( 'edit_posts' ),
				'default'           => self::get_default_form_settings(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_form_settings_meta' ),
				'show_in_rest'      => array(
					'schema' => $this->get_form_settings_meta_schema(),
				),
				'single'            => true,
				'type'              => 'object',
			)
		);
	}

	/**
	 * Sanitizes REST meta values for the form settings object.
	 *
	 * @param mixed $value Raw meta value.
	 *
	 * @return array<string, string|bool>
	 */
	public static function sanitize_form_settings_meta( $value ): array {
		if ( ! is_array( $value ) ) {
			return self::get_default_form_settings();
		}

		return self::sanitize_form_settings( $value );
	}

	/**
	 * Returns the REST schema for the form settings sidebar panel.
	 *
	 * @return array<string, mixed>
	 */
	public function get_form_settings_meta_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'adminRecipients'       => array( 'type' => 'string' ),
				'adminSubject'          => array( 'type' => 'string' ),
				'adminTemplate'         => array( 'type' => 'string' ),
				'autoresponderField'    => array( 'type' => 'string' ),
				'autoresponderSubject'  => array( 'type' => 'string' ),
				'autoresponderTemplate' => array( 'type' => 'string' ),
				'enableCaptcha'         => array( 'type' => 'boolean' ),
				'enableTurnstile'       => array( 'type' => 'boolean' ),
				'redirectUrl'           => array( 'type' => 'string' ),
				'retentionDays'         => array( 'type' => 'integer' ),
				'saveEntries'           => array( 'type' => 'string' ),
				'submitLabel'           => array( 'type' => 'string' ),
				'successMessage'        => array( 'type' => 'string' ),
				'webhookUrl'            => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Enqueues shared form editor assets for the form CPT block editor.
	 */
	public function enqueue_form_editor_assets(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::FORM_POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_path  = SWIFTFORMS_PATH . 'dist/form/settings-panel.asset.php';
		$script_path = SWIFTFORMS_PATH . 'dist/form/settings-panel.js';
		$style_path  = SWIFTFORMS_PATH . 'dist/form/settings-panel.css';

		if ( ! file_exists( $asset_path ) || ! file_exists( $script_path ) ) {
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			'swiftforms-form-settings-panel',
			SWIFTFORMS_URL . 'dist/form/settings-panel.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? SWIFTFORMS_VERSION,
			true
		);

		wp_set_script_translations( 'swiftforms-form-settings-panel', 'swiftforms', SWIFTFORMS_PATH . 'languages' );

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'swiftforms-form-settings-panel',
				SWIFTFORMS_URL . 'dist/form/settings-panel.css',
				array( 'wp-components' ),
				$asset['version'] ?? SWIFTFORMS_VERSION
			);

			wp_style_add_data( 'swiftforms-form-settings-panel', 'rtl', 'replace' );
		}
	}

	/**
	 * Returns the default settings stored on a form post.
	 *
	 * @return array<string, string|bool|int>
	 */
	public static function get_default_form_settings(): array {
		return array(
			'adminRecipients'       => '',
			'adminSubject'          => __( 'SwiftForms submission #{submission_id}', 'swiftforms' ),
			'adminTemplate'         => '',
			'autoresponderField'    => '',
			'autoresponderSubject'  => __( 'We received your submission', 'swiftforms' ),
			'autoresponderTemplate' => '',
			'enableCaptcha'         => false,
			'enableTurnstile'       => false,
			'redirectUrl'           => '',
			'retentionDays'         => 0,
			'saveEntries'           => 'default',
			'submitLabel'           => __( 'Send message', 'swiftforms' ),
			'successMessage'        => __( 'Form submitted successfully.', 'swiftforms' ),
			'webhookUrl'            => '',
		);
	}

	/**
	 * Returns the stored settings for a form post merged with defaults.
	 *
	 * @return array<string, string|bool>
	 *
	 * @param int $post_id Form post ID.
	 */
	public static function get_form_settings( int $post_id ): array {
		$saved_settings = get_post_meta( $post_id, self::FORM_SETTINGS_META_KEY, true );

		if ( ! is_array( $saved_settings ) ) {
			$saved_settings = array();
		}

		return self::sanitize_form_settings( $saved_settings );
	}

	/**
	 * Sanitizes form settings before storage or runtime use.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 *
	 * @return array<string, string|bool>
	 */
	public static function sanitize_form_settings( array $settings ): array {
		$defaults = self::get_default_form_settings();

		return array(
			'adminRecipients'       => isset( $settings['adminRecipients'] ) ? sanitize_textarea_field( (string) $settings['adminRecipients'] ) : $defaults['adminRecipients'],
			'adminSubject'          => isset( $settings['adminSubject'] ) ? sanitize_text_field( (string) $settings['adminSubject'] ) : $defaults['adminSubject'],
			'adminTemplate'         => isset( $settings['adminTemplate'] ) ? sanitize_textarea_field( (string) $settings['adminTemplate'] ) : $defaults['adminTemplate'],
			'autoresponderField'    => isset( $settings['autoresponderField'] ) ? sanitize_key( (string) $settings['autoresponderField'] ) : $defaults['autoresponderField'],
			'autoresponderSubject'  => isset( $settings['autoresponderSubject'] ) ? sanitize_text_field( (string) $settings['autoresponderSubject'] ) : $defaults['autoresponderSubject'],
			'autoresponderTemplate' => isset( $settings['autoresponderTemplate'] ) ? sanitize_textarea_field( (string) $settings['autoresponderTemplate'] ) : $defaults['autoresponderTemplate'],
			'enableCaptcha'         => ! empty( $settings['enableCaptcha'] ),
			'enableTurnstile'       => ! empty( $settings['enableTurnstile'] ),
			'redirectUrl'           => isset( $settings['redirectUrl'] ) ? esc_url_raw( (string) $settings['redirectUrl'] ) : $defaults['redirectUrl'],
			'retentionDays'         => isset( $settings['retentionDays'] ) ? max( 0, (int) $settings['retentionDays'] ) : $defaults['retentionDays'],
			'saveEntries'           => isset( $settings['saveEntries'] ) && in_array( (string) $settings['saveEntries'], array( 'default', 'disabled', 'enabled' ), true )
				? (string) $settings['saveEntries']
				: $defaults['saveEntries'],
			'submitLabel'           => isset( $settings['submitLabel'] ) && '' !== trim( (string) $settings['submitLabel'] )
				? sanitize_text_field( (string) $settings['submitLabel'] )
				: $defaults['submitLabel'],
			'successMessage'        => isset( $settings['successMessage'] ) && '' !== trim( (string) $settings['successMessage'] )
				? sanitize_text_field( (string) $settings['successMessage'] )
				: $defaults['successMessage'],
			'webhookUrl'            => isset( $settings['webhookUrl'] ) ? esc_url_raw( (string) $settings['webhookUrl'] ) : $defaults['webhookUrl'],
		);
	}

	/**
	 * Adds useful submission columns in wp-admin.
	 *
	 * @param array<string, string> $columns Existing post list table columns.
	 *
	 * @return array<string, string>
	 */
	public function filter_submission_columns( array $columns ): array {
		return array(
			'cb'               => $columns['cb'] ?? '<input type="checkbox" />',
			'title'            => $columns['title'] ?? __( 'Title', 'swiftforms' ),
			'swiftforms_form'  => __( 'Form', 'swiftforms' ),
			'swiftforms_email' => __( 'Email', 'swiftforms' ),
			'date'             => $columns['date'] ?? __( 'Date', 'swiftforms' ),
		);
	}

	/**
	 * Renders custom submission column values.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Submission post ID.
	 */
	public function render_submission_column( string $column_name, int $post_id ): void {
		if ( 'swiftforms_form' === $column_name ) {
			$form_id = (int) get_post_meta( $post_id, '_sf_form_id', true );

			if ( get_post_meta( $post_id, '_sf_spam', true ) ) {
				echo '<span class="swiftforms-spam-badge" style="color:#b32d2e;font-weight:600;">' . esc_html__( 'Spam', 'swiftforms' ) . '</span> ';
			}

			if ( $form_id <= 0 ) {
				echo '&mdash;';
				return;
			}

			$form = get_post( $form_id );

			if ( ! $form instanceof WP_Post ) {
				/* translators: %d: form post ID. */
				echo esc_html( sprintf( __( 'Form #%d', 'swiftforms' ), $form_id ) );
				return;
			}

			$form_title = get_the_title( $form );
			/* translators: %d: form post ID. */
			echo esc_html( '' === $form_title ? sprintf( __( 'Form #%d', 'swiftforms' ), $form_id ) : $form_title );
			return;
		}

		if ( 'swiftforms_email' === $column_name ) {
			$email = $this->find_submission_email( $this->get_submission_field_values( $post_id ) );

			if ( '' === $email ) {
				echo '&mdash;';
				return;
			}

			echo esc_html( $email );
		}
	}

	/**
	 * Finds the first stored field value that looks like an email address.
	 *
	 * Falls back beyond a field literally named "email" since form authors are
	 * free to name their email field anything (e.g. "work_email", "contact").
	 *
	 * @param array<string, string> $fields Submission field values keyed by slug.
	 */
	private function find_submission_email( array $fields ): string {
		if ( isset( $fields['email'] ) && is_email( $fields['email'] ) ) {
			return $fields['email'];
		}

		foreach ( $fields as $value ) {
			if ( is_email( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Registers the read-only submission details metabox.
	 *
	 * Also the moment an admin actually opens a submission, so the unread
	 * marker is cleared here.
	 *
	 * @param WP_Post|null $post Submission post being viewed.
	 */
	public function add_submission_metabox( ?WP_Post $post = null ): void {
		if ( $post instanceof WP_Post ) {
			delete_post_meta( $post->ID, '_sf_unread' );
			delete_transient( 'swiftforms_unread_count' );
		}

		add_meta_box(
			'swiftforms-submission-details',
			__( 'Submission Details', 'swiftforms' ),
			array( $this, 'render_submission_metabox' ),
			self::SUBMISSION_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders every stored field value for a submission as a read-only table.
	 *
	 * @param WP_Post $post Submission post.
	 */
	public function render_submission_metabox( WP_Post $post ): void {
		$fields  = $this->get_submission_field_values( $post->ID );
		$form_id = (int) get_post_meta( $post->ID, '_sf_form_id', true );

		echo '<table class="widefat striped"><tbody>';

		if ( $form_id > 0 ) {
			$form_link  = get_edit_post_link( $form_id );
			$form_title = get_the_title( $form_id );
			/* translators: %d: form post ID. */
			$form_title = '' === $form_title ? sprintf( __( 'Form #%d', 'swiftforms' ), $form_id ) : $form_title;

			echo '<tr><th scope="row">' . esc_html__( 'Form', 'swiftforms' ) . '</th><td>';
			if ( $form_link ) {
				echo '<a href="' . esc_url( $form_link ) . '">' . esc_html( $form_title ) . '</a>';
			} else {
				echo esc_html( $form_title );
			}
			echo '</td></tr>';
		}

		if ( empty( $fields ) ) {
			echo '<tr><td colspan="2">' . esc_html__( 'No field data was stored for this submission.', 'swiftforms' ) . '</td></tr>';
		}

		foreach ( $fields as $slug => $value ) {
			$file_url = $this->resolve_uploaded_file_url( (string) $value );

			echo '<tr><th scope="row">' . esc_html( $this->format_field_label( $slug ) ) . '</th><td>';
			if ( '' !== $file_url ) {
				echo '<a href="' . esc_url( $file_url ) . '" rel="noopener" target="_blank">' . esc_html( basename( (string) $value ) ) . '</a>';
			} else {
				echo nl2br( esc_html( (string) $value ) );
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Adds the CSV export bulk action to the submissions list table.
	 *
	 * @param array<string, string> $actions Existing bulk actions.
	 *
	 * @return array<string, string>
	 */
	public function filter_submission_bulk_actions( array $actions ): array {
		$actions['swiftforms_export_csv'] = __( 'Export to CSV', 'swiftforms' );
		$actions['swiftforms_mark_spam']  = __( 'Mark as spam', 'swiftforms' );
		$actions['swiftforms_not_spam']   = __( 'Not spam', 'swiftforms' );

		return $actions;
	}

	/**
	 * Streams selected submissions as a CSV download.
	 *
	 * Submission data is PII, so export is gated behind a higher capability than
	 * the general edit_posts used elsewhere for this screen; sites can adjust it
	 * via the swiftforms_export_capability filter.
	 *
	 * @param string $redirect_to Default redirect URL.
	 * @param string $action      Bulk action being performed.
	 * @param int[]  $post_ids    Selected submission IDs.
	 */
	public function handle_submission_bulk_actions( string $redirect_to, string $action, array $post_ids ): string {
		if ( in_array( $action, array( 'swiftforms_mark_spam', 'swiftforms_not_spam' ), true ) ) {
			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;

				if ( self::SUBMISSION_POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
					continue;
				}

				if ( 'swiftforms_mark_spam' === $action ) {
					update_post_meta( $post_id, '_sf_spam', 1 );
					delete_post_meta( $post_id, '_sf_unread' );
				} else {
					delete_post_meta( $post_id, '_sf_spam' );
				}
			}

			return $redirect_to;
		}

		if ( 'swiftforms_export_csv' !== $action ) {
			return $redirect_to;
		}

		$required_capability = (string) apply_filters( 'swiftforms_export_capability', 'manage_options' );

		if ( ! current_user_can( $required_capability ) || empty( $post_ids ) ) {
			return $redirect_to;
		}

		$rows = $this->build_export_rows( $post_ids );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="swiftforms-submissions.csv"' );

		$handle = fopen( 'php://output', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',', '"', '\\' );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV straight to php://output.

		exit;
	}

	/**
	 * Builds the CSV matrix (header row plus one row per submission).
	 *
	 * Kept separate from the streaming handler so the column logic is unit testable.
	 *
	 * @param int[] $post_ids Submission IDs to export.
	 *
	 * @return array<int, array<int, string>>
	 */
	public function build_export_rows( array $post_ids ): array {
		$field_keys = $this->collect_field_keys( $post_ids );
		$labels     = array_map( array( $this, 'format_field_label' ), $field_keys );

		$rows = array( array_merge( array( 'ID', 'Title', 'Date' ), $labels ) );

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );

			if ( ! $post instanceof WP_Post || self::SUBMISSION_POST_TYPE !== $post->post_type ) {
				continue;
			}

			$values = $this->get_submission_field_values( $post_id );
			$row    = array(
				(string) $post_id,
				get_the_title( $post ),
				(string) get_the_date( 'Y-m-d H:i:s', $post ),
			);

			foreach ( $field_keys as $slug ) {
				$value    = isset( $values[ $slug ] ) ? (string) $values[ $slug ] : '';
				$file_url = $this->resolve_uploaded_file_url( $value );
				$row[]    = '' !== $file_url ? $file_url : $value;
			}

			$rows[] = array_map( array( $this, 'escape_csv_cell' ), $row );
		}

		return $rows;
	}

	/**
	 * Neutralizes CSV formula injection.
	 *
	 * Spreadsheet apps execute cell values starting with =, +, -, or @ as
	 * formulas when the CSV is opened, which is a known attack vector for
	 * exported user-submitted data. Prefixing with an apostrophe forces the
	 * cell to be treated as plain text.
	 *
	 * @param string $value Raw cell value.
	 */
	private function escape_csv_cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Collects the union of field slugs across the given submissions.
	 *
	 * @param int[] $post_ids Submission IDs.
	 *
	 * @return string[]
	 */
	private function collect_field_keys( array $post_ids ): array {
		$keys = array();

		foreach ( $post_ids as $post_id ) {
			foreach ( array_keys( $this->get_submission_field_values( (int) $post_id ) ) as $slug ) {
				$keys[ $slug ] = true;
			}
		}

		return array_keys( $keys );
	}

	/**
	 * Returns the `_sf_field_*` meta for a submission keyed by field slug.
	 *
	 * Public so the privacy exporter/eraser can reuse it.
	 *
	 * @param int $post_id Submission ID.
	 *
	 * @return array<string, string>
	 */
	public function get_submission_field_values( int $post_id ): array {
		$meta   = get_post_meta( $post_id );
		$fields = array();

		if ( ! is_array( $meta ) ) {
			return $fields;
		}

		foreach ( $meta as $key => $value ) {
			if ( 0 !== strpos( (string) $key, '_sf_field_' ) ) {
				continue;
			}

			$slug            = substr( (string) $key, strlen( '_sf_field_' ) );
			$fields[ $slug ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return $fields;
	}

	/**
	 * Converts a stored uploaded-file path into a public URL, or '' if not a file.
	 *
	 * @param string $value Stored meta value.
	 */
	private function resolve_uploaded_file_url( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$uploads = wp_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] ) . 'swiftforms/';

		if ( 0 !== strpos( $value, $basedir ) ) {
			return '';
		}

		return $uploads['baseurl'] . '/swiftforms/' . ltrim( substr( $value, strlen( $basedir ) ), '/' );
	}

	/**
	 * Turns a field slug into a human-readable label.
	 *
	 * @param string $slug Field slug.
	 */
	private function format_field_label( string $slug ): string {
		return ucwords( str_replace( array( '_', '-' ), ' ', $slug ) );
	}

	/**
	 * Renders a "filter by form" dropdown above the submissions list table.
	 */
	public function render_form_filter_dropdown(): void {
		global $typenow;

		if ( self::SUBMISSION_POST_TYPE !== $typenow ) {
			return;
		}

		$forms = get_posts(
			array(
				'order'          => 'ASC',
				'orderby'        => 'title',
				'post_type'      => self::FORM_POST_TYPE,
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded dropdown; a site with 200+ forms should filter by search instead.
			)
		);

		if ( empty( $forms ) ) {
			return;
		}

		$selected_form_id = isset( $_GET['swiftforms_form_id'] ) ? (int) $_GET['swiftforms_form_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<select name="swiftforms_form_id">';
		echo '<option value="0">' . esc_html__( 'All forms', 'swiftforms' ) . '</option>';

		foreach ( $forms as $form ) {
			$label = get_the_title( $form );
			/* translators: %d: form post ID. */
			$label = '' === $label ? sprintf( __( 'Form #%d', 'swiftforms' ), $form->ID ) : $label;

			printf(
				'<option value="%d"%s>%s</option>',
				(int) $form->ID,
				selected( $selected_form_id, $form->ID, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		$spam_filter = isset( $_GET['swiftforms_spam'] ) ? sanitize_key( (string) $_GET['swiftforms_spam'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<select name="swiftforms_spam">';
		echo '<option value=""' . selected( $spam_filter, '', false ) . '>' . esc_html__( 'Spam and non-spam', 'swiftforms' ) . '</option>';
		echo '<option value="only"' . selected( $spam_filter, 'only', false ) . '>' . esc_html__( 'Spam only', 'swiftforms' ) . '</option>';
		echo '<option value="hide"' . selected( $spam_filter, 'hide', false ) . '>' . esc_html__( 'Hide spam', 'swiftforms' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Narrows the submissions list query to the form selected in the filter dropdown.
	 *
	 * @param WP_Query $query Current admin list query.
	 */
	public function apply_form_filter_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( self::SUBMISSION_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$form_id = isset( $_GET['swiftforms_form_id'] ) ? (int) $_GET['swiftforms_form_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $form_id > 0 ) {
			$query->set( 'meta_key', '_sf_form_id' );
			$query->set( 'meta_value', $form_id );
		}

		$spam_filter = isset( $_GET['swiftforms_spam'] ) ? sanitize_key( (string) $_GET['swiftforms_spam'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $spam_filter, array( 'only', 'hide' ), true ) ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = 'only' === $spam_filter
			? array(
				'key'     => '_sf_spam',
				'compare' => 'EXISTS',
			)
			: array(
				'key'     => '_sf_spam',
				'compare' => 'NOT EXISTS',
			);
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Deletes any files a submission uploaded once the submission post itself is deleted.
	 *
	 * Without this, uploads accumulate forever in uploads/swiftforms/ even after
	 * their owning submission is gone.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public function delete_submission_uploads( int $post_id ): void {
		if ( self::SUBMISSION_POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( $this->get_submission_field_values( $post_id ) as $value ) {
			$this->maybe_delete_uploaded_file( $value );
		}
	}

	/**
	 * Deletes a stored value from disk if it points at a SwiftForms upload.
	 *
	 * @param string $value Stored meta value.
	 */
	private function maybe_delete_uploaded_file( string $value ): void {
		if ( '' === $value ) {
			return;
		}

		$uploads = wp_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] ) . 'swiftforms/';

		if ( 0 !== strpos( $value, $basedir ) || ! file_exists( $value ) ) {
			return;
		}

		wp_delete_file( $value );
	}

	/**
	 * Extends the submissions list search to match stored field values.
	 *
	 * Submission titles are just "Submission #N", so WordPress's default
	 * title/content search finds nothing useful. Instead, resolve the search
	 * term against `_sf_field_*` meta values (plus a literal "#123" ID form)
	 * and constrain the query to those IDs.
	 *
	 * @param WP_Query $query Current admin list query.
	 */
	public function apply_field_value_search( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( self::SUBMISSION_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$search = trim( (string) $query->get( 's' ) );
		if ( '' === $search ) {
			return;
		}

		$ids = get_posts(
			array(
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'compare'     => 'LIKE',
						'compare_key' => 'LIKE',
						'key'         => '_sf_field_',
						'value'       => $search,
					),
				),
				'post_status'    => 'any',
				'post_type'      => self::SUBMISSION_POST_TYPE,
				'posts_per_page' => (int) apply_filters( 'swiftforms_search_results_limit', 500 ),
			)
		);

		if ( preg_match( '/^#?(\d+)$/', $search, $matches ) ) {
			$ids[] = (int) $matches[1];
		}

		$query->set( 's', '' );
		$query->set( 'post__in', ! empty( $ids ) ? array_map( 'intval', $ids ) : array( 0 ) );
	}

	/**
	 * Counts submissions matching the given query without loading them.
	 *
	 * `no_found_rows` must stay false: WP_Query only populates found_posts
	 * (the total ignoring pagination) when it also runs the FOUND_ROWS query.
	 *
	 * @param array<string, mixed> $query_args Additional WP_Query arguments.
	 */
	private function count_submissions( array $query_args ): int {
		$query = new WP_Query(
			array_merge(
				$query_args,
				array(
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'post_status'    => 'any',
					'post_type'      => self::SUBMISSION_POST_TYPE,
					'posts_per_page' => 1,
				)
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Appends the unread submissions count bubble to the Submissions menu item.
	 */
	public function add_unread_count_bubble(): void {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		// Runs on every admin page load, so the count is cached briefly; 60s
		// staleness on a badge is invisible, a COUNT query per page is not.
		$count = get_transient( 'swiftforms_unread_count' );

		if ( false === $count ) {
			$count = $this->count_submissions(
				array(
					'meta_key'   => '_sf_unread', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			set_transient( 'swiftforms_unread_count', $count, MINUTE_IN_SECONDS );
		}

		$count = (int) $count;

		if ( $count < 1 ) {
			return;
		}

		foreach ( $menu as $index => $item ) {
			if ( isset( $item[2] ) && 'edit.php?post_type=' . self::SUBMISSION_POST_TYPE === $item[2] ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Appending the unread bubble to this plugin's own menu entry is the documented pattern.
				$menu[ $index ][0] .= sprintf(
					' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
					$count
				);
				break;
			}
		}
	}

	/**
	 * Flags unread submission rows in the list table.
	 *
	 * @param string[]           $classes Row classes.
	 * @param string[]           $css_class Additional classes requested by the caller.
	 * @param int|WP_Post|string $post_id Post being rendered.
	 *
	 * @return string[]
	 */
	public function add_unread_row_class( array $classes, array $css_class, $post_id ): array {
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id > 0
			&& self::SUBMISSION_POST_TYPE === get_post_type( $post_id )
			&& '1' === (string) get_post_meta( $post_id, '_sf_unread', true )
		) {
			$classes[] = 'swiftforms-unread';
		}

		return $classes;
	}

	/**
	 * Prints the styling that makes unread submission rows stand out.
	 */
	public function print_unread_row_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || self::SUBMISSION_POST_TYPE !== $screen->post_type ) {
			return;
		}

		echo '<style>.wp-list-table tr.swiftforms-unread .row-title{font-weight:700}.wp-list-table tr.swiftforms-unread td, .wp-list-table tr.swiftforms-unread th{background-color:#f0f6fc}</style>';
	}

	/**
	 * Adds an entry-count column to the Forms list table.
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function filter_form_columns( array $columns ): array {
		$reordered = array();

		foreach ( $columns as $key => $label ) {
			$reordered[ $key ] = $label;

			if ( 'title' === $key ) {
				$reordered['swiftforms_entries'] = __( 'Entries', 'swiftforms' );
			}
		}

		return $reordered;
	}

	/**
	 * Renders the entry count for a form, linked to the filtered submissions list.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Form post ID.
	 */
	public function render_form_column( string $column_name, int $post_id ): void {
		if ( 'swiftforms_entries' !== $column_name ) {
			return;
		}

		$count = $this->count_submissions(
			array(
				'meta_key'   => '_sf_form_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $post_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( $count < 1 ) {
			echo '&mdash;';
			return;
		}

		$url = add_query_arg(
			array(
				'post_type'          => self::SUBMISSION_POST_TYPE,
				'swiftforms_form_id' => $post_id,
			),
			admin_url( 'edit.php' )
		);

		echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $count ) . '</a>';
	}

	/**
	 * Adds a Duplicate row action to forms.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param WP_Post               $post    List table row post.
	 *
	 * @return array<string, string>
	 */
	public function add_duplicate_row_action( array $actions, WP_Post $post ): array {
		if ( self::FORM_POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'swiftforms_duplicate_form',
					'post'   => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'swiftforms_duplicate_' . $post->ID
		);

		$actions['swiftforms_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate', 'swiftforms' ) . '</a>';

		return $actions;
	}

	/**
	 * Handles the Duplicate row action request.
	 */
	public function handle_duplicate_form_action(): void {
		$form_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		check_admin_referer( 'swiftforms_duplicate_' . $form_id );

		if ( ! current_user_can( 'edit_post', $form_id ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this form.', 'swiftforms' ) );
		}

		$new_form_id = $this->duplicate_form( $form_id );

		if ( is_wp_error( $new_form_id ) ) {
			wp_die( esc_html( $new_form_id->get_error_message() ) );
		}

		wp_safe_redirect( admin_url( 'post.php?post=' . $new_form_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Creates a draft copy of a form, including its settings meta.
	 *
	 * Kept separate from the request handler so it can be unit tested.
	 *
	 * @return int|WP_Error New form ID.
	 *
	 * @param int $form_id Source form post ID.
	 */
	public function duplicate_form( int $form_id ) {
		$form = get_post( $form_id );

		if ( ! $form instanceof WP_Post || self::FORM_POST_TYPE !== $form->post_type ) {
			return new WP_Error( 'invalid_form', __( 'The form to duplicate could not be found.', 'swiftforms' ) );
		}

		$new_form_id = wp_insert_post(
			array(
				/* translators: %s: original form title. */
				'post_title'   => sprintf( __( '%s (Copy)', 'swiftforms' ), $form->post_title ),
				'post_content' => wp_slash( $form->post_content ),
				'post_status'  => 'draft',
				'post_type'    => self::FORM_POST_TYPE,
			),
			true
		);

		if ( is_wp_error( $new_form_id ) ) {
			return $new_form_id;
		}

		$settings = get_post_meta( $form_id, self::FORM_SETTINGS_META_KEY, true );
		if ( is_array( $settings ) ) {
			update_post_meta( $new_form_id, self::FORM_SETTINGS_META_KEY, self::sanitize_form_settings( $settings ) );
		}

		return $new_form_id;
	}

	/**
	 * Returns registration arguments for the form builder post type.
	 *
	 * @return array<string, mixed>
	 */
	public function get_form_args(): array {
		return array(
			'label'        => __( 'Forms', 'swiftforms' ),
			'description'  => __( 'SwiftForms builders.', 'swiftforms' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-feedback',
			// 'custom-fields' is required for WordPress's REST API to expose
			// and accept the `meta` field at all; without it, _sf_settings
			// (the Form Experience / Notifications sidebar panel) can never
			// be read or saved through the block editor.
			'supports'     => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'map_meta_cap' => true,
		);
	}

	/**
	 * Returns registration arguments for the submission post type.
	 *
	 * @return array<string, mixed>
	 */
	public function get_submission_args(): array {
		return array(
			'label'              => __( 'Submissions', 'swiftforms' ),
			'description'        => __( 'SwiftForms submission records.', 'swiftforms' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => false,
			'supports'           => array( 'title' ),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
		);
	}
}
