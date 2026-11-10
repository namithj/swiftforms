<?php
/**
 * Image settings field.
 *
 * @package SwiftForms
 */

namespace SwiftForms;

use SwiftForms\Vendor\PedalCMS\CassetteCMF\Field\Fields\Upload_Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings_Image_Field
 *
 * The media-library picker for one image, stored as the attachment ID. The stock upload field also accepts a
 * URL or any ID; this one keeps only an ID of an image (or "" for none) and refuses everything else.
 */
class Settings_Image_Field extends Upload_Field {

	/**
	 * Field type the page registers this class under.
	 */
	const TYPE = 'swiftforms_image';

	/**
	 * The type the settings app sees.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'upload';
	}

	/**
	 * An attachment ID as an integer, "" for none, anything else as it is (so validate() can refuse it).
	 *
	 * @param mixed $input Raw value.
	 * @return mixed
	 */
	public function sanitize( $input ) {
		if ( null === $input || '' === $input || 0 === $input || '0' === $input ) {
			return '';
		}
		if ( is_int( $input ) || ( is_string( $input ) && 1 === preg_match( '/^[0-9]{1,10}$/', $input ) ) ) {
			return (int) $input;
		}
		return is_scalar( $input ) ? sanitize_text_field( (string) $input ) : '';
	}

	/**
	 * Validate: none, or the ID of an image in the media library.
	 *
	 * @param mixed $input Sanitised value.
	 * @return array{valid:bool,errors:array<int,string>}
	 */
	public function validate( $input ): array {
		$result = [
			'valid'  => true,
			'errors' => [],
		];
		if ( '' !== $input && ( ! is_int( $input ) || ! wp_attachment_is_image( $input ) ) ) {
			$result['valid']    = false;
			$result['errors'][] = __( 'Choose an image from the media library.', 'swiftforms' );
		}
		return $result;
	}
}
