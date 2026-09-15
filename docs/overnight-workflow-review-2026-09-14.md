# Overnight production workflow review — 2026-09-14

Status: completed; five bounded corrections deployed, backup failure-path
finding deliberately deferred for discussion before NAS deployment.

Follow-up: after explicit approval, the backup failure path was corrected and
verified separately later on September 14; see
[backup correction](backup-failure-fix-2026-09-14.md). The finding below records
the original overnight result, not the current patched behavior.

## Scope and isolation

Authorized: realistic synthetic workflows, small bounded corrections, and
documentation of larger issues for discussion. No upstream merge, dependency
upgrade, NAS deployment or deletion of real production data was performed.

- Source checkpoint: `var/checkpoints/2026-09-13-night-workflow/source-before.tar.gz`.
- Browser data: isolated MariaDB 12.3.2, `partdb_night_test`, container
  `partdb-night-db-20260913`, internal Podman network `partdb-night-20260913`.
  The database port is bound to loopback only (`127.0.0.1:13307`).
- Full regression uses a second fresh database, `partdb_night_regression_test`,
  so fixture resets cannot erase the browser test evidence.
- Initial HTTP/browser checks: loopback port 18082, host PHP 8.4.25. The test
  server's debug mode is isolated and is not used by the normal installation.
- Runtime cross-check: loopback port 18083, Apache/PHP 8.4.23 from the exact
  deployed image, with only six corrected source/template files replaced.
- Chrome uses fresh temporary browser contexts and synthetic accounts. No real
  user password, browser profile or customer dataset was used.
- Browser artifacts and scripts are ignored by Git under
  `var/night-workflow-2026-09-13/`. Do not expose this directory publicly.

## Verified results

| Workflow | Result / evidence |
| --- | --- |
| Empty MariaDB migration | All 84 migrations / 695 SQL statements applied successfully. Repeated on the second fresh database. |
| Full MariaDB regression after corrections | 2,132 tests, 6,169 assertions, one SQLite-only fault-injection skip, no failures; `/private/tmp/partdb-night-regression-full.log`. Equivalent MariaDB fault injection is verified separately below. |
| Static checks | PHPStan level 5: no errors; all 221 Twig templates and 72 YAML files valid; `git diff --check` clean. |
| Reusable build models | Browser-created board, case and cable templates, BOMs, allowed board slots and independent serial ranges. |
| Build and stock | Board, cable and case created with actual synthetic Part-DB lot withdrawals; explicit serial confirmation is enforced. |
| Orders | Customer, project, optional delivery date, multiline description, order positions and production status saved. Changing the site correctly opens reservation review. |
| Installed hierarchy | Case assigned to the order; two separately built and measured boards assigned to distinct child positions. Physical parent, slot and index are reflected on the case page. |
| Protocol editor | All seven current field types created; section/field ordering and publication exercised. Required flags, notes and exact decimal input retained. |
| Protocol lifecycle | Save draft returns to the device; values/date/editor survive reopening; explicit completion freezes the run and prevents editing. Test-status button cycles pass / not applicable / fail. |
| Template export/import | Real browser JSON download and upload/preview/import completed. Filename uses the template name and status. Imported definitions have draft revision 1 and no system assignment; the source active flag is preserved, not forcibly cleared. |
| Late import failure on MariaDB | Injected SQLSTATE 45000 on the second template insert outside PHPUnit/DAMA (MariaDB trigger DDL commits implicitly). Both templates and all dependent rows rolled back: counts before/after 8 templates, 8 revisions, 8 sections, 42 fields; zero partial templates. The entity manager closes correctly after failure. Evidence: `logs/mariadb-import-rollback.log`. |
| Datasheet editor | Heading size/font/bold/italic/underline, static text, spacer, mapped values, editable note, separator and page break saved and reopened; published revision created. |
| Multipage PDF | Three A4 portrait pages with product heading, logo in every footer, page numbering, restrained red styling, and no lost final text. Preview is marked DRAFT; released output is not. |
| Component matrix | `Board bay 1` / `Board bay 2`, different serials and measurements; separate mapped value from the second child agrees with the table. `0` and `0.012300` are preserved. Visual evidence: `case-matrix.png`. |
| Official PDF download | Actual browser download succeeds after the correction below, including on the production-equivalent candidate image. Internal run notes are absent. |
| Attachment downloads | Device and order uploads/downloads exercised through the browser on the candidate image. |
| Read restrictions | Six real production/file endpoints denied to both anonymous and explicitly unprivileged synthetic users; authorized downloads remain available. |
| Repeat measurements | Incomplete completion returns 422 on the candidate; a completed retest makes the source ambiguous. Release without selection is rejected without creating a document; choosing the original run permits release. The old PDF's SHA-256 stays unchanged. |
| Duplicate proposal | Two independent browser sessions see `NAQ-0002`; first build commits, second is rejected. Stock decreases only once, from 16 to 14. Invalid finish CSRF also leaves stock unchanged. |
| Stale stock / retry | A reviewed build is rejected after a native Part-DB withdrawal empties the selected lot. Replenishing only that synthetic stock permits retry of the same workflow; `NAQ-0003` commits once and stock becomes 12. |
| Synthetic full backup/restore | A normal full backup restored into `partdb_night_restore_test`: all 74 table checksums match. Eight files verified (seven against stored SHA-256, the order attachment against its known fixture content). A fresh restored Apache/PHP instance authenticated the test user and successfully downloaded the restored three-page PDF. |

