# Backup failure handling — 2026-09-14

## Scope and behavior

The user prioritized the false-success finding before further feature changes,
the Git checkpoint and the planned 2.17 merge. This change does not upgrade
dependencies, migrate the database or change production models.

- MySQL/MariaDB and PostgreSQL dump exceptions propagate to the command's
  failure handler. Unsupported database platforms also fail explicitly.
- Every selected backup step must finish before the destination ZIP is written.
  A failed or empty SQL dump returns exit code 1 and no success message. No new
  incomplete archive is created and an existing destination remains untouched
  in this failure case, including with `--overwrite`.
- Missing required input files and ZIP exceptions also return failure.
- SQL temporary files use private temporary handles retained until ZIP writing
  completes, then closed/removed on both success and failure.
- Successful database/full backups and intentional attachments-only backups
  remain supported. A config backup still warns if `.env.local` is absent;
  deployment environment variables must be backed up separately.

This does not add atomic replacement for a disk/write failure during final ZIP
writing, nor does it certify consistency of a database and independently
changing attachment files during active concurrent writes. Those are distinct
backup concerns; keep previous generations and rehearse restore before migration.

## Verification

- `tests/Command/BackupCommandTest.php`: 22 tests / 84 assertions passed.
  Actual dumper subprocesses are exercised with test-only executables for
  nonzero exit and empty output, MySQL/PostgreSQL, database/full mode, and
  new/existing destinations. Success archives, unsupported platforms, missing
  SQLite/configuration files and attachments-only operation are covered.
  These command tests require no real database and do not switch the app to SQLite.
- PHPStan level 5 for the changed command: no errors; PHP syntax checks pass.
- Runtime image based on the exact previously deployed PHP 8.4.23 image with
  only `src/Command/BackupCommand.php` replaced.
- Real MariaDB 12.3.2 in an isolated internal Podman network with no published
  ports: populated synthetic Part-DB database and eight attachments/PDFs.
- Corrected `partdb:backup --full` succeeds. All eight archived files match
  their source SHA-256. Restoring its SQL into a second test database gives
  matching extended checksums for all 74 tables.
- A deliberately failing `mysqldump`, injected only into individual test CLI
  process environments, returns exit 1, emits no success message, preserves an
  existing ZIP's hash and produces no ZIP at a new destination.
- The main MariaDB and its real business records were not used for fault
  injection, fixture loading or restore. A pre-deployment full backup was read
  and checked: 1,757 entries, `database.sql` 3,353,107 bytes.

## Local deployment and recovery

- Local image: `localhost/partdb-backup-fix:2026-09-14`, image ID
  `7d489eb60d2167462dfdc77547ef8b283c2f9fc80cc5fb1e89397d3af0fd0f15`.
- Prior image retained: `localhost/partdb-night-fixes:2026-09-14`.
- The ignored `.env.mariadb.local` selects the new image. App and dependent
  HTTPS proxy were recreated; MariaDB was not restarted and named data,
  attachment, session and certificate volumes were preserved.
- Running command SHA-256 matches local source:
  `efd59e7c8ad55999c465b8cff43dafdc186c05eaf2941996b45a273858496c73`.
- HTTPS login returned 200 with the trusted local CA, not by bypassing TLS.
- A post-deployment full backup executed as the actual `www-data` application
  user also passed archive inspection: 1,757 entries, SQL 3,353,107 bytes.
  Database, app and HTTPS healthchecks passed after deployment.
- Private checkpoint directory: `var/checkpoints/2026-09-14-backup-fix/`,
  containing the previous environment file and real backups. Directory mode
  0700, sensitive files 0600; excluded from Git. Do not publish these files.
- Reproduction script, minimal image recipe and synthetic full backup are in
  the ignored `var/backup-fix-2026-09-14/` directory.
- The two isolated test containers, their three exclusive temporary volumes
  and their internal network were removed after verification. Synthetic data
  remain recoverable from the retained full backup. Real data were not deleted.

No Git commit/push or 2.17 merge was performed. Carry the regression tests into
that merge and repeat backup/restore acceptance for the final NAS candidate.
