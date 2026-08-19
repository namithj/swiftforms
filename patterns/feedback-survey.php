<?php
/**
 * "Feedback Survey" starter pattern.
 *
 * @package SwiftForms
 */

declare(strict_types=1);

return <<<'BLOCKS'
<!-- wp:swf/field-rating {"label":"How would you rate your experience?","slug":"rating","required":true,"maxRating":5} /-->

<!-- wp:swf/field-radio {"label":"Would you recommend us to a friend?","slug":"recommend","required":true,"options":"Yes|yes\nMaybe|maybe\nNo|no"} /-->

<!-- wp:swf/field-textarea {"label":"Anything else you'd like to share?","slug":"comments","required":false} /-->
BLOCKS;
