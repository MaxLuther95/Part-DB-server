# Component table headings

Select a component table in the designer. **Spaltenüberschriften** appears
directly below the block label.

- An empty header source means automatic installation-slot names. A number is
  appended only for slots allowing multiple occupants or currently occupied
  more than once. This is a slot-local index, never an invented global position.
  Components without a named slot use product name and instance identifier.
- A mapped source is resolved relative to each child instance, using the same
  allow-listed catalog as table data rows. Product, serial/ID, installation and
  completed protocol values can therefore also supply the heading.
- The text pattern contains exactly one literal `{value}`, for example
  `Board {value}` or `Channel {value}`. It is plain text, not executable template
  code. Output is escaped in HTML/PDF and inserted with text nodes in the editor.
- Without a selected instance, previews use clearly described placeholders.
  Real-instance preparation and draft PDFs flag missing or duplicate headings.
  These are warnings, not extra required fields or automatic renumbering.
  Ambiguous/invalid protocol selections still prevent official release.
- Header-only protocol sources participate in run selection and source snapshots.
  New revisions copy the header configuration; published revisions remain
  immutable. Already released PDF files are unchanged.
- The top-right revision label is removed from the editor and newly rendered
  PDFs. Internal revision tracking and the footer identification remain.

## Verification — 2026-09-11

- PHPUnit: 2,104 tests, 5,935 assertions, one existing skipped test, no failures.
- PHPStan: no errors. All 42 production Twig templates pass syntax validation.
- Chrome: source selection, text pattern and absence of the top revision checked
  at 1920/1280/375 pixels, alongside existing publication and preview controls.
- Isolated MariaDB 12.3.2: additive migration, mapping/defaults, editor save and
  reload, automatic and mapped headings, duplicate warnings and PDF rendering.
  Synthetic database writes were rolled back.
- A two-page synthetic PDF was parsed and visually inspected: both pages retain
  header, logo/footer and page numbers, with revision only in the footer.
- Live image ID verified:
  `192274034b6e1ce9b86848b82a7e4b18b234ac2653d96c4b43504db8dc26168c`,
  tagged `localhost/partdb-table-headers:2026-09-11`.
  Live Doctrine mapping and schema validation both pass.

Protected backup: `var/checkpoints/2026-09-11-table-headers/pre-deploy.sql`.
SHA-256: `1298f7dfd0164446eeea402a6872135030566983985405118c0c66bed1b8a7f8`.
Previous image: `localhost/partdb-before-table-headers:2026-09-11`.
Synthetic PDF: `var/checkpoints/2026-09-11-table-headers/Header-Verification.pdf`.
No user templates, runs or released documents were deleted. No commit or push.
