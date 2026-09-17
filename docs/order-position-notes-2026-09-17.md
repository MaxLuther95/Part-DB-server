# PDF position descriptions as existing position notes

The existing manufacturing position notes field and yellow note icon already
provide editing and a hover preview. This change connects PDF import to that
existing behavior.

## Import behavior

- The short position title remains the mapping key. Text below that title is
  retained separately as multiline position notes.
- The layout parser follows description-column rows until the next position,
  preserving continuations across pages. Total, subtotal and footer rows bound
  the extraction; general notes below the total remain order notes.
- Invalid numbered rows with an unrecognized quantity/unit reject the import
  instead of attaching their text to the preceding position.
- The text fallback handles both single-line and vertically serialized positions.
- PDF text-object extraction consumes quoted strings before looking for `ET`;
  words such as `ETHERNET`, and literal `ET`/`BT` in notes, are not truncated.
- Position notes exceeding 50,000 characters are rejected, never truncated.
  Existing PDF size, decompression, stream and text-block limits remain active.
- The import review offers an expandable, editable position-notes field. Both
  parsed and manually added rows retain that field through validation/submission.
- Immediate manufacturing assignments copy the notes to every generated root
  position. Open rows retain notes until a later assignment; nested template
  positions and existing manually edited notes are not rewritten.

## Persistence and display

`production_order_import_lines.notes` is nullable text, added by
`Version20260917080000`. Existing rows receive null; no historical PDF or existing
position note is automatically rewritten. The column retains the import metadata
independently of later manufacturing edits. The initially missing stock-part branch is completed by the subsequent
[accessory-note correction](accessory-import-notes-2026-09-17.md); it copies the
imported detail text into the existing accessory note field as well.

Manufacturing positions use the existing note icon and edit field unchanged.
Unassigned rows use the same escaped note icon, and their assignment page shows
the text for review. All PDF-derived notes remain text, not executable HTML.

## Verification

Synthetic tests cover separate position/order notes, page continuation, quoted
PDF operator names, malformed numbered rows, length rejection, preview correction,
immediate and later assignment, multiple generated positions, HTML escaping and
preservation of old rows by the migration. The provided German and English sample
PDFs were checked privately against independent PDFKit extraction; their existing
header fields, quantities, units, mappings and general notes remain unchanged.

Private source PDFs, extracted text, test reports, screenshots and backups remain
under ignored local paths and are excluded from image build contexts and Git.

The final synthetic MariaDB production suite passed with 283 tests and 2,255
assertions (one existing SQLite-only fault-injection test skipped). PHPStan, Twig
lint and Doctrine schema validation passed. The browser test verified an actual
two-page PDF upload, preview correction, multi-quantity assignment, tooltip text
escaping, editing, and a later assignment with manually edited notes preserved.

## Deployment

Deployed as `localhost/partdb-position-notes:2026-09-17` after a verified full
backup. The new nullable column and migration/log entry are the only database
changes: all prior values across 78 tables and 1,316 persistent files matched
before/after checks. The database container, volume attachments, ports and
networks were preserved. HTTPS certificate checks and deployed source hashes
passed. The previous app image remains available locally. No Git commit or push
was performed during this change.
