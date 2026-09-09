# Production extension audit — 2026-09-09

## Scope

This checkpoint reviews the complete Part-DB application through its existing
automated gates and manually reviews the locally added production extension,
its permissions, file handling, template revision model and MariaDB data
relationships. It is the baseline before the reusable-template export/import
model is changed.

Orders, customers, build instances, protocol runs, released datasheets and
attachments remain explicitly outside the planned portable template package.

## Verified green

- All PHP sources pass syntax validation.
- Symfony validates 66 YAML files, 216 Twig templates, 72 XLIFF files and the
  production service container.
- Doctrine ORM mappings are valid.
- PHPStan reports no errors across 875 analysed files.
- PHPUnit completes 2,030 tests and 5,306 assertions with no failures; one test
  is skipped.
- A clean production container image builds successfully, including Composer,
  Symfony cache warm-up and the complete Yarn/Webpack production asset build.
- Composer and Yarn report no known dependency security advisories.
- The running Part-DB and MariaDB containers are healthy. The application runs
  with debug mode disabled, explicit trusted hosts and external attachment
  downloads disabled.
- Every MariaDB table passes `mariadb-check`.
- Read-only integrity queries found no cross-revision protocol rows, no
  cross-section answers, no cross-template released datasheets, no invalid
  import-mapping targets, no invalid parent/slot combinations, no duplicate
  order positions, no duplicate build serial numbers, no negative known stock,
  no over-reserved lots and no orphan attachment records.
- Production mutation routes reviewed use explicit permissions and CSRF
  protection. Template changes are additionally restricted to administrators.
- The datasheet editor persists a bounded versioned JSON structure, validates
  all values on the server and never accepts user-supplied Twig, HTML, SQL or
  executable expressions. Browser rendering uses DOM text nodes; PDF templates
  retain Twig auto-escaping.
- Uploaded production files use allow-listed extensions plus detected MIME
  checks, random stored names, protected download responses and path traversal
  checks. Released PDFs are stored with SHA-256 integrity metadata.

## Backup checkpoint

The pre-export checkpoint is stored in the sibling directory
`../PartDB-checkpoint-2026-09-09-pre-export` (relative to the repository root).
It contains the complete Git history, the current tracked and untracked working
tree, a transactional MariaDB dump, protected uploads, Part-DB public media and
the local runtime configuration. The directory and files have restrictive
local permissions.

All recorded SHA-256 checksums pass. The Git bundle and all archives were
opened successfully. The MariaDB dump was restored into a separate empty
MariaDB 11.4 instance; expected production-object and migration counts matched,
and every restored table passed `mariadb-check`. The temporary restore resources
were removed afterwards.

## Findings to resolve before template export/import

### 1. Template identities and revisions — blocking

Top-level system, protocol and datasheet templates do not yet have portable
UUIDs. System templates are mutable and their slots have no stable keys or
published revisions. Datasheet source paths currently contain installation-
local numeric protocol-template IDs. These values must not be frozen into an
exchange format. The required model work is detailed in
`docs/production-roadmap.md`.

The datasheet product title is also mutable top-level data although the title
is rendered as part of a particular revision. It must be snapshotted into the
revision before the first portable schema is published.

### 2. Protocol publication state — corrected and deployed

The protocol service leaves older revisions in `published` state when a new
revision is published. The current MariaDB consequently contains one protocol
template with two simultaneously published revisions. Collection ordering makes
the application choose the newest one, so current use is deterministic, but the
state is semantically ambiguous and unsuitable for import conflict rules.

Recommended rule: exactly one current published revision per protocol template;
publishing a newer revision retires the previous one while existing protocol
runs keep their immutable reference to that retired revision. The existing
record must only be normalized after explicit approval. That approval was given
on 2026-09-09. `ProtocolManager::publish()` now retires previous publications
after successfully validating the new draft. A dedicated migration keeps the
highest published revision per template and retires only older published ones.
Regression tests cover unchanged completed runs, continued editing/completion
of already-started runs, new-run selection and rejected invalid publication.

### 3. MariaDB migration baseline — corrected and deployed

Both the live database and a completely fresh MariaDB migration end with the
same Doctrine schema diff:

- two bulk-import JSON columns are still declared as plain `LONGTEXT` without
  MariaDB JSON validation;
- `log.level` produces a persistent `TINYINT(1)` change request.

Existing JSON values are valid. A small explicit multi-platform migration
should align only the MariaDB/MySQL representation, then be tested on a fresh
database and the restored checkpoint before it is applied to the live instance.

The follow-up migration test isolated a second cause: DBAL 4 introspects
`TINYINT` without a display width, while the custom `TinyIntType` explicitly
emitted `TINYINT(1)`. Altering the column alone did not remove that diff. The
correction is therefore in the custom type declaration (`TINYINT`), not a
change to stored log levels. Regression tests compare its SQL with DBAL
introspection for MySQL/MariaDB and retain integer conversion and the existing
SQLite/PostgreSQL fallback. The migration only alters the two JSON columns and
aborts before DDL if existing JSON is invalid.

