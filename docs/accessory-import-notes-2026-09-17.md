# Complete imported notes for accessories

The preceding position-note change covered manufacturing and pending rows but
left the stock-part assignment branch unchanged. That branch generated a short
`PDF-Position …` provenance string instead of copying the imported description.
The accessory note column and validator also limited text to 255 characters, and
the order rendered accessory notes as inline text instead of a note marker.

## Correction

- Every stock-part assignment copies the import row's complete notes into the
  existing `ProjectAccessory.note` field. The same resolver handles immediate
  import mapping and later assignment. Empty source notes remain empty.
- The column is widened from VARCHAR(255) to text by migration
  `Version20260917090000`, preserving existing values and the empty default.
  The validator permits 50,000 characters, matching the import review limit.
- The existing accessory editor uses a multiline textarea with the same limit.
- A shared Twig macro renders the existing escaped yellow note marker for
  manufacturing positions, pending/note-only import rows, additional accessories,
  and accessory assignments within positions. Notes are no longer duplicated as
  inline text in the accessory list.
- Assignment routing, quantities, snapshots, material requirements and existing
  notes are unchanged. Historical imports are not heuristically rewritten by the
  schema migration.

## Verified scenarios

The late stock-assignment regression failed before the correction. Tests now
cover immediate system/native-project/stock mappings, later assignments to all
three target types, long Unicode notes, empty notes, edit/clear, length rejection,
HTML escaping and preservation of old column values/defaults. The full synthetic
MariaDB production suite passes: 288 tests, 2,357 assertions, one existing
SQLite-specific skip. PHPStan, Twig lint and schema validation pass.

The provided PDF was parsed privately and its current live mapping types were
read using a read-only database transaction. All five position descriptions are
present: one system-template mapping, three stock-part mappings, one pending row.
Browser tests reproduce these types using synthetic target entities in an
isolated database. Original PDF text and screenshots remain in ignored private
storage and are not part of the image build context or Git.

The browser run passed for all five rows of the provided PDF, reproducing the
current assignment types. It compared every accessory's quantity, stored note,
actual tooltip text and edit-field value; it also verified a long manual edit,
clearing the field, note-only classification/reopening, and later stock-part
assignment without overwriting prior manual edits.

## Deployment

Deployed on 2026-09-17 after a verified full backup. The migration widened only
the accessory note column; comparison of all existing row values across 78
tables and all 1,318 persistent files passed, allowing only the expected migration
and migration-log records. Existing note text was not rewritten.

The application and HTTPS health checks passed. Both local HTTPS addresses
returned HTTP 200 with certificate verification, and deployed source hashes
matched the tested image `localhost/partdb-accessory-notes:2026-09-17`.
