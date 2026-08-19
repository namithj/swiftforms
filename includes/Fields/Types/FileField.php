<?php
/**
 * File upload field type.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Fields\Types;

use SwiftForms\Fields\FieldType;

/**
 * Validation here checks the raw upload before MIME/content sniffing and the
 * move-to-uploads-dir happen in Submissions\UploadHandler.
 */
final class FileField {

	public static function define(): FieldType {
		return new FieldType(
			type: 'file',
			label: __( 'File Upload', 'swiftforms' ),
			attributes: array(
				'label' => array(
					'type'    => 'string',
					'default' => __( 'Attachment', 'swiftforms' ),
				),
				'slug'  => array(
					'type'    => 'string',
					'default' => 'attachment',
				),
			),
			validate: static function ( $value, array $attributes ): ?string {
				$has_file = is_array( $value ) && ! empty( $value['tmp_name'] );

				if ( ! $has_file ) {
					return ! empty( $attributes['required'] ) ? __( 'Please choose a file.', 'swiftforms' ) : null;
				}

				if ( ( (int) ( $value['size'] ?? 0 ) ) > wp_max_upload_size() ) {
					return __( 'That file is too large.', 'swiftforms' );
				}

				return null;
			}
		);
	}
}
