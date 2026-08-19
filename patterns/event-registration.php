<?php
/**
 * "Event Registration" starter pattern — a multi-step form.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

return <<<'BLOCKS'
<!-- wp:swf/step {"title":"Your details"} -->
<!-- wp:swf/field-text {"label":"Full name","slug":"name","required":true} /-->

<!-- wp:swf/field-email {"label":"Email","slug":"email","required":true} /-->
<!-- /wp:swf/step -->

<!-- wp:swf/step {"title":"Attendance"} -->
<!-- wp:swf/field-date {"label":"Which date will you attend?","slug":"attend_date","required":true} /-->

<!-- wp:swf/field-select {"label":"Number of guests","slug":"guests","options":"Just me|1\n1 guest|2\n2 guests|3\n3+ guests|4"} /-->
<!-- /wp:swf/step -->

<!-- wp:swf/step {"title":"Confirm"} -->
<!-- wp:swf/field-consent {"slug":"privacy_consent","statementText":"I agree to receive event updates by email."} /-->
<!-- /wp:swf/step -->
BLOCKS;
