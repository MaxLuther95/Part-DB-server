# Fixed manufacturing definitions for order positions

Status: business rule confirmed and implemented on 2026-09-15; deployment
verification is recorded in the implementation report. This decision supersedes the proposed template-update, reset/restart and
replacement-revision workflows for existing positions.

## Confirmed behavior

- When an order position is created from a system template or native build
  project, capture its complete manufacturing definition. Positions assigned
  after PDF import capture the definition at their later assignment.
- Include nested assemblies at every level, allowed options, required contents,
  BOM parts and quantities. Changing a referenced assembly or native project
  must not indirectly change an existing position.
- Existing positions retain their captured definitions. Do not provide an
  action to refresh, merge, upgrade or reset them from changed templates.
- To use a newer definition, the user deletes the affected position and creates
  it again. The newly created position uses the current definition. Other order
  positions retain their existing definitions and assignments.
- Keep existing deletion and assignment safeguards. Do not automatically
  delete physical builds, detach devices, return material, erase measurements
  or rewrite released documents to make a position replaceable. The user must
  resolve applicable existing associations through explicit operations.
- Physical inventory remains current; a manufacturing-definition snapshot
  does not freeze stock availability or recreate parts as duplicate stock items.

The user explicitly accepts the additional manual work because changing an
already planned order to a later template definition is exceptional. Automatic
handling of in-progress template updates is therefore outside the agreed scope.

## Implementation

A position owns a fixed manufacturing snapshot. Its nested positions share that
snapshot and refer to their own definition within it. Captured definitions also
include optional choices that may be selected later. New positions and newly
assigned import rows capture the then-current source; they cannot replace an
existing position's snapshot. The configuration form, material planner and build
workflow consistently read these definitions. Completed builds retain their
manufacturing snapshot as well.

Template-slot edits no longer synchronize existing order positions. Removing a
source slot preserves its captured label and assignment in existing positions.
Native inventory stays live; snapshot references do not duplicate real parts.

Current position deletion rejects a position tree containing built instances.
It also rejects deletion while the order has material allocations. These are
existing guards, not new snapshot behavior or automatic rollback mechanisms.

Migration captures the current definition of existing positions and preserves
identities, associations and timestamps. It cannot reconstruct earlier definitions
that were already changed before snapshots existed. All data mutations are queued
as migration SQL, so a dry run leaves both schema and data unchanged. Incompatible
legacy definitions are rejected before the SQL phase instead of silently changed.
