# Fixed order manufacturing definitions — 2026-09-15

Deployed at 10:55 CEST. Image `localhost/partdb-position-snapshots:2026-09-15`
(`deb9216ad3d58958abbc827fed525e018ce35a1825a92cb43593dcd1d37573e4`).
Migration: `DoctrineMigrations\Version20260915100000`.

## Behavior

Creating a manufacturing position captures its system/native-project definition,
all nested assembly choices, slot requirements and BOM parts/quantities. Nested
positions share the same stored graph. Later optional selections come from that
graph, including their original BOMs. An imported, initially unassigned row gets
its definition when assigned to a manufacturing source.

Existing positions keep this definition when templates or native BOMs change.
Template slot changes no longer synchronize them. Deleting a source slot leaves
its captured name and existing assignment visible. Configuration and material
planning use the same fixed definition as the build workflow; created physical
builds retain it. Stock availability remains live and parts are not duplicated.

The source selector is disabled for an existing position and its server-side
assignment is immutable. To adopt a current template, delete the affected
position and create a new one, subject to the existing build/material-allocation
deletion guards. There is no update, merge or automatic reset action. A lock icon
on the order explains that the definition is fixed.

## Migration and data handling

The migration creates three snapshot/reference tables and nullable links/slot
keys on positions, accessories and builds. It captures the current definitions
of pre-existing positions and preserves existing identifiers, assignments and
timestamps. Earlier, already overwritten template states cannot be reconstructed.
Incompatible legacy graphs fail during read-only planning before any queued SQL
is executed. Every mutation is queued as migration SQL; a dry run writes nothing.

The live preflight found no manufacturing positions requiring backfill. Legacy
backfill was therefore exercised with synthetic old positions, including nested
assignments. Existing production data was not deleted or substituted.

## Verification

- Full synthetic MariaDB regression: **2,562 tests, 8,221 assertions**, no errors
  or failures; nine existing PHPUnit deprecations and one existing skip.
- Snapshot controller tests cover nested BOM changes, later optional selection,
  deleted source slots, fixed selectors including forged POST data, new positions
  using newer definitions, read-only migration planning and preserved legacy rows.
- Actual migration dry-run comparison: all table contents and schema unchanged;
  real migration then captures an old position whose BOM remains fixed after edit.
- Fresh database installation runs all migrations successfully.
- PHPStan level 5, production Twig lint and candidate ORM/schema validation pass.
- Headless Chrome on the candidate image confirms old/new definitions coexist,
  deleted source slots remain configurable and no JavaScript errors occur.
- Deployment paused HTTP writers, verified a full backup (1,760 archive entries),
  and compared existing columns across **75 tables** and **1,310 persistent files**
  before/after migration. Values and file hashes match. Snapshot coverage and
  linked-build consistency checks pass; only the expected migration/log entry was
  added to those histories.
- Application/proxy were recreated; the database container identity/start time,
  volume attachments, ports and networks were preserved. Live source hashes match
  the tested image. Hostname, hotspot-IP and localhost HTTPS checks return 200
  with successful certificate verification.

## Operating boundaries

Snapshot definitions retain references to actual source and inventory entities.
Removing a real stock part does not invent replacement stock; missing captured
parts cannot silently disappear from material requirements. Removing the source
system/native project still prevents starting new builds from that deleted type.

Protocol and datasheet revision workflows retain their separately agreed rules;
this change freezes manufacturing definitions, not future measurement records.
Existing published records are not rewritten. Simultaneous absolute stock writes
remain the explicitly accepted existing behavior.

Recovery should use the protected deployment checkpoint and the preserved prior
image `localhost/partdb-open-order-items:2026-09-15`. Do not apply an old database
backup over newer work without reconciliation. Test logs, screenshots, checkpoints,
credentials, certificates and customer data remain under ignored private paths;
no Git staging, commit or push was performed.

The concurrently requested stable local hostname and temporary hotspot access are
recorded in [local HTTPS](local-https.md). Client-side mDNS resolution and CA trust
must also be available on each other computer.

## Final cleanup

The three isolated test containers and their network were removed after copying
the test report. All live services remain healthy; migrations are current and
the proxy has zero zombie processes. Optional removal of the older image tag
and dangling image remnants was not executed: automatic approval review timed
out and the permitted retry reported model capacity exhaustion. The current
image and rollback images remain available; no live volume cleanup was attempted.
