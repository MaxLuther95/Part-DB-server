# German and English order confirmations — 2026-09-15

The importer now applies the same header extraction rules to German and English
confirmations, both for plain text and visually reconstructed PDF lines. A
language selection is not required.

## Corrected behavior

- Recognize German document, customer, project, date and reference labels alongside
  their English equivalents. Accept ISO dates and valid `DD.MM.YYYY` dates, and
  store both as ISO dates. Preserve multiword references and remove their trailing
  reference-date suffix (`from`, `von`, `vom`).
- Keep an explicitly empty customer field empty. Do not fill it with incidental
  text from the PDF's internal stream order. The existing mandatory review fields
  still require missing customer information before an order can be saved.
- Recognize German `Bezeichnung`, `Menge`, `Einh.`, `MwSt.` and `Einzelpreis`
  columns. Keep quantity and unit separate from the adjacent VAT/price columns.
  Retain the existing English column boundaries.
- Accept German piece spellings and normalize them to the existing `pcs.` unit.
  Preserve `psch`/`pauschal` as the distinct commercial unit **Pauschal (psch)**;
  do not silently convert a service lump sum into stock pieces. Existing mapping
  checks still require matching target units. Unmapped lines remain open order
  positions and can explicitly be classified as notes through the existing flow.
- Read notes after the final total in both languages and exclude German as well
  as English company/bank footers. Reference and notes remain separate.
- Normalize composed/decomposed Unicode text so German umlauts work regardless of
  the PDF's representation. Existing upload, decompression, stream, text, position
  and note limits remain in place.

## Verification

The two supplied redacted PDFs were read locally and checked in the candidate's
browser import review. Both produce their two expected positions, quantities,
units, references and notes; redacted customer fields remain empty. No real sample
order was saved in a test database. Company PDFs, extracted text and screenshots
remain under private ignored paths and are not added to source or test fixtures.

Synthetic tests cover German headers/columns, adjacent VAT values, footer removal,
Unicode units, blank customer fields, invalid dates, multiword references and the
complete upload/review/save flow for a German lump-sum position. The existing
English cases remain passing.

- Production regression: **266 tests, 2,118 assertions**, no errors or failures;
  one existing skip.
- PHPStan level 5 and Twig validation pass.
- Candidate Chrome review of both real samples passes with no JavaScript errors.
- Source/fixture whitespace checks pass. No commit or push was performed.

No database migration is needed: the existing commercial-unit string column can
store the new enum value. This change does not modify existing orders, mappings,
stock levels or manufacturing snapshots.

## Deployment

Deployed at 11:22 CEST as `localhost/partdb-order-languages:2026-09-15`
(`ec0c5fa2c574575dbb3579dc5b963520e8aceda2db0bd5006e88cad5bf339b41`).
A full backup with 1,760 archive entries was verified before replacing the
application/proxy. No migration was executed. All existing values across 78 tables,
all migration/log history and hashes of 1,310 persistent files match before/after.
The database container identity and start time, named volumes, networks and port
bindings were preserved. Live hashes of the three changed source files match the
validated candidate. Hostname, current hotspot IP and localhost HTTPS login pages
return 200 with certificate verification. The immediately preceding snapshot image
is retained as a fallback.
