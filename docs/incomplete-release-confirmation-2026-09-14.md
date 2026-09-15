# Confirm incomplete releases without losing inputs — 2026-09-14

Status: implemented, verified and deployed to the LAN instance at 21:42 CEST.
Addresses F5 from the [workflow review](workflow-plausibility-review-2026-09-14.md)
and the requested explicit exception for missing required fields.

## Resulting behavior

Existing template `required` flags remain authoritative. Protocol template
controls and their meaning are unchanged. The existing completion validator is
used for the warning; optional fields and free notes remain optional. Protocol
drafts can be saved incomplete, including without a protocol date. Invalid
nonempty dates, malformed numbers and other invalid values remain form errors.

Completing a protocol with missing required inputs returns the submitted form
with a list of those inputs. The user must explicitly accept the warning and
submit completion again. Missing dates can likewise be explicitly accepted.
The existing protocol version check still rejects outdated forms and concurrent
writes. An accepted exception is recorded on the run and displayed in its
completed view; the existing completion user/time identify the actor and time.

Datasheet release uses one central validation pass with two result categories:
missing required values/notes can be explicitly accepted; invalid or ambiguous
sources and invalid table structure remain blocking errors. Existing template
requirements are reused, with no blanket requirement for optional fields or
free additional notes. The compatibility validator delegates to this same pass.

A failed release now renders the populated preparation form with HTTP 422
instead of redirecting and losing inputs. Editable notes, deliberate removal of
default text, selected protocol runs, and additional-note headings, text and
widths survive. Adding another note after validation uses a new index. The note
help correctly states that notes belong to the preview and released revision.

The explicit acceptance is bound to the precise submitted protocol values and
version, or resolved datasheet snapshot, and the warning list. Changed values
require a new acceptance. Existing CSRF and permission checks still apply.
Accepted missing datasheet fields are recorded in the immutable source snapshot
alongside the normal release user/time. The customer PDF remains the official
released document; no draft watermark is added because of an accepted exception.

Migration `Version20260914230000` adds a nullable JSON column for protocol
completion warnings. Existing runs retain their current content. No record
exceptions, test-data deletion or changes to stock handling are necessary.

## Verification

- Full isolated suite: **2,528 tests / 7,936 assertions**, no failures/errors,
  one skip and nine existing PHPUnit deprecations.
- Regression coverage includes incomplete drafts, unchanged optional-field
  behavior, acceptance missing/forged/changed, invalid decimal rejection,
  retained cleared defaults/notes/run selections, immutable exception records,
  PDF release/download, and the existing concurrency protection.
- Required-field tests distinguish missing or whitespace-only input from valid
  zero integers/decimals and false boolean values.
- Real Chrome on the actual candidate confirms incomplete draft saving,
  explicit warning acceptance, preserved notes/run choices, safe additional-note
  indexes after validation, renewed acceptance after changed inputs, and a real
  PDF download. No JavaScript errors.
- PHPStan level 5, production Twig validation and the production frontend build
  pass. No dependency versions changed.

Candidate image: `localhost/partdb-incomplete-release:2026-09-14`, ID
`3698d289b055d0b0426e2820f28f49bb060a027359b9c5d0f0dc989aeded0c6e`.
The candidate extends the deployed protocol guard and contains thirteen changed
source/template/migration files and compiled frontend assets. The Composer
class map was regenerated. Test evidence is retained privately under Git-ignored
`var/incomplete-release-20260914/`. Tests use synthetic data only.

## Deployment verification

HTTP writers were paused at 21:41:01 CEST. A fresh full backup passed archive
CRC and SQL-content checks (1,757 entries). Only application and HTTPS containers
were replaced. Database container identity and start time, persistent volumes,
networks, port bindings and the proxy init setting were preserved.

Exactly migration `Version20260914230000` was executed. Fingerprints confirm
all 75 previous table contents and 1,305 persistent files were preserved. The
comparison excludes only the newly added nullable completion-warning column
and one verified new database-update event (type 10). Existing protocol version
values are included in that comparison. No existing data was deleted.

At 21:42:00 CEST, application and HTTPS were healthy. Both localhost and LAN
HTTPS login returned 200 with successful certificate verification. Deployed
source and all compiled-asset hashes match the tested candidate. The protected,
Git-ignored checkpoint is `var/checkpoints/2026-09-14-incomplete-release-20260914T194013Z/`.
The previous image remains available; no backup restoration was performed.

The isolated test containers and their own resources were removed after final
checks. All live services are healthy, the migration state is current and the
HTTPS proxy has zero zombie processes. No Git commit or push was performed;
business data, credentials and backup archives remain outside Git.
