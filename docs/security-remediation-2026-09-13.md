# Private access remediation — 2026-09-13

## Scope

Implemented and deployed the two approved items from the
[security review](security-review-2026-09-13.md): S1 (inconsistent production
authorization) and S2 (anonymous access/public native attachments). Local HTTPS
was subsequently deployed and is documented in [local HTTPS](local-https.md).
Other review items remain open. This does not approve internet exposure.

## Production authorization

- A shared `BuildInstanceVoter` requires device read permission, associated
  native project read permission, and system-template read permission where
  applicable. Installed descendants are checked as well; a restricted child
  denies the parent tree rather than leaking its data through a composite PDF.
- Device detail/edit/delete/unassign, protocol creation/read/edit/completion/
  invalidation, instance attachments, datasheet preparation/preview/release/
  download use this policy alongside existing operation and CSRF checks.
- Device listings, assignment candidates and order instance displays exclude
  instances denied by this policy. The DataTables base query is filtered so
  totals/search do not disclose excluded instances.
- Native project/system permissions remain module-wide. This is not a new
  customer-specific tenant isolation model.

## Native attachments and anonymous access

- All anonymous permission operations are explicitly denied in the live DB.
- `FORCE_PRIVATE_ATTACHMENTS=1` is enforced by `compose.mariadb.yaml`. The shared
  upload handler forces private storage, including API uploads and requests
  attempting to move existing private files back into public storage. The
  corresponding form checkbox is checked and locked by this installation policy.
- Existing Part-DB private-file routes retain both element read permission and
  `attachments.show_private` checks. Authorized users can view/download files;
  responses include `Cache-Control: private, no-store` and `nosniff`, retaining
  the existing restrictive attachment CSP.
- This policy does not privatize external URLs or built-in application artwork.
  It cannot revoke copies downloaded before this change. Original images are
  served by the existing private attachment route instead of public thumbnails.

## Migration and recovery

The new CLI command is read-only by default:

```sh
php bin/console partdb:security:privatize-media
```

Applying requires `--apply --offline-confirmed`, private-upload enforcement,
a DB/file backup and stopped HTTP writers. The migration validates source paths,
rejects missing files, symbolic links and conflicting private destinations, and
verifies SHA-256 copies before changing references/removing public originals.
Anonymous permissions and attachment references are updated in one DB transaction.
If the process fails after commit, keep the app offline and investigate/rerun;
private copies and the archive remain available. Never blindly restore only the
DB or only files after a partial migration.

Deployment used a short web-container stop; MariaDB remained running. No business
records were deleted and no schema migration was needed. Existing sessions were
backed up and restored into the new persistent `partdb_sessions` volume.

- Protected host checkpoint: `var/checkpoints/2026-09-13-private-access/`
  (ignored by Git; directory mode 0700). It contains a consistent SQL dump,
  public media/uploads/session archives, source snapshot, checksums and the
  previous container image ID/build-form template. Treat it as confidential.
- Verified file archive in the private uploads volume:
  `private-media-migration-13543e23bdc8563c9511b177/`. Includes all old public files
  and the reference mapping. Do not publish it or remove it without a separate
  backup-retention decision.
- Deployed image: `localhost/partdb-private-access:2026-09-13`, image ID
  `065bb90a74693fbed51a12992f65dccd4719d47abbce8afc0ffeb523a825e9b8`;
  also tagged as the configured `ghcr.io/maxluther95/part-db-server:current`.
- Previous container image retained as
  `localhost/partdb-before-private-access:2026-09-13`. Restoring the old public
  behavior would reopen S2 and needs an explicit decision; it is not an automatic
  rollback action.

## Verification

- Final full PHPUnit suite: **2,118 tests, 6,052 assertions**, no failures/errors,
  one skipped test. Includes native attachment HTTP checks: anonymous denied,
  authorized view/download allowed, revoked private permission denied, response
  headers correct.
- PHPStan: no errors. Changed order Twig template: valid syntax.
- New regression tests cover valid-CSRF forbidden protocol edits, direct PDF/
  attachment access, restricted installed child sources, mandatory private
  uploads/moves, migration dry run, data preservation, destination conflicts
  and missing files. Tests use isolated test data, not the live MariaDB.
- Live MariaDB/file check in a read-only transaction: **366 private attachment
  references and file checksums verified; 913 original public files archived;
  zero original public files remaining; zero allowed anonymous operations**.
- Live unauthenticated HTTP: login **200**; part detail, private attachment view,
  and sampled old PDF/JPG/PNG public URLs **302** to authentication, no document
  content. Private-upload enforcement is active.
- Both live containers healthy. LAN login at
  `http://192.168.178.20:8081/en/login` returned **200**. The deployment retained
  session files; no session identifiers or credentials were logged.

The HTTP results above describe this remediation's original verification, before
the subsequent [local HTTPS deployment](local-https.md). Remaining audit findings,
including administrator authentication, version updates and availability
hardening, still need attention before internet exposure.
