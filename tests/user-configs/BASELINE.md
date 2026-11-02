# Baseline: where the snapshots came from

The golden-master snapshots in `snapshots/` (configs 01 to 20 and `_global/`) are the baseline of release **1.0.0**. They are frozen at the `v1.0.0` tag. From R02 on, a snapshot change needs an entry in the release file's "Intended snapshot changes" section ([roadmap gate item 2](../../.ai/roadmap/README.md)).

## Known now (filled on 2026-11-02, end of R01 phase P9)

| | |
|---|---|
| Snapshot generator | `SF_UPDATE_SNAPSHOTS=1 composer test:configs` (not run in CI). The last regeneration was in P8b; P9 did not regenerate. |
| Verified without the flag | `composer test:configs` passes (21 of 21: configs 01 to 20 and the globals) on the matrix below, see `.ai/r01/gate-local.md` |
| Configs | 20 (`01-fresh-defaults` to `20-power-user`); `00-harness-selftest` was deleted |
| Scenarios | S1 to S13 run; S14 to S16 are `pending: R02` in every config |
| WordPress (generating core) | 7.1.4 (`.cache/wp-core/latest`); also run on 6.8.12 with identical snapshots |
| WordPress test library | the core's own (`tests/phpunit` of the downloaded core, via `WP_TESTS_ABSPATH`) |
| PHP | 8.4.27 (snapshots identical on 7.4.33) |
| PHPUnit | 9.6.38 |
| Database | MariaDB 11.8.9 (the schema snapshot is normalised for MySQL 8 column widths; MySQL 8 itself is unverified until CI) |
| Branch at the time of writing | `restart` (the R01 work is uncommitted) |

## Tag-time fields (the maintainer fills these when tagging `v1.0.0`)

| | |
|---|---|
| Date (UTC) | |
| SwiftForms version | `1.0.0` (the release pipeline writes it from the tag with `bin/set-version.sh`) |
| `git rev-parse HEAD` of the tag | |
| Core versions of the final run | |

To finish it: check out the tag, run the suite once without the flag, and let the script record the date, versions and commit.

```
git checkout v1.0.0
composer install
composer test:configs                  # must pass without SF_UPDATE_SNAPSHOTS
bin/generate-baseline.sh               # overwrites this file with date, versions, PHP, PHPUnit, database and `git rev-parse HEAD`
```

`bin/generate-baseline.sh` replaces this whole file with its own table. If you want to keep the "Known now" notes above, copy them first. Then commit the file on the tag's commit or note the hash in the release description.
