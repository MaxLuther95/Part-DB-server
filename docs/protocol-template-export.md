# Protocol template export and import, format version 1

## Scope and use

Administrators with protocol-template read permission can download one saved
revision from its template detail page, or open **Export protocol templates**
on the template list and select up to 100 revisions. Nothing is preselected.
Draft and retired revisions are included only when explicitly selected and
retain their source status. Empty drafts/sections can be exported without
publishing them. Inactive templates can also be backed up.

The download is UTF-8 JSON, not a PDF and not a database backup. Both endpoints
are read-only; selection submission uses Symfony CSRF protection. Downloads
use attachment disposition, `no-store` and `nosniff`. No temporary export file,
database mutation, database migration or system-template versioning is needed.

A single selected revision downloads as `Laufzettel_<template name>_<status>.json`,
both from the detail page and from the export selection. The status follows the
interface language, e.g. `Laufzettel_Prüfung SEL_Entwurf.json`. Unicode and spaces
are retained; unsafe filesystem/control characters are replaced and very long
names are shortened. This never changes the name stored in the JSON. Multiple
selected revisions remain one bundle named `Laufzettel_Sammelexport.json`.

System-template and build-project assignments are local eligibility rules, not part of format version
1. Exports contain no local system or project IDs; imported templates start without
assignments and must be assigned before creating runs. The optional notes field is
automatically available on every new run, including runs from imported
templates. Its filled contents are private run data and are never exported.

Only explicitly allow-listed template properties are serialized. Instance
protocol runs, answers, devices, serial numbers, orders, customers, users and
attachments are never traversed or included. Text deliberately entered into a
template is exported unchanged, so authors must still review their own text
before sharing a file externally.

## Contract

The root object has these properties:

- `format`: `partdb.protocol-templates`.
- `format_version`: integer `1`.
- `exported_at`: ISO 8601 UTC timestamp.
- `templates`: array of selected template definitions.

Each template contains `name` (string), `description` (string), `active`
(boolean) and `revisions` (array). Different templates with the same name stay
separate. Multiple selected revisions of one template are grouped together and
sorted by revision number. Unselected revisions are omitted.

Each revision contains:

- `number`: source revision number (integer).
- `status`: `draft`, `published` or `retired`.
- `change_note`: string or null.
- `published_at`: ISO 8601 timestamp or null; descriptive source metadata only.
- `sections`: array in ascending position order.

Each section contains `key` (its existing UUID), `name`, `description`,
`position` (integer) and `fields` (array in ascending position order).

Each field contains:

- `key`: its existing UUID; stable across copied template revisions.
- `label`: string.
- `type`: `text`, `integer`, `decimal`, `boolean`, `test_result`,
  `choice` or `static_note`. Removed legacy types are rejected during import.
- `unit`, `help_text`: string or null.
- `position`: integer; existing positions and gaps are preserved, not rewritten.
- `layout_columns`: 3, 6, 9 or 12 (25/50/75/100 percent width).
- `start_new_row`, `required`: booleans. Static notes are not required inputs.
- `options`: ordered array of choice strings or null.

All object properties listed above are always present. Empty collections are
JSON arrays, not objects. No PHP class names or installation-local database
IDs are serialized. Field text is data, never executable code.

## Import workflow

Administrators with template read and create permissions can open **Import
protocol templates** from the template list. Upload one version-1 JSON file
(single or bulk export). The complete file is checked before showing a preview;
upload/preview alone does not write any production entities.

In the preview, choose one source revision for each template to import and
review/edit its target name. The default is to skip every template. Each
selected definition becomes a **new template with draft revision 1**, regardless
of its source status/number. Unselected revisions are not imported. The preview
explicitly describes this behavior before confirmation. Original change notes,
section/field keys, positions, contents, choices, active flag and layouts are
preserved; publication timestamps and user attribution are not restored. A new
draft must pass the normal publication workflow before it can be used for runs.

Existing names (case-insensitive, using database comparison) and duplicate target
names within a batch block import and ask for renaming or deselection. Nothing
is silently replaced or suffixed. The preview retains selections after such a
conflict. Name checks are not a new global uniqueness constraint on ordinary
template editing. All selected inserts execute within one database transaction,
so a failure rolls back the batch.