The serial/stock browser tests deliberately interleave separate sessions around
the final review. They are not a simultaneous-worker load test or a proof of
every possible database race.

## Small corrections

1. **Empty protocol field type/width caused HTTP 500.** Typed setters were
   reached before validation. Nullable form setters now leave the entity
   unchanged while `NotNull` rejects missing input. Twelve regression cases
   cover empty/missing/unknown choices for both create and edit. Before: eight
   errors; after: 12 tests / 108 assertions passed on MariaDB.
2. **Zero hidden in resolved-source overview.** Twig's truthy fallback rendered
   the string `0` as a dash. Use the empty-value filter; regression verifies
   the preparation page, and the browser now shows the actual zero.
3. **Invalid completion returned HTTP 200.** Incomplete run completion now
   returns 422 while retaining unsaved values/date for correction. No invalid
   completion is persisted. Existing lifecycle test extended accordingly.
4. **Unavailable production sidebar source initialized.** A projects-read
   permission alone rendered a production tree without an allowed source,
   causing a JavaScript initialization error. The sidebar now uses the same
   production-read conditions as its source menu. Both visibility cases tested.
5. **File links were intercepted by Turbo.** Official PDFs and device/order
   attachments were fetched without a browser download. Their links now opt
   out of Turbo and declare download behavior, matching protocol JSON exports.
   Actual browser downloads, not just HTTP 200 responses, verify this fix.

The six invalid create-field HTTP cases were also repeated on the deployed PHP
version in the candidate: all returned 422 without persisting the invalid field.

No entity definitions, migrations, serial-number rules, stock algorithms or
security permission grants were changed by these corrections.

## Important finding for follow-up: backup can report false success

**Priority: before unattended backup or NAS migration.** In the current core
`BackupCommand`, `backupDatabase()` catches SQL-dump failures and only prints an
error. `execute()` can then write the other full-backup entries and return
success. This is outside the five small production/UI corrections above and
was not changed or bundled into the live patch.

Reproduction used only the isolated candidate and synthetic data:

1. Place a deliberately failing `mysqldump` wrapper in a temporary directory.
2. Prefix `PATH` only for one `partdb:backup --full --no-interaction` invocation.
   Do not replace the installed binary or change the real service environment.
3. Observe the dump failure followed by `Backup finished`, process exit code 0.
4. Inspect the generated ZIP: 13 entries, but **no `database.sql`**.

Evidence: `logs/partdb-night-backup-failure.log` and the explicitly labelled
`INTENTIONALLY-INCOMPLETE-backup.zip` inside the private artifact directory.
Do not mistake that artifact for a usable backup. This reproduces a reliability
failure, not an externally exploitable data-access finding. Applicability to
the planned 2.17 merge has not yet been verified.

