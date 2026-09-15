# Protect protocol edits and completion — 2026-09-14

Status: implemented, verified and deployed to the LAN instance at 21:15 CEST.
Addresses F2/F4 from the [workflow review](workflow-plausibility-review-2026-09-14.md).

An older browser form previously overwrote newer draft values without warning.
Overlapping database requests could also change answers after another request
completed the run. The status check only inspected the request's old entity.

## Resulting behavior

Protocol runs now carry an integer Doctrine optimistic-lock version. Answer
writes explicitly mark their run as modified, including writes performed without
controller `touch()`. The existing timestamp is used to schedule the parent
update; the integer version, not clock precision, detects competing writes.
Doctrine flush wraps parent and answer updates in one transaction. A version
conflict rolls back that entire flush, preserving the competing committed work.

The edit form carries the version seen when it was opened. The form checks this
version and draft status before applying submitted values. Saving, completion,
and invalidation also handle conflicts that occur between request loading and
flush. Standalone lifecycle POSTs require `_version` in addition to their
existing CSRF token and permission checks.

An outdated submission returns HTTP 409 with its unsaved values preserved for
comparison. The page has no protocol submit action and opens the current run
in a separate tab. Old forms cannot silently accept a new version and retry.
A submitted invalidation reason is likewise retained when its version is old.
Normal validation failures and successful draft/completion workflows retain
their existing behavior. Already-open pre-update forms lack a version and must
be reopened; submitted values are still shown on the conflict page.

Migration `Version20260914220000` adds only the version column, initially 1 for
existing runs. No special record exceptions or data cleanup are needed. The
accepted absolute stock-update behavior is unchanged.

## Verification

- The original two-connection reproduction first confirmed that a completed
  answer changed from `1.000` to `9.999`. With the correction, the second flush
  fails and the completed value stays `1.000`.
- A second independent-connection check confirms that an answer-only save
  prevents a stale completion from committing.
- Focused checks: **22 tests / 219 assertions**, including stale/missing/future
  form versions, old completion, late saves after completion, invalidation,
  answer-only writes, clear operations, same-editor writes, and retained form
  rendering after flush has closed the entity manager.
- Full suite: **2,517 tests / 7,864 assertions**, no failures/errors, one skip,
  nine existing PHPUnit deprecations. PHPStan level 5 and Twig validation pass.
- Two real Chrome sessions using the actual candidate image confirm HTTP 409,
  preserved unsaved inputs, an independent tab showing the current values,
  successful fresh completion, and protection against a later draft save.
  The conflict screenshot was inspected; no JavaScript errors occurred.

Candidate: `localhost/partdb-protocol-guard:2026-09-14`, ID
`5bdd1d944576ecea60c34d86cddc470356ccbf9cbd30bc578be41e544e91d800`.
The image extends the already deployed stale-build fix and contains seven
source/template/migration files. Its Composer class map was regenerated.
No dependencies or frontend assets changed.

Private evidence and scripts remain in Git-ignored
`var/protocol-concurrency-20260914/`. Only synthetic data was used in tests;
no firm data, credentials, commits or pushes were added to Git.

## Deployment verification

HTTP access was paused at 21:13:02 CEST. A fresh full backup passed archive
CRC and SQL-content checks (1,757 entries). Application and HTTPS containers
were replaced using the reviewed Compose plan; the database container identity
and start time, persistent volumes, networks, bindings and proxy init setting
were retained.

The single explicit migration added the protocol version column. An initial
strict comparison stopped deployment because the normal database-migration
listener appended one `DatabaseUpdatedLogEntry` (type 10). The cause was checked
before continuing: all 75 existing table contents and 1,305 persistent files
matched, excluding only the new version column and that verified new log entry.
Migration history contains exactly the intended additional migration. The full
backup was not restored; no existing records needed deletion.

At 21:15:10 CEST, application and HTTPS were healthy, localhost and LAN HTTPS
login returned 200 with successful certificate verification, and all seven
deployed source files matched the reviewed candidate. The protected checkpoint
is `var/checkpoints/2026-09-14-protocol-guard-20260914T191207Z/`.
It contains the full backup, previous local environment, private comparisons
and the complete deployment event record. The previous image remains available.

The synthetic test environment was removed after final verification. All live
services are healthy, migration status is current and the HTTPS proxy has zero
zombie processes.