The pending JSON lives only in the user's server-side session, is bound to that
user and a random preview identifier, and expires after 30 minutes. A completed
or cancelled preview is consumed; a replaced preview cannot confirm or cancel
its replacement. Upload, confirmation and cancellation require CSRF validation.
An abandoned expired session may remain on disk until the normal session
garbage collector removes it, but it can no longer be imported. No uploaded
file is installed into public media or interpreted as PHP, HTML or a template.

Validation rejects unsupported versions, missing/unknown properties, wrong
object/list/scalar types, invalid UUIDs/timestamps/enums/widths, duplicate
revision numbers, duplicate section/field keys or positions, invalid text and
inconsistent static-note settings. Limits are 2 MiB per file, 100 templates and
100 total revisions, 200 sections per revision, 500 fields per section, 5000
total fields and 200 options per field. Text lengths are also bounded by the
database representation. Empty draft sections and incomplete choice lists can
be imported as drafts, but still need completion before publication.

## Deliberate boundaries

This is definition transfer, not restoration of a complete revision history,
database backup, system template or measured protocol run. Source `published`
status is not authority to publish data in the target installation. The file
is not signed, and its metadata is not proof of origin.

Top-level templates currently have no persistent portable identity. Template
names and section/field keys must not be guessed to represent a global
template identity; in particular, an export does not invent a new UUID on
every download. The importer therefore creates new drafts only; updating or
merging existing templates requires a separately agreed identity/mapping
workflow. This limitation does not prevent lossless transfer of field definitions.

Template name, description and active state are current template metadata at
download time, even when an older revision is selected. They are not claimed
to be historical snapshots. System-template revisions, cross-template source
mapping and a combined four-model package remain separate future work.

## Verification — 2026-09-10

- Nine focused tests, 134 assertions: every existing field type, text and
  layout preservation, revision selection/grouping, equal-name separation,
  empty/oversized selections, HTTP download headers, exclusion of real run
  data, unchanged run/revision state, invalid selections/CSRF and access
  restrictions for anonymous users, ordinary readers and administrators
  lacking template read permission.
- Full SQLite-backed PHPUnit suite: 2,044 tests, 5,473 assertions, no failures,
  one skipped test. PHPStan and Symfony container/Twig/YAML validation pass.
- The production image builds and was deployed to the local Podman instance.
  A read-only exporter smoke test against its MariaDB verified matching
  revision/field counts and unchanged protocol-run references and states.
  ORM schema validation remains clean; no migration was added or executed.
- HTTP/form behavior was tested through Symfony's browser client. A visual
  browser acceptance check of the installed page remains a user check.

### Import follow-up — 2026-09-10

- 29 focused importer/validator/controller tests, 86 assertions: round-trip
  field fidelity, draft-only creation, bulk import, conflict prevention,
  retained preview choices, malformed/unsupported files, permission and CSRF
  boundaries, stale/replayed previews, cancellation and transactional rollback
  after an injected failure on the second insertion.
- Full PHPUnit suite: 2,073 tests, 5,559 assertions, no failures, one skipped.
  PHPStan and Symfony container/Twig/YAML checks pass.
- On an isolated restored MariaDB 11.4 copy, exporting existing definitions,
  importing selected revisions and exporting again preserves their complete
  section/field definitions. Existing protocol-run references/states remain
  unchanged. A MariaDB trigger deliberately failing the second insertion also
  confirms that the earlier insertion rolls back. No test templates were
  inserted in the working database.
- No schema change or data migration is required. A protected pre-test SQL
  backup is retained under `var/checkpoints/2026-09-10-protocol-import/`.

### Browser download correction — 2026-09-10

- Direct revision links and the bulk export form bypass Turbo with
  `data-turbo="false"` and `data-turbo-frame="_top"`, matching Part-DB's
  existing export forms. Revision links also carry the native `download`
  attribute. Otherwise Turbo fetches the JSON inside the content frame without
  starting a browser download.
- Controller regression tests follow the actual overview/revision links and
  check the download attributes as well as attachment headers and JSON content
  (9 focused tests, 139 assertions).
- An isolated headless Chrome check used controls rendered by authenticated
  Symfony test requests and the installed Turbo library. Both missing downloads
  were reproduced without the attributes; with them, GET and POST downloads
  completed and produced valid JSON. Test fixture database changes were rolled
  back. No live data, permissions or export format were changed.