### 4. Concurrent stock withdrawal — deferred multi-user hardening

The production build finalization is transactional, but it calculates available
stock before the transaction and does not lock the consumed `PartLot` rows.
Two simultaneous build completions can therefore both validate against the same
stock snapshot. The live data currently has no negative stock or over-reserved
lot. This concerns overlapping withdrawals of the same lot, not ordinary
sequential builds: the final stock check already catches intervening changes
visible at that point.

Follow-up review: the checked-in core Part-DB project build also calls
`PartLotWithdrawAddHelper::withdraw()` without explicit lot locking
(`ProjectController::build()` and `ProjectBuildHelper::doBuild()`). The helper
checks and changes the loaded amount; neither build path adds an atomic
compare-and-update or lot refresh under a row lock. The earlier urgency was
overstated for this development instance. Track targeted locking and
revalidation for later multi-user hardening, not as a prerequisite for template
export. No stock-booking behavior was changed in this follow-up.

### 5. Concurrent ordering edits — medium priority

The UI implements insertion semantics for system-template slots and order
positions. Normal use and tests pass, and current data contains no duplicates.
The owning template/order rows are not locked during reordering, however, so
simultaneous edits can conflict or produce duplicate order-position numbers.
Serialize these changes with database locks and retain the existing unique slot
constraint.

### 6. File lifecycle cleanup — medium priority

Deleting an individual production attachment removes its database row and then
its protected file. Deleting a complete order also explicitly removes its order
files. Deleting a build instance, however, relies on database cascade for its
attachment rows without invoking the attachment storage service, which can
leave inaccessible files behind. Cleanup should be explicit and logged; failed
physical deletion must remain detectable by an integrity/maintenance command.

### 7. Remaining release hardening

- Replace the temporary `MAGNICON` text footer with the approved logo asset.
- Add negative controller tests for permission boundaries, CSRF failures,
  immutable revisions and unauthorized protected downloads, in addition to the
  existing successful workflow tests.
- The ECS 13 configuration migration currently marks more than one thousand
  upstream files as fixable. Do not mass-format the repository. Establish a
  reviewed baseline or restrict the gate to changed files before using ECS as a
  required check.
- The current trusted-LAN HTTP setup is appropriate for development. Before
  internet publication, terminate TLS at a reviewed reverse proxy, enable the
  production security headers and secure cookies there, restrict trusted
  proxies/hosts to the deployed names and keep MariaDB on its internal network.
- Composer reports no vulnerabilities but identifies two abandoned transitive
  packages. Track their removal through upstream dependency updates rather than
  replacing them ad hoc in the production extension.

## Recommended next sequence

1. Completed: confirm and implement the single-current-published protocol
   revision rule and normalize the explicitly approved existing record.
2. Completed: retain backups, test the MariaDB correction against both a
   restored checkpoint and a fresh database, then deploy it locally.
3. Track stock and ordering concurrency and attachment cleanup as separate
   release-hardening work; repeat focused tests and the complete suite for the
   approved corrections.
4. Add portable UUIDs, stable system-slot keys and immutable system-template
   revisions; snapshot revision-sensitive datasheet metadata.
5. Replace numeric datasheet source IDs with portable identities and migrate the
   two existing templates.
6. Only then freeze version 1 of the allow-listed single/bulk template package
   and build dry-run import validation and conflict handling.

## Follow-up verification and deployment — 2026-09-09

- PHPUnit: 2,035 tests, 5,339 assertions, no failures, one skipped test. This
  suite uses the separate SQLite test database, not the running MariaDB.
- PHPStan: no errors. PHP syntax checks and `git diff --check` pass.
- The final application image builds successfully, including production assets
  and cache warm-up: `localhost/partdb-verified:2026-09-09`, image ID
  `bf4e54489f811d1c3c51eb1ac44e883dff13348eb63e8c30309de576cee3338d`.
- Both a restored database backup and an empty MariaDB 11.4 database pass all
  applicable migrations and Doctrine schema validation; the fresh database
  executes all 78 migrations. No PostgreSQL integration run was performed.
- In the restored database, data-only dumps of protocol runs, run rows and
  answers are byte-identical before and after migration.
- The running local instance was briefly stopped for a final backup and
  migrated with the verified image. Its ORM schema now validates without a
  diff. Template 2 revision 1 (ID 4) is retired, revision 2 (ID 5) remains
  published, and both existing drafts remain drafts. The same data-only dump
  comparison also passed on the live database before restarting the app.
- Additional protected SQL backups and before/after comparison dumps are in
  `var/checkpoints/2026-09-09-schema-revisions/` (Git-ignored). The original
  application image is retained as `localhost/partdb-before-fixes:2026-09-09`.
  The earlier full pre-export checkpoint remains unchanged. These migrations
  deliberately do not offer an automatic downgrade that would remove JSON
  validation or guess historical publication states; recovery uses the saved
  database and matching application image.
- Unresolved import assignments may be saved for later completion in a
  separate staging area. Structured portable datasheet sources and this
  deferred-mapping workflow are recorded as planned model work, not implemented
  export/import functionality.
