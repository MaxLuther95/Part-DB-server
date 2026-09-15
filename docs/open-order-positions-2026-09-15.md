# Open imported order positions

An imported row without a mapping now appears in the order's existing positions
section with its PDF line number, description, quantity and unit. Its initial
state is **Open**. Open rows prevent completion and delivery through the
existing order validation and persistence guards.

Users with order read/edit permission can assign exactly one system template,
native build project or stock part. Assignment uses the same creation service
as the PDF importer: templates and build projects produce normal manufacturing
positions, including required default contents; stock parts produce additional
accessories and material demand. Quantities are preserved. The assignment form
requires an explicit unit correction when the selected target uses another
unit; it never silently converts sets into pieces.

Alternatively, an open row can be explicitly marked as a **Note**. Notes remain
in the positions section, do not require manufacturing or serial numbers and
do not block completion. Notes can be reopened or assigned while the order is
active. Completed, delivered and cancelled orders reject these changes.

The original import row persists independently of its global description
mapping. Its `pending`, `assigned` or `note` disposition is stored explicitly.
Assigned rows are not displayed a second time. Removing a global mapping does
not reopen an already assigned row. No placeholder templates or historical
content snapshots are used to represent unassigned rows.

## Consistency and authorization

Assignment and classification lock the order and import row within one
transaction. The submitted previous disposition must still match; already
assigned rows cannot be assigned again. Failures roll back generated positions,
accessories and history together. Every action requires order read/edit
permission and CSRF protection. Native project/part read permission is checked
when assigning those targets, including the template's base projects.

Migration `Version20260915090000` adds the disposition and marks previously
mapped rows as assigned. Unmapped rows become open. The live preflight found
only one unmapped row, in a commissioned order; no completed orders or existing
business records needed conflict cleanup. Dropping this distinction is an
explicitly irreversible migration.

## Verification

- Targeted controller/import/migration checks: 15 tests, 193 assertions.
- Covers completion blocked by open rows, explicit notes, terminal-order guards,
  stale submissions, exactly one assignment, quantity preservation, three target
  kinds, explicit unit correction, missing permissions, invalid CSRF, escaped
  descriptions and deletion of global mappings.
- Full fresh-fixture MariaDB regression: 2,560 tests, 8,194 assertions, no
  failures; nine existing PHPUnit deprecations and one existing skip.
- PHPStan level 5 and production Twig lint pass.
- Chrome tested the actual candidate image with synthetic data: visible open
  positions, note/reopen actions, later stock-part assignment, no duplicate row,
  notes retained, no JavaScript errors. The screenshot was visually checked.

Test data, browser artifacts, checkpoints and logs stay under Git-ignored
`var/unassigned-order-items-20260915/`. No business data is part of the image
build context, tests or Git changes.

## Deployment

Deployed on 2026-09-15 at 08:46 CEST as
`localhost/partdb-open-order-items:2026-09-15`, image
`4b3aff584e18335bfc5467e672f0e4659c1c8b606c7d9f7d172e3414100034ef`. All 15 deployed source-file checksums
match the tested context.

A fresh full backup with 1,759 entries was verified before the migration. All
75 table fingerprints match the expected disposition backfill; all 1,308
persistent files are unchanged. The expected migration record and one migration
log entry were added. The database container was not restarted; volumes,
network connections and port bindings were preserved. Localhost HTTPS returns
HTTP 200 with certificate verification. No hotspot configuration was changed.

The protected backup and deployment checkpoint are referenced from
`var/unassigned-order-items-20260915/deployment-checkpoint.txt`.

The three isolated test containers and their network were removed. Final checks
confirm all live services healthy, migrations current and zero proxy zombies.
After removal of the superseded rollback tag and dangling build remnants,
eight visible images and eight referenced volumes remain. The immediately
previous `localhost/partdb-order-import:2026-09-15` image is retained.
