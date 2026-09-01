# Production extension checkpoint — 2026-09-01

This checkpoint records the cleanup and consolidated review of the
`ProjectManager` branch. It complements `docs/production-extension.md`, which
describes the functional architecture.

## Repository and data boundary

- Removed disposable `public/build.*` variants, local browser profiles,
  screenshots and the exited asset helper container.
- Added ignore rules for `public/build.*` and `.tmp`.
- Confirmed that database copies, uploaded media and development backups are
  ignored by Git.
- Added the previously missing Docker-context exclusions for `db`,
  `development_backups`, `public_media`, `.tmp` and generated build variants.
  Local company/test data therefore cannot be copied into a newly built image.
- Replaced company-like product/order examples in the current tests with
  synthetic demo names and numbers. The published branch history still
  contains two previously used product example names in an older test commit;
  it contains none of the reviewed real-looking order, customer, project or
  reference numbers. Rewriting published history was deliberately not
  performed as part of this non-destructive checkpoint.

## Hardening and consistency changes

- PDF review imports reject more than 500 submitted lines instead of silently
  truncating them. Empty descriptions and non-positive quantities are rejected
  instead of being silently normalized into different data.
- Existing customer/project master data can only be changed during import when
  the corresponding edit permission is present. Concurrent uniqueness races
  for order, customer and project numbers produce a controlled user message.
- Unexpected build-finalization failures are logged server-side and no longer
  expose raw exception/database details in the browser. Expected workflow
  conflicts remain readable to the operator.
- Production permissions now include all read dependencies for referenced
  Part-DB projects, parts, orders, customers and templates. The build operation
  also includes the material-withdrawal prerequisite, so the visible workflow
  cannot end in a permission dead end.
- The two collection counts used by paginated project/order lists use Doctrine
  `EXTRA_LAZY` loading, avoiding full collection hydration for a simple count.
- Build workflow regression tests explicitly enforce the selected rule: free
  builds and order-position builds create only the selected top-level physical
  instance; configured child systems/projects are assigned separately.
- PHP 8.2 compatibility and PHPStan type findings in the production
  controllers/services were corrected without changing Part-DB core entities.
- The two remaining `final` production entities were made proxy-compatible.
  Doctrine's production lazy-object generator otherwise prevented a clean
  release-image cache warmup.
- Container builds use the pinned Composer image `2.10.2`.
- `mcp/sdk` was raised from `0.7.0` to `0.7.1`, removing the high-severity
  advisory CVE-2026-53965 from the locked production dependency set.

## Consolidated verification

The checks ran in an isolated container and did not modify the running Part-DB
database.

- PHP syntax: **1,155 files**, no syntax errors.
- Symfony production cache warmup: successful.
- YAML lint: **72 files**, successful.
- Production Twig lint: **30 files**, successful.
- Doctrine mapping validation: successful.
- Fresh SQLite migration: **69 migrations / 1,472 SQL statements**, successful
  through `Version20260901080000`.
- Upgrade check on a copy of the current local SQLite database: already at the
  latest migration; mapping valid.
- Relevant PHPUnit suite: **67 tests / 304 assertions**, successful.
- PHPStan level 5 over the complete `src` tree: no errors.
- Composer production security audit after the lock update: no known security
  advisories. Composer still reports the upstream abandoned packages
  `composer/package-versions-deprecated` and `php-http/message-factory`.
- Complete Apache release-image build, including dependency installation,
  frontend asset compilation and production cache warmup: successful.
- Smoke check of the built image with Symfony in `prod` mode: successful.
- Image-content boundary check: no local `db`, `development_backups`,
  `public_media`, `.tmp` or generated build-copy directory is present.
- Image environment check: no application secret or workstation proxy value is
  embedded in the image configuration.

Doctrine's full fresh-SQLite schema comparison still proposes rebuilding the
original Part-DB `part_lots` table solely to change the storage-location foreign
key delete rule. No `production_*` table appears in the diff. This is a Part-DB
core migration/mapping difference and was intentionally not patched in the
extension.

## Environment observation

The workstation's Podman configuration still injects obsolete organization
proxy variables into containers. Direct networking works after those variables
are removed. For repeatable local diagnostics use a clean Podman proxy
configuration; do not copy workstation proxy values or application secrets
into deployable images.

## Deliberately deferred

- MariaDB migration rehearsal on a one-to-one copy of the productive data.
- Complete order-to-reservation-to-build end-to-end acceptance run.
- Backup/restore rehearsal and hardened Raspberry Pi/reverse-proxy deployment.
- Archive workflow and later removal/replacement of the two abandoned upstream
  packages.
- Any published Git-history rewrite; that requires an explicit coordinated
  decision and force-push.
