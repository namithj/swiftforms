<?php
/**
 * Quote request form template content.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

return <<<'HTML'
<!-- wp:swiftforms/text-field {"label":"Name","slug":"name","required":true} -->
<div class="wp-block-swiftforms-text-field swiftforms-field swiftforms-field--text" data-field-slug="name" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Name</span><input name="name" placeholder="" required type="text"/></label></div>
<!-- /wp:swiftforms/text-field -->

<!-- wp:swiftforms/email-field {"label":"Work email","slug":"email","required":true} -->
<div class="wp-block-swiftforms-email-field swiftforms-field swiftforms-field--email" data-field-slug="email" data-field-type="email" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Work email</span><input name="email" placeholder="" required type="email"/></label></div>
<!-- /wp:swiftforms/email-field -->

<!-- wp:swiftforms/select-field {"label":"Project type","slug":"project_type","options":"Website|website\nOnline store|store\nSomething else|other","required":true} -->
<div class="wp-block-swiftforms-select-field swiftforms-field swiftforms-field--select" data-field-slug="project_type" data-field-type="select" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Project type</span><select name="project_type" required><option value="">Select an option</option><option value="website">Website</option><option value="store">Online store</option><option value="other">Something else</option></select></label></div>
<!-- /wp:swiftforms/select-field -->

<!-- wp:swiftforms/radio-field {"label":"Budget","slug":"budget","options":"Under $1,000|small\n$1,000 – $10,000|medium\nOver $10,000|large"} -->
<div class="wp-block-swiftforms-radio-field swiftforms-field swiftforms-field--radio" data-field-slug="budget" data-field-type="radio" data-swiftforms-field="true"><fieldset class="swiftforms-field__fieldset"><legend class="swiftforms-field__label">Budget</legend><label class="swiftforms-field__choice"><input name="budget" type="radio" value="small"/><span>Under $1,000</span></label><label class="swiftforms-field__choice"><input name="budget" type="radio" value="medium"/><span>$1,000 – $10,000</span></label><label class="swiftforms-field__choice"><input name="budget" type="radio" value="large"/><span>Over $10,000</span></label></fieldset></div>
<!-- /wp:swiftforms/radio-field -->

<!-- wp:swiftforms/date-field {"label":"Ideal start date","slug":"start_date"} -->
<div class="wp-block-swiftforms-date-field swiftforms-field swiftforms-field--date" data-field-slug="start_date" data-field-type="date" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Ideal start date</span><input name="start_date" type="date"/></label></div>
<!-- /wp:swiftforms/date-field -->

<!-- wp:swiftforms/textarea-field {"label":"Project details","slug":"details","required":true} -->
<div class="wp-block-swiftforms-textarea-field swiftforms-field swiftforms-field--textarea" data-field-slug="details" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Project details</span><textarea name="details" placeholder="" required rows="4"></textarea></label></div>
<!-- /wp:swiftforms/textarea-field -->
HTML;
