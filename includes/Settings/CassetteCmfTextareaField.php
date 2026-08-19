<?php
/**
 * Newline-preserving textarea field for Cassette-CMF.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

namespace SwiftForms\Settings;

use Pedalcms\CassetteCmf\Field\Fields\Textarea_Field;

/**
 * Cassette-CMF's own Textarea_Field never overrides sanitize(), so it
 * inherits Abstract_Field::sanitize() — which runs sanitize_text_field()
 * and strips line breaks. That's wrong for multi-line content (email
 * templates, success messages), so FormSettingsMetabox registers this in
 * its place via Field_Factory::register_type( 'textarea', ... ); everything
 * else about the field (rendering, validation) is unchanged.
 */
final class CassetteCmfTextareaField extends Textarea_Field {

	/**
	 * @param mixed $input Raw input value.
	 * @return string
	 */
	public function sanitize( $input ) {
		return sanitize_textarea_field( (string) $input );
	}
}
