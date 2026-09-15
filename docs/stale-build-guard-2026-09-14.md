# Reject outdated build wizards — 2026-09-14

Status: implemented, verified and deployed to the LAN instance at 20:46 CEST.
This addresses F1 and F3 from the [workflow review](workflow-plausibility-review-2026-09-14.md).

A build wizard previously kept the old type and material selections after an
order position changed. Finalization could assign the resulting device to the
new type while consuming the old type's material. It also allowed a previously
opened wizard to complete after the order was cancelled.

## Resulting behavior

Each new wizard records signatures of its relevant configuration: content type,
base projects/BOM entries, position assignment and quantity, order/site,
parent/slot placement, and assigned material. Every wizard stage checks that
state, and the finalization service independently checks it before claiming a
serial identifier or withdrawing material. An order build still requires the
order to be in production at completion.

Changed or removed types/positions, occupied positions, changed material or
ineligible orders receive HTTP 409 with a recovery page. The page explains the
reason, provides a route back to order/build selection and shows previously
saved device details. A new wizard requires fresh serial and material
confirmation. Descriptive edits that do not change the build remain allowed.

The session draft format is version 4. Already-open version-3 wizards must be
restarted; they do not have the configuration signatures needed for validation.
This is a session format change, not a database migration or an order snapshot
model. No exception for particular records or data cleanup was needed.

The accepted absolute stock-update behavior is unchanged. This correction
validates outdated workflow state; it does not add stock locks or claim to
provide general concurrency protection against all simultaneous configuration
edits. Protocol concurrency was subsequently addressed in the
[separate protocol correction](protocol-concurrency-guard-2026-09-14.md).

## Verification

- Regression tests first reproduced the missing finalization checks.
- Full suite on the deployed Trixie/PHP runtime with isolated MariaDB 12.3.3:
  **2,496 tests / 7,730 assertions**, no failures/errors, one skip and nine
  PHPUnit deprecations.
- Expanded focused checks: **21 tests / 120 assertions**. They cover native and
  system type changes, every ineligible order status, site/material/quantity/BOM
  changes, removed content/positions, occupied positions, missing signatures,
  old draft format, successful unchanged builds and explicitly reviewed
  replacements. Rejected cases preserve stock, serial counter and build records.
- PHPStan level 5 and build-workflow Twig validation pass.
- Real Chrome on the actual candidate image: type changes and cancellations
  return 409, leave stock at ten, leave the serial counter at one, and create no
  device. A fresh unchanged build succeeds, consumes three units, advances the
  counter to two and creates one device. No JavaScript errors.
- The source-only candidate contains exactly four application files. Its
  authoritative Composer class map was regenerated; no application dependencies
  or frontend assets changed.

Candidate image: `localhost/partdb-stale-build-guard:2026-09-14`, ID
`ecce8380bd01a0c32b2ee62614d5ace03ee32d5a87472c7d72ca1a9f271e4f6b`.
Private test logs, screenshots, source checkpoint and scripts are retained in
Git-ignored `var/stale-build-fix-20260914/`.

During verification, all existing containers were found stopped, including the
normal Part-DB stack. The existing release was restarted and HTTPS verified
before tests continued. The cause of that external stop was not established.
This was separate from deploying this correction.

## Deployment verification

HTTP writers were paused at 20:45:28 CEST. A fresh full backup passed archive
CRC and SQL-content checks (1,757 entries). Only the application image selection
was changed. The checked Compose plan recreated application and HTTPS, retaining
their volumes and bindings and the proxy's init setting. The database container
identity and start time remained unchanged; no migration ran.

Before reopening HTTP access, database fingerprints and persistent-file hashes
matched exactly: 75 tables and 1,305 files. All four deployed application files
match the reviewed working source. Application and HTTPS became healthy, and
both localhost and LAN HTTPS login passed certificate verification at 20:46:25.

The protected, Git-ignored checkpoint is
`var/checkpoints/2026-09-14-stale-build-20260914T184220Z/`. It includes the full
backup, previous local environment, data fingerprints and deployment events.
The previous application image remains available. No Git commit or push was
performed and no business data was added to Git.

The three synthetic test containers, their own anonymous volumes and their
internal network were removed. Final checks confirm all three live services
healthy and zero residual HTTPS proxy zombies.
