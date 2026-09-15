# Required-parts filters — deployed (2026-09-14)

Status: deployed to the local MariaDB instance; user acceptance pending.
Targeted tests (4 tests, 57 assertions), the complete suite (2163 tests,
6367 assertions, 1 skip) and real Chrome filter checks passed.
No Codex approval settings were changed. The intermittent HTTPS healthcheck
root cause remains a separate agenda item; recreation is not a root-cause fix.

## Scope

- Primary blue project/order creation buttons; blue outlined order import.
- Remove the "All orders" shortcut from "My orders" without removing its route.
- Remove the part-name search from the required-parts UI and controller.
- Add GET filters for manufacturing site, distributor and missing/all demand.
- Site means the order's manufacturing site (existing reservation-site fallback
  when no production site is set). Stock includes the selected site's descendants.
- Distributor matches non-obsolete native part purchasing sources. Multiple
  sources must not multiply required quantities. No purchase order is generated.
- Leave the navigation tree unchanged pending a new user-approved proposal.

## Quantity and permission rules

The new read-only `RequiredPartsPlanner` counts every physical lot once, ignores
unknown/non-positive stock, and preserves reservations belonging to orders
outside the current filter. Own reservations cover only their own order and
only up to physically coverable quantities. Excess allocation/reservation for
one order must not hide another order's shortage. Without a site filter stock
is pooled across sites, as stated in the UI.

`RequiredPartsFilterType` validates referenced entities, hides unauthorized
location/distributor lists and rejects forged filters without the corresponding
read permission. Invalid selections return a validation response and no rows.
The material-read permission remains mandatory.

## Verification/handoff

- Initial targeted run: four tests, two passing (permissions and buttons), two
  quantity/filter failures caused by incomplete bidirectional fixture links.
  Fixed fixture purchasing-source and part-lot inverse collections; the targeted
  rerun passes with 4 tests and 57 assertions.
- The first complete run with APP_DEBUG=0 used a stale Symfony test container:
  2163 tests, 3 errors and 1 failure in the new tests, 1 skip. It is not a passing
  full-suite result. The rerun with APP_DEBUG=1 (cache freshness checking) passed:
  2163 tests, 6367 assertions, 1 skip in 70.477 seconds, recorded in
  `full-tests-debug.log`.
- Static analysis of the three changed/new PHP classes passes.
- All 44 production Twig templates and both translation files pass syntax
  validation. The generated local-only reference-file change was reverted.
- Tests used isolated MariaDB database `partdb_required_test` with all 85
  migrations and synthetic fixtures, never the live database.
- Logs and the pre-test generated-reference checkpoint are under the ignored
  `var/required-parts-check-20260914/` directory.
- Candidate `06211e698517b8cc81b8d215be509f99c5cad906258bf6d8919b1c1fab44338e`
  MUST NOT be deployed: its authoritative Composer class map lacks the new
  classes. The Containerfile now adds offline `composer dump-autoload --no-dev
  --classmap-authoritative --no-scripts --no-plugins --no-interaction`.
- The corrected candidate passed real Chrome login, combined GET filters,
  retained selections, quantities, distributor change, primary creation buttons
  and absent My-orders shortcut. No JavaScript errors were observed.
- Visual review at 1440 and 1024 pixels caught inherited narrow label widths.
  Full-width labels were added and the browser checks repeated successfully,
  including a regression assertion for label height. No package versions changed.
- Both temporary test containers, their three exclusive synthetic-data volumes
  and their test network were removed after successful verification. Scripts,
  logs and screenshots remain in the ignored artifact directory.

## Deployment and recovery

- Image: `localhost/partdb-required-parts:2026-09-14`
- Image ID: `a993ea69bb886ecb96d1a5e7fc6911a221703ccc520fcf5c17b969179348bbcb`
- Previous image: `localhost/partdb-datasheet-assignments:2026-09-14`.
- Protected checkpoint: `var/checkpoints/2026-09-14-required-parts/live-before.zip`
  and `env-before.local`. All ZIP entries read and checked: 1757 entries,
  database.sql 3355544 bytes with schema statements. Environment variables
  were saved separately because the application's backup cannot include them.
- No database migration or live-data modification was needed. Compose recreated
  the application and dependent HTTPS proxy; MariaDB remained running.
- Live cache cleared, Doctrine mapping/schema validation passed, and runtime
  hashes of the new PHP classes and filter template match the workspace.
- HTTPS login returns 200 with the trusted local CA. All three live containers
  report healthy. No Git commit or push was performed.
