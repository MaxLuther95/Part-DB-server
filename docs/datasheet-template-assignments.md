# Datasheet template assignments

## Agreed behavior

Datasheet templates can be assigned to multiple system templates/products and
Part-DB build projects. Unlike protocol templates, **multiple datasheet
templates per build type are allowed**. The later user clarification supersedes
the initial one-template proposal; no uniqueness-per-target constraint was
deployed.

Configure assignments when creating a datasheet template or via its detail
page → edit master data. Assignments belong to the template, not to a particular
revision. Each built instance offers only applicable, active templates with a
published revision and uses their current published revision.

- A system-built instance matches its own system template only.
- A project-built instance matches its own Part-DB build project only.
- Assignments do not propagate through parent/child instances, nested system
  templates or a system's base projects.
- Customer projects and orders are not build types and are not assignment targets.
- Existing templates start unassigned. No assignments are guessed from names,
  existing documents or their source protocols. Until assigned and published,
  they are not offered for creating new customer datasheets.
- Removing assignments does not alter or invalidate already released PDFs.
  Existing document downloads retain their independent access checks.
- Protocol-template assignment rules remain unchanged: one per build type.

## Authorization and persistence

Assignment fields require the corresponding system-template/project read
permission. Template creation/editing retains the existing administrator and
template-specific permission checks. Hidden assignment fields are not cleared
by submitting other metadata. Unknown submitted IDs are rejected.

Instance preparation, preview and release enforce applicability on the server,
not only through filtering links in the UI. CSRF and instance/hierarchy access
checks remain in place. Template-editor previews remain available to authorized
template designers without assigning every sample instance.

Migration `Version20260914090000` adds two many-to-many join tables, with composite
primary keys and cascading foreign keys. It neither edits nor deletes existing
business records. Ordinary indexes on target IDs allow multiple templates per
target; duplicate pairs are prevented by the primary key.

## Related UI changes

- Removed the built-instance navigation shortcut from datasheet-template and
  protocol-template overview pages.
- Customer, system-template and import-mapping create buttons use primary blue.
- System-template overview uses the same main-card structure as other production
  lists. Hierarchical expansion, nesting, existing actions and permissions remain.
- The production navigation tree was **not changed**. Proposed grouping, pending
  user acceptance: orders/projects; manufacturing (build, instances, required
  parts); templates (system, protocol, datasheet); master data (customers, serial
  number ranges, import mappings).

## Verification — 2026-09-14

- Fresh MariaDB 12.3.2: all 85 migrations / 701 SQL statements applied; Doctrine
  mapping and database schema match. Updating the previous synthetic schema
  required only the new migration / six SQL statements.
- Full MariaDB PHPUnit suite: **2,159 tests, 6,310 assertions, no failures,
  one SQLite-specific fault-injection skip**. No SQLite test database was used.
- Targeted tests cover multiple systems/projects/templates, assignment removal,
  inactive/draft/unassigned templates, direct URLs, CSRF, denied administration,
  invisible fields preserving existing links, no inheritance and continued
  download/checksum of previously released PDFs.
- PHPStan level 5 for changed PHP classes: no errors. All 44 production Twig
  files and both production translation files pass syntax validation.
- Isolated browser candidate uses the deployed PHP/Apache baseline. Actual
  template creation, multiple selection, publication confirmation and offering
  two templates on both a system and a project-built device passed. Primary
  buttons and hierarchical expansion passed; no browser JavaScript errors.
- Screenshots reviewed at 1440 px and 1024 px. Scripts, logs and screenshots are
  retained privately in `var/datasheet-assignment-check-20260914/` (ignored by Git).

The initial local test run encountered an old compiled test container; clearing
the test cache resolved it. An existing permission-test fixture was updated to
assign its datasheet explicitly; its denied-child access assertion is unchanged.

## Local handoff

Candidate image: `localhost/partdb-datasheet-assignments:2026-09-14`, based on
`localhost/partdb-backup-fix:2026-09-14`. It contains only this task's 15 changed
runtime files, including the additive migration; the backup correction remains.

Deployed locally after verifying the full checkpoint (1,757 entries; SQL
3,353,107 bytes). The migration was dry-run and applied explicitly before
recreating the app and its dependent HTTPS proxy. MariaDB was not restarted.
The application cache was refreshed. All 15 runtime file hashes match source;
the live database schema matches Doctrine, migrations are up to date, and the
HTTPS login returns 200 with the trusted CA. All three service healthchecks pass.

Protected pre-deployment checkpoint:
`var/checkpoints/2026-09-14-datasheet-assignments/`. Full backup and environment
file remain outside Git with directory mode 0700 / sensitive files 0600.

The isolated browser database is retained as `browser-database.zip` alongside
the test artifacts. The two test containers, their three exclusive data volumes
and internal test network were removed; no synthetic records were inserted into
the actual installation.

No upstream merge, dependency update, Git commit/push or real-data cleanup is
part of this change.
