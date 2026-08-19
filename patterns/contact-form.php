<?php
/**
 * "Contact Form" starter pattern.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

return <<<'BLOCKS'
<!-- wp:swf/field-text {"label":"Name","slug":"name","required":true} /-->

<!-- wp:swf/field-email {"label":"Email","slug":"email","required":true} /-->

<!-- wp:swf/field-textarea {"label":"Message","slug":"message","required":true} /-->
BLOCKS;
