# Device and assembly status simplification

The user confirmed that an installed assembly is considered finished. Removing
it from a device must therefore continue to set its status to `completed`.
The earlier F6 recommendation to restore a previous status was based on a
different business assumption and is superseded. Installation and removal
behavior stays unchanged; no historical-status storage is added.

The user separately requested removal of `paused` from devices and assemblies.
The remaining choices are `planned`, `in_progress`, `completed`, `installed`,
and `scrapped` (Geplant, In Bau, Fertig, Eingebaut, Ausgesondert).

The enum, edit form, assignment eligibility, active-build queries, table badges,
and German/English translations use these five values. Both the standard
Symfony enum form field and the build wizard derive their choices from `BuildStatus`; no separate choice lists remain. The form submits
stable enum values, so numeric selections from an old open form are rejected
instead of being interpreted as a different status. The independent
production-project status `paused` remains available.

Migration `Version20260914233000` converts only existing
`production_build_instances.status = 'paused'` rows to `in_progress`. It leaves
all other columns and statuses intact and deletes no records. Its down migration
is explicitly irreversible because the original paused rows cannot subsequently
be distinguished from other in-progress builds.

## Validation

- Full suite: 2,531 tests / 7,951 assertions, no failures or errors; one existing
  skip and nine existing PHPUnit deprecations.
- PHPStan level 5 and all 48 production Twig templates pass.
- An isolated MariaDB migration verifies all device/assembly and production
  project rows, including every column: only paused builds become in progress.
  The migrated entity loads and its edit page offers the five remaining values.
- HTTP regression tests verify that both removed `paused` and old numeric `2`
  submissions are rejected without changing the stored status; explicit
  `completed` still saves normally. The test submits the required serial-number
  confirmation in all three cases.
- The earlier failing valid-save case omitted that serial confirmation. The
  fixture was corrected without weakening application validation; the final
  full suite was rerun successfully.

Private verification artifacts are under ignored `var/build-status-20260914/`.
No company data, backups, runtime environment files, or generated artifacts
were staged or committed.

## Deployment

Deployed to the existing LAN instance on 2026-09-14 at 22:26 CEST:
`localhost/partdb-build-status:2026-09-14`, image
`5282870d74b87a651fd002518b6fb67931972db9c1b96434e59b1cf042cfcd49`.

A fresh full backup was verified before replacing the app and HTTPS containers.
The existing database container, networks, named volumes, port bindings, and
HTTPS init setting were preserved. Migration `Version20260914233000` completed;
there were no paused builds to convert. Comparison verified all existing values
across 75 tables and 1,305 persistent files, allowing only the new migration
history row and its expected database-update log entry.

HTTPS checks passed for localhost and the LAN address with certificate
verification. All 12 deployed source-file hashes match the tested candidate.
The private checkpoint is referenced by
`var/build-status-20260914/deployment-checkpoint.txt`.

Temporary test containers and their isolated network were removed. Final checks
confirm all three live services healthy, migrations current, and zero proxy
zombie processes.
