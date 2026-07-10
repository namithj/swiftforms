<?php
/**
 * Contact form template content.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

return <<<'HTML'
<!-- wp:swiftforms/text-field {"label":"Name","slug":"name","required":true} -->
<div class="wp-block-swiftforms-text-field swiftforms-field swiftforms-field--text" data-field-slug="name" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Name</span><input name="name" placeholder="" required type="text"/></label></div>
<!-- /wp:swiftforms/text-field -->

<!-- wp:swiftforms/email-field {"label":"Email","slug":"email","required":true} -->
<div class="wp-block-swiftforms-email-field swiftforms-field swiftforms-field--email" data-field-slug="email" data-field-type="email" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Email</span><input name="email" placeholder="" required type="email"/></label></div>
<!-- /wp:swiftforms/email-field -->

<!-- wp:swiftforms/textarea-field {"label":"Message","slug":"message","required":true} -->
<div class="wp-block-swiftforms-textarea-field swiftforms-field swiftforms-field--textarea" data-field-slug="message" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Message</span><textarea name="message" placeholder="" required rows="4"></textarea></label></div>
<!-- /wp:swiftforms/textarea-field -->
HTML;
