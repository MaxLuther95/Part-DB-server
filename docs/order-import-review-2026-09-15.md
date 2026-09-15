# Order import requirements, references and notes

The import review now marks customer number, customer name, project number,
order number and the existing required order date with a visible asterisk.
Order description and project description are optional, including later edits.
Empty descriptions remain empty; the identifier supplies the display label.

Customer reference is a separate optional field stored as
`CustomerProject.customerReference`. It is displayed with the order details
and can be edited independently of notes. The optional notes field appears
below the imported positions, matching the existing order detail layout.
Invalid import submissions return HTTP 422 and retain submitted values.
Existing permission, duplicate-number, CSRF and attachment checks remain active.

## PDF extraction

Notes are taken from below the total amount and continue on subsequent pages.
Page headings and the recognized company/bank footer are excluded. Recognition
continues to produce editable suggestions requiring review before saving.
No OCR or arbitrary PDF execution is introduced.

The supplied two-page document exposed a custom Unicode font mapping for its
currency symbol. Supported single-byte fonts now honor MacRoman/Windows-1252
encoding and bounded Unicode character/range mappings. Ambiguous font aliases,
unsupported Unicode maps and excessive mapping data are rejected. Font maps
are cached per referenced object and capped at 128 aliases and one MiB per map;
existing PDF size, stream and text limits still apply. Notes are limited to
50,000 characters. The original PDF stays in protected attachment storage.

## Migration

`Version20260915080000` adds the nullable reference column. Existing notes in the
old import format, starting with `Kundenreferenz: `, are split into the reference
on the first line and any remaining notes. Other notes are retained. The
reverse migration recombines both fields. A synthetic round-trip test includes
reference-only records, ordinary notes, null values and literal zero values.

## Podman housekeeping

Removed 47 obsolete image tags and unreferenced build images, plus three
verified empty, unused volumes. Podman reported image storage falling from
30.23 GB to 2.35 GB (about 27.88 GB reclaimed). The four pre-existing containers,
all their attached volumes, current application/database/proxy images, build
tools and a recent application fallback were retained.
After removing the final test resources and superseded fallback tag, eight
visible images and eight referenced volumes remain. The final image storage is
2.35 GB, with 27.88 GB reclaimed. The immediate pre-deployment image remains
available as the application fallback.

## Verification

- Full fresh-fixture MariaDB suite: 2,551 tests and 8,117 assertions, no failures;
  one existing skip and nine existing PHPUnit deprecation notices.
- PHPStan level 5 and all production Twig templates pass.
- Parser tests cover multi-page notes, split total labels, omitted footers,
  missing totals, note limits, Unicode mapping, oversized compressed maps,
  ambiguous font aliases and the font-count limit.
- Controller tests cover each required header field, empty descriptions,
  independent reference/notes persistence, escaped content and subsequent edits.
- The migration round-trip retains references, notes, nulls and zero values.
- Chrome tested the actual final image with the supplied two-page PDF: five
  positions, both note sections, correct currency glyph, visible requirements,
  HTTP 422 for missing customer name, retention of inputs, successful import
  without descriptions, and clearing the reference without changing notes.
  No JavaScript errors were observed. The review screenshot was inspected.

## Deployment

Deployed at 08:09 CEST on 2026-09-15 as
`localhost/partdb-order-import:2026-09-15`, image
`a1417251871ecd9a84c7a10ddc44bd396f2ead1e3613fae15e405fed04748bbb`.
The 12 deployed source-file checksums match the tested image context.

A fresh full backup was verified before replacing application and HTTPS
containers. The database container was retained without restart. The expected
reference/notes transformation was applied; all 75 table fingerprints match
the expected result, and all 1,307 persistent files are unchanged. Only the
expected migration record and migration log entry were added. Volumes,
network attachments, port bindings and proxy initialization were retained.

All services are healthy; localhost HTTPS returned HTTP 200 with certificate
verification. The user's temporary hotspot requires no network configuration
change. The protected checkpoint pointer is
`var/order-import-20260915/deployment-checkpoint.txt`.
All three isolated test containers and their network were removed. Final checks
confirm healthy live services, current migrations and zero proxy zombies.

The supplied PDF, extracted content, browser screenshots, backups and logs are
kept only under Git-ignored `var/order-import-20260915/` and protected checkpoint
directories. Regression fixtures contain synthetic data. No company data or
runtime files were staged or pushed.
