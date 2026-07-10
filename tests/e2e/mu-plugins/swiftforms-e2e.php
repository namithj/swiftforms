<?php
/**
 * E2E-only tweaks, mapped into wp-env as an mu-plugin (see .wp-env.json).
 *
 * The whole Playwright suite submits from one IP in quick succession, so the
 * production rate limit (5/min) would make specs flaky as the suite grows.
 */

declare(strict_types=1);

add_filter(
	'swiftforms_rate_limit_max_requests',
	static fn (): int => 1000
);

// Playwright fills and submits forms in well under the production minimum
// submit time, so the time trap would silently absorb every spec submission.
// The trap's logic is covered by PHPUnit; e2e only verifies the wiring.
add_filter(
	'swiftforms_min_submit_seconds',
	'__return_zero',
	20
);
