<?php
/**
 * Event registration template content.
 *
 * Living documentation for the step block, radio field, date field, and a
 * conditional field, combined in one form.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

return <<<'HTML'
<!-- wp:swiftforms/step {"title":"Your details"} -->
<div class="wp-block-swiftforms-step swiftforms-step" data-swiftforms-step="true" data-step-title="Your details"><!-- wp:swiftforms/text-field {"label":"Full name","slug":"full_name","required":true} -->
<div class="wp-block-swiftforms-text-field swiftforms-field swiftforms-field--text" data-field-slug="full_name" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Full name</span><input name="full_name" placeholder="" required type="text"/></label></div>
<!-- /wp:swiftforms/text-field -->

<!-- wp:swiftforms/email-field {"label":"Email","slug":"email","required":true} -->
<div class="wp-block-swiftforms-email-field swiftforms-field swiftforms-field--email" data-field-slug="email" data-field-type="email" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Email</span><input name="email" placeholder="" required type="email"/></label></div>
<!-- /wp:swiftforms/email-field --></div>
<!-- /wp:swiftforms/step -->

<!-- wp:swiftforms/step {"title":"Registration"} -->
<div class="wp-block-swiftforms-step swiftforms-step" data-swiftforms-step="true" data-step-title="Registration"><!-- wp:swiftforms/date-field {"label":"Attendance date","slug":"attendance_date","required":true} -->
<div class="wp-block-swiftforms-date-field swiftforms-field swiftforms-field--date" data-field-slug="attendance_date" data-field-type="date" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Attendance date</span><input name="attendance_date" required type="date"/></label></div>
<!-- /wp:swiftforms/date-field -->

<!-- wp:swiftforms/radio-field {"label":"Meal preference","slug":"meal","options":"Standard|standard\nVegetarian|vegetarian\nOther / allergies|other","required":true} -->
<div class="wp-block-swiftforms-radio-field swiftforms-field swiftforms-field--radio" data-field-slug="meal" data-field-type="radio" data-swiftforms-field="true"><fieldset class="swiftforms-field__fieldset"><legend class="swiftforms-field__label">Meal preference</legend><label class="swiftforms-field__choice"><input name="meal" required type="radio" value="standard"/><span>Standard</span></label><label class="swiftforms-field__choice"><input name="meal" required type="radio" value="vegetarian"/><span>Vegetarian</span></label><label class="swiftforms-field__choice"><input name="meal" required type="radio" value="other"/><span>Other / allergies</span></label></fieldset></div>
<!-- /wp:swiftforms/radio-field -->

<!-- wp:swiftforms/text-field {"label":"Dietary notes","slug":"dietary_notes","conditions":{"enabled":true,"action":"show","groups":[[{"field":"meal","operator":"equals","value":"other"}]]}} -->
<div class="wp-block-swiftforms-text-field swiftforms-field swiftforms-field--text" data-field-slug="dietary_notes" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Dietary notes</span><input name="dietary_notes" placeholder="" type="text"/></label></div>
<!-- /wp:swiftforms/text-field --></div>
<!-- /wp:swiftforms/step -->
HTML;