The separately created **real pre-update database backup is valid**: ZIP test
passed and `database.sql` contains 3,353,107 bytes. The normal synthetic full
backup/restore also passed. Until corrected, verify archive contents and do not
treat the backup command's exit status alone as proof of success.

## Test-environment findings, not product bugs

- The host had old September 4 JavaScript artifacts. The running image already
  had the September 13 bundle. The old host build was preserved under the
  checkpoint and the test server was switched to the exact deployed assets.
- The host CLI server needed `variables_order=EGPCS` to receive the explicitly
  isolated environment. A fresh `night` kernel cache avoided stale dev routes.
- Browser selectors were corrected for Tom Select controls, default-type
  submit buttons, asynchronous Turbo rendering and the expected reservation
  redirect. Failed harness assumptions are not counted as product defects.
- Chrome's intercepted preview response body appeared empty to the test API;
  a same-session HTTP request returned a valid PDF, and rendered PDF pages were
  examined. Do not classify the interceptor artifact as a PDF generation bug.
- An imported active flag is copied from the source. Draft-only publication
  and empty assignments prevent immediate use; this is the documented import
  behavior, not an inactive-flag regression.

## Deliberately deferred / limits

- Part-DB 2.17 and PHP/MariaDB maintenance updates remain a separate candidate,
  migration and regression exercise; see the version review. This night's
  passing suite does not certify the future merged version.
- Existing roadmap decisions about system-template snapshots/revisions and
  portable identities remain open. Published datasheet titles still live on
  mutable template metadata; old released PDFs are preserved as stored files,
  but metadata/version semantics need agreement before portable template export.
- Central Part-DB history integration remains deferred as requested.
- This is not a penetration-test certificate, a restore of the real customer
  installation onto the NAS, an unattended-reboot test, or an exhaustive
  multi-user load test. The restore rehearsal above used synthetic data.
- The real user-created electronics datasheet still needs comparison and
  acceptance with the supplied original; synthetic visual checks do not replace it.

## Runtime handoff and cleanup

- Deployed: `localhost/partdb-night-fixes:2026-09-14`, image
  `ee0194b08863e15290082e5afda59bc4798f444304245e65b162316cd05613f1`.
  `.env.mariadb.local` now selects this image for normal compose startup.
- Base/rollback image: `localhost/partdb-before-night-fixes:2026-09-14`, ID
  `065bb90a74693fbed51a12992f65dccd4719d47abbce8afc0ffeb523a825e9b8`.
  No dependency download or version change was part of the candidate build.
- The six original file checksums matched the pre-test checkpoint; the six
  running file checksums now match the tested working-tree files exactly.
- Compose recreated the app and its dependent HTTPS proxy; the database was
  not restarted. Database, uploads, sessions and certificate volumes retained.
  App/database/proxy all healthy. Migration status: up to date, none executed.
- HTTPS login returned 200 with the trusted root explicitly verified; HTTP
  port 8081 returned 308 to `https://192.168.178.20:8443/en/login`.
  No real user password was used for a new live login; authenticated flows
  were verified with synthetic users on the production-equivalent image.
- Recovery material, directory mode 0700:
  `var/checkpoints/2026-09-13-night-workflow/`. Real SQL ZIP and prior local
  environment file have mode 0600. Roll back only the image selection and
  recreate app/proxy if necessary; these corrections require no DB rollback.
- Source/control checkpoint and old host build retained. Current host build
  now matches the previously deployed September 13 assets. No new JavaScript
  source change was needed for the five fixes.
- The four temporary containers, their six exclusively used anonymous
  volumes and internal network were removed after the verified full synthetic
  backup. The host loopback PHP server was stopped. Only the normal three
  Part-DB services remain. Six host-generated upload files were moved into
  `host-test-files/` in the private artifact directory rather than discarded.
- Test scripts, selected logs, screenshots, PDFs and restore inputs remain in
  the private artifact directory for reproduction. The temporary browser venv
  is under `/private/tmp/partdb-night-browser-20260913`, not a project dependency.
- No commit, push, upstream merge or NAS/public deployment was performed.
