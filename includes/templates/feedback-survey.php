<?php
/**
 * Feedback survey template content.
 *
 * Demonstrates conditional logic: the follow-up field appears only when the
 * visitor picks an unhappy rating.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

return <<<'HTML'
<!-- wp:swiftforms/radio-field {"label":"How satisfied are you?","slug":"satisfaction","options":"Very satisfied|5\nSatisfied|4\nNeutral|3\nUnsatisfied|2\nVery unsatisfied|1","required":true} -->
<div class="wp-block-swiftforms-radio-field swiftforms-field swiftforms-field--radio" data-field-slug="satisfaction" data-field-type="radio" data-swiftforms-field="true"><fieldset class="swiftforms-field__fieldset"><legend class="swiftforms-field__label">How satisfied are you?</legend><label class="swiftforms-field__choice"><input name="satisfaction" required type="radio" value="5"/><span>Very satisfied</span></label><label class="swiftforms-field__choice"><input name="satisfaction" required type="radio" value="4"/><span>Satisfied</span></label><label class="swiftforms-field__choice"><input name="satisfaction" required type="radio" value="3"/><span>Neutral</span></label><label class="swiftforms-field__choice"><input name="satisfaction" required type="radio" value="2"/><span>Unsatisfied</span></label><label class="swiftforms-field__choice"><input name="satisfaction" required type="radio" value="1"/><span>Very unsatisfied</span></label></fieldset></div>
<!-- /wp:swiftforms/radio-field -->

<!-- wp:swiftforms/textarea-field {"label":"What went wrong?","slug":"what_went_wrong","conditions":{"enabled":true,"action":"show","groups":[[{"field":"satisfaction","operator":"equals","value":"1"}],[{"field":"satisfaction","operator":"equals","value":"2"}]]}} -->
<div class="wp-block-swiftforms-textarea-field swiftforms-field swiftforms-field--textarea" data-field-slug="what_went_wrong" data-field-type="text" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">What went wrong?</span><textarea name="what_went_wrong" placeholder="" rows="4"></textarea></label></div>
<!-- /wp:swiftforms/textarea-field -->

<!-- wp:swiftforms/email-field {"label":"Email (optional, if you want a reply)","slug":"email"} -->
<div class="wp-block-swiftforms-email-field swiftforms-field swiftforms-field--email" data-field-slug="email" data-field-type="email" data-swiftforms-field="true"><label class="swiftforms-field__control"><span class="swiftforms-field__label">Email (optional, if you want a reply)</span><input name="email" placeholder="" type="email"/></label></div>
<!-- /wp:swiftforms/email-field -->
HTML;
