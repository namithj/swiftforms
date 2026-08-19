<?php
/**
 * "Quote Request" starter pattern.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

return <<<'BLOCKS'
<!-- wp:swf/field-text {"label":"Full name","slug":"name","required":true} /-->

<!-- wp:swf/field-email {"label":"Email","slug":"email","required":true} /-->

<!-- wp:swf/field-tel {"label":"Phone","slug":"phone","required":false} /-->

<!-- wp:swf/field-select {"label":"Budget range","slug":"budget","options":"Under $1,000|under_1000\n$1,000–$5,000|1000_5000\n$5,000–$20,000|5000_20000\nOver $20,000|over_20000"} /-->

<!-- wp:swf/field-textarea {"label":"Project details","slug":"details","required":true} /-->
BLOCKS;
