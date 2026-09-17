# Order creation and input hints — 2026-09-17

## Cause and correction

The manufacturing snapshot factory rejected every native BOM entry without a
linked inventory part. Native Part-DB explicitly supports named non-part entries,
including services. Both PDF imports mapped to a manufacturing source and manual
order-position creation therefore failed for otherwise valid BOMs.

Snapshots now preserve those entries with their name, quantity and a null
`part_id`, including nested and optional manufacturing sources. Material planning
and withdrawal omit non-part entries, matching the native build workflow. A
non-null part ID whose inventory reference is missing still raises an error.
Entries without either a part or a name, and unsaved inventory parts, remain
invalid. Existing snapshots are unchanged; no schema migration is required.

## Input hints

The shared single, multiple, structural-entity and inventory-part selectors now
use true input placeholders instead of selectable empty items. Typing hides the
hint; clearing restores it. Existing selected values remain actual values. The
structural selector also handles creating a child from an empty selection without
attempting to read a nonexistent selected item.

Native text inputs and textareas retain browser placeholder behavior. The
attachment category's `Allgemein` text is now a placeholder rather than a
prefilled value. The existing server-side default still applies when left blank.
Business defaults such as quantities, units and existing saved values are not
converted into hints or erased on typing.

## Verification

- The new manual-position and mapped-PDF-import regressions failed before the fix.
- Synthetic MariaDB production controller/service/entity/form suite: 277 tests, 2,213 assertions;
  one existing SQLite-specific fault-injection test is skipped on MariaDB.
- Regressions cover nested/optional free BOM rows, frozen definitions after source
  edits, unchanged material requirements, and the missing-inventory-part guard.
- PHPStan and production Twig lint pass; the production frontend build succeeds.
- Browser verification uses an isolated candidate image and synthetic data only.
  Private logs, browser artifacts and deployment backups stay in ignored `var/`.

## Deployment verification

The candidate image was deployed after a full backup and passed HTTPS checks
with certificate verification. Before/after comparisons confirmed unchanged
contents across 78 existing tables and 1,315 persistent files. Database container
identity/start time, volumes, ports and networks were preserved. Runtime source
and compiled asset hashes match the tested candidate.

Image: `localhost/partdb-order-regressions:2026-09-17`. The previous
`localhost/partdb-protocol-management:2026-09-15` image remains available.
No company data was staged, committed or pushed.
