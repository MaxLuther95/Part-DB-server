# Production extension roadmap

Last updated: 2026-09-09

This file is the shared list of deliberately unfinished production-extension
work. An item remains open until its behavior, data migration, permissions,
tests and documentation are complete.

## Next architecture decisions

- [ ] Integrate the complete production-extension audit trail into Part-DB's
  existing central history/event-log mechanism as far as its public structure
  permits. Preserve actors, timestamps, affected objects and meaningful event
  details, migrate or retain existing production history safely, and remove
  the separate order-history presentation only after the central integration
  is verified. Do not redesign Part-DB's core history structure for this.
- [ ] Replace the current live template behavior for newly created order
  positions with an order-owned snapshot. Later changes to a system template
  must not silently alter an existing order. Updating an existing order from a
  newer template revision must be an explicit, reviewed action.
- [ ] Stabilize the portable template model and then implement a versioned
  export/import package for system templates, order-import mappings, protocol
  templates and datasheet templates. This is deliberately a reusable-model
  exchange format, not an order or production-data export. Complete the model
  decisions and migration items in `Template portability and export` below
  before defining version 1 of the package.
- [x] Add protected attachments to devices and assemblies (`BuildInstance`).
  Reuse the hardened production upload/download rules and shared storage code,
  but keep a database relation to the concrete physical instance.
- [x] Build one generic editor for structured internal protocol/run-sheet
  templates. Electronics, cables and complete systems use the same engine;
  their different contents belong in user-created templates, not in special
  application code. Fields must be addable, removable and reorderable. Static
  notes, intermediate headings and constrained responsive widths provide the
  layout without embedding product-specific presentation code.
- [x] Define separately versioned customer datasheet templates. They select and
  format explicitly mapped values from one or more finished protocols;
  protocol fields are never exported implicitly.
- [x] Decide the release/correction workflow for protocols. Protocol runs are
  numbered per build instance. A run can be saved and edited as a draft over
  several days. The explicit `Finish` action freezes its parameters; later
  measurements use another numbered run instead of overwriting the finished
  result.

## Measurement protocol phase 1: database and user interface

- [ ] Allow zero, one or several published protocol-template revisions to be
  assigned as defaults to a buildable type while retaining manual template
  selection. The data model must not assume one fixed protocol type per build.
- [x] Give every protocol run instance-owned section records and answers and pin it to an
  immutable, published template revision. New template revisions therefore do
  not change existing runs; duplicating the field definitions into every run
  is deliberately unnecessary.
- [x] Support typed fields such as text, decimal measurement, integer, yes/no,
  selection, date/time and long notes. Store unit and precision with the
  pinned revision. Decimals currently use a fixed high-precision database
  scale. Configurable precision, limits and automatic result interpretation
  remain later extensions and are not required for the first SEL form.
- [x] Provide a separate three-state test field alongside yes/no. One button
  cycles through passed (green check), not used (orange dash) and failed (red
  cross); the stored values remain explicit for later validation and datasheet
  mappings.
- [x] Remove generic repeatable groups after the first practical trial showed
  that their purpose and deletion behavior were unclear. Each section now has
  exactly one answer set, enforced by the database. The pre-release test runs
  and template were explicitly discarded before this schema change.
- [x] Add static notes/intermediate headings and a constrained responsive
  12-column layout. Every element can occupy 25, 50, 75 or 100 percent and may
  explicitly begin a new row; mobile views stack the elements automatically.
- [ ] Decide whether individual protocol runs really need local fields. Fields
  can already be added, removed and reordered in a template draft; published
  revisions and runs are intentionally immutable in structure.
- [x] Show protocols as readable tables on the device/assembly detail page.
- [x] Permit multiple, sequentially numbered protocol runs for one serial
  number so initial test, retest and repair results are not overwritten.
- [ ] Extend the recorded author, creation/edit/completion/invalidation
  timestamps and invalidation reason with a dedicated event history if a
  field-level audit trail is required.
- [x] Add permissions for reading and editing/finishing protocols; no
  four-eyes approval is required. Changing published template definitions
  remains an administrator task.

## Customer datasheet phase 2: controlled PDF output

- [x] Build layouts from safe, predefined blocks (headings, fields, tables and
  notes), not from arbitrary executable Twig supplied through the UI.
- [x] Map output cells to stable protocol field keys instead of display labels.
  A safe source selector must support the current instance and explicitly
  related instances/protocols without allowing executable user expressions.
- [x] Validate that required customer-visible fields exist before a datasheet
  can be released.
- [x] Render a PDF from a released protocol and its pinned datasheet-template
  revision.
- [x] Save the exact released PDF as a protected, hashed artifact so the file
  delivered to a customer remains reproducible even after later template
  changes.
- [x] Support the customer access data as ordinary, explicitly selected output
  fields. The provided access data is intended for the customer and was
  confirmed to contain no sensitive internal information.
- [x] Fix customer output to English and A4 portrait, allow constrained
  25/50/75/100-percent placement and provide editable plain-text notes in both
  the template and one specific generated document.
- [x] Require an explicit run selection whenever more than one completed
  protocol matches a mapped value. Never use drafts or invalid runs.
- [x] Allow a mapped single value to select either the current device or an
  installed electronic by physical position and then choose its serial,
  product or published protocol value. The same source catalog remains
  constrained and non-executable.
- [x] Use the same A4 component styles in the editor and PDF renderer. All
  meaningful content blocks expose constrained size, alignment, bold, italic
  and underline options; the product title remains editable template data.
- [x] Offer only the three locally available, PDF-safe font families Sans
  Serif, Serif and Monospace per block. Arbitrary font uploads remain excluded
  until licensing, embedding and PDF security implications have been reviewed.
- [x] Start a new template with a fixed English document frame only: product
  title, customer, project, order number and Magnicon footer. All body content
  is added deliberately as a freely ordered and sized block.
- [x] Render component matrices with installed subprojects as columns and
  freely configured instance or finished-protocol values as rows, matching the
  supplied Position 1/2/3 electronics example.
- [ ] Replace the temporary `MAGNICON` footer wordmark with the approved SVG or
  high-resolution transparent PNG logo.
- [ ] Configure and review the first real electronics mapping against the
  supplied customer output. The starter layout deliberately contains no real
  measured values or credentials.

## Confirmed protocol workflow

- Publishing a new template revision retires previously published revisions.
  Existing draft and completed runs retain their pinned revision and values;
  an existing draft run can still be finished. Only new runs use the current
  published revision. Normalizing the existing duplicate publication state was
  explicitly approved on 2026-09-09.
- Every build instance can own more than one protocol run. Runs are numbered
  in a stable sequence within that instance.
- Saving does not finish a protocol. Drafts remain editable and can be resumed
  on later days without losing previously entered values.
- `Finish` is a separate deliberate action with a confirmation dialog. It
  validates required fields and then freezes the exact field set, values and
  template revision.
- A finished protocol is eligible as a source for its customer datasheet. A
  draft must never be used for a released customer document.
- The same authorized user may enter and finish a protocol; a second-person
  approval is not part of the workflow.

## Generic template architecture

- Protocol templates are independent, user-named definitions. Terms such as
  electronics run sheet, cable run sheet or system final inspection describe
  configured templates, not separate database models.
- A template revision consists of ordered sections and elements. Input fields
  and static note/intermediate-heading elements share stable technical keys,
  constrained widths and optional row breaks separate from editable labels.
- Common field types include text, decimal, integer, yes/no or test status,
  choice, date/time and notes, with optional unit, help text and required
  state. Template-level default values remain a possible later extension.
- A protocol run pins one immutable published revision, owns one answer set per
  section and its typed
  answers, and belongs to a serialized build instance. It can therefore use
  the same workflow for a board, cable, case or complete system without later
  template edits changing its historical meaning.
- Datasheet templates contain layout and field mappings, not another copy of
  the measured data. Generation reads existing finished protocols and adds
  only template-owned static headings or explanatory text.
- Datasheet generation fails visibly when a required mapped source value is
  missing or ambiguous; it must not guess between multiple protocol runs.
- Template editors use constrained sections, fields, tables and source
  selectors. They must not accept arbitrary Twig, SQL or executable formulas.

## Template portability and export — model review 2026-09-09

The current separation is sound: system templates describe build structures,
order-import mappings translate external position descriptions to one build
target, protocol templates describe internal data capture, and datasheet
templates describe controlled customer output. Protocol runs, answers and
released PDF documents remain instance-owned records pinned to immutable
revisions. These different responsibilities must not be merged merely to
simplify export.

The template model is not yet ready for a durable cross-installation package.
The following structure must be settled before an exporter is implemented:

- [ ] Add a stable portable UUID to every top-level system, protocol and
  datasheet template. Database IDs, names, timestamps and users are not
  portable identities.
- [ ] Give system templates immutable published revisions and give their slots
  stable keys. Nested system-template references must point to a defined
  revision, so later edits neither change existing orders nor silently change
  an already exported definition.
- [ ] Move or snapshot revision-sensitive display metadata into the published
  revision. In particular, the datasheet product title currently belongs to
  the mutable top-level template although it is rendered as part of a specific
  revision. Decide the same rule explicitly for protocol name and description.
- [ ] Replace numeric protocol-template database IDs in datasheet source paths
  with structured source references using portable template UUIDs. Existing
  stable section and field keys should
  be retained; they already survive a new protocol revision and are the right
  basis for value mappings.
- [ ] Define portable references to Part-DB core objects without modifying its
  core data model unnecessarily. Parts can be proposed by their unique IPN
  when one exists. Projects can be proposed by their complete hierarchical
  path. Missing or ambiguous matches must be shown for manual mapping during
  import; neither a database ID nor a display name alone may be guessed.
- [ ] Treat every order-import mapping as dependent on exactly one target: a
  system template, a Part-DB project or a Part-DB part. Its normalized source
  description can be transported directly, but the target must be resolved by
  stable template identity or explicit Part/Project mapping before commit.
- [ ] Include future default protocol assignments as references between stable
  template identities once that assignment model has been implemented.

Version 1 is explicitly limited to reusable definitions and their declared
dependencies:

- included: selected system templates and their slots, selected order-import
  mappings, selected protocol templates and revisions, selected datasheet
  templates and revisions, nested template dependencies, safe layout/style
  settings and source mappings;
- excluded: orders, customers, production projects, project positions, built
  instances, serial numbers, protocol runs and answers, generated/released
  datasheets, attachments, stock, users and history records.

The exchange contract itself must be designed as a small allow-listed and
versioned JSON format, separate from a complete Part-DB backup:

- [ ] Add a manifest with format name, schema version, package identity,
  exported objects, dependency references and checksums. Do not serialize PHP
  objects or executable expressions.
- [ ] Export either an explicitly selected revision or an explicitly selected
  revision history. Drafts must be opt-in and remain recognizable as drafts;
  published revisions stay immutable after import.
- [ ] Resolve nested system-template and datasheet-to-protocol dependencies as
  a graph. The export preview must show what will be included and what remains
  an external Part/Project reference.
- [ ] Use one package format for both single and bulk transfer. A package can
  contain one or several explicitly selected root objects. Referenced reusable
  templates are included as a visible dependency closure by default; Part-DB
  parts and projects remain external references that must already exist or be
  mapped explicitly.
- [ ] Implement import through a temporary validation/staging step and preview,
  followed by one database transaction only after every required dependency
  has been resolved. Do not leave half-imported or silently reduced definitions
  in operational tables. Show missing references and identity/revision
  conflicts to the administrator. Never overwrite, renumber or merge a
  conflicting template silently; an explicit choice is required.
- [ ] Let administrators save an unresolved import in staging and resume its
  manual assignments later. Keep it visibly separate from usable templates;
  incomplete mappings must never become operational definitions.
- [ ] Define deterministic conflict behavior: an identical UUID/revision can
  be accepted as already present, a differing definition with the same
  UUID/revision is an error, and importing as a new template must generate new
  identities consistently across all internal references.
- [ ] Validate schema, enum values, string and collection sizes, graph cycles
  and checksums server-side. Plausibility checks must additionally cover unique
  positions and keys, slot quantity ranges, exactly one target per import
  mapping, complete allowed-content references, compatible protocol field
  types and the existence of every datasheet source field. If the package
  later becomes an archive with assets, additionally restrict file types,
  paths and unpacked size before extraction.
- [ ] Protect export/import with dedicated administrator permissions and add
  the operations to the production audit trail.

Recommended implementation order: first complete the model migrations above,
then freeze and document the JSON schema, add serializer/validator round-trip
tests, build the dry-run import and conflict UI, and only then expose export and
import actions in the template pages. This order keeps the first published
package version backward-compatible instead of encoding temporary database
details into a public format.

This package supplements but does not replace the MariaDB and protected-file
backup. The database backup remains the authoritative full recovery method;
the model package is the portable backup and transfer mechanism for reusable
configuration only.

## External design research — 2026-09-03

Recommended direction: implement the Part-DB-specific template editor and
datasheet mapping in Symfony/Stimulus, while adopting proven schema and
lifecycle concepts rather than a third-party form platform's complete storage
model.

- Use a deliberately bounded JSON Schema 2020-12 subset as the portable
  validation representation and keep layout/source mapping separate. The
  already locked `opis/json-schema` package can validate it server-side; it
  must become an explicit Composer dependency before production code relies on
  it. Remote references and implementation-specific executable extensions stay
  disabled.
- Borrow stable item keys, nested/repeating groups, published questionnaire
  versions and in-progress/completed/entered-in-error response states from
  [HL7 FHIR Questionnaire and QuestionnaireResponse](https://hl7.org/fhir/R5/questionnaireresponse.html).
  This is design inspiration only; the production extension will not become a
  medical FHIR implementation.
- Borrow the strict separation of data schema and UI schema from
  [Eclipse JSON Forms](https://jsonforms.io/docs/). Its MIT renderer is not the
  preferred runtime because it would add React, Angular or Vue to the existing
  Stimulus/Bootstrap frontend and does not provide the required visual template
  editor.
- Borrow measurement outcome, unit, dimension and binary-attachment separation
  from [Google OpenHTF](https://github.com/google/openhtf). Do not add its
  Python runtime to Part-DB.
- [Form.io's MIT browser builder](https://github.com/formio/formio.js) is the
  closest ready-made visual proof-of-concept and works in plain JavaScript. It
  is not selected as the persistence model: its schema is platform-specific,
  its builder can contain user-authored JavaScript evaluations, and its full
  server uses a different OSL-3.0 license. Any evaluation must use a local,
  pinned package, `noeval` mode and an allow-list of components.
- SurveyJS demonstrates useful dynamic matrices and panels, but its visual
  Creator and PDF components require developer licenses for private/commercial
  production use. The free MIT Form Library alone does not remove the need to
  build our own template editor.
- The older MIT JSON Editor is in maintenance mode and supports only older
  JSON Schema drafts. Its successor Jedison is currently too small to make a
  core production dependency. Both remain useful renderer examples only.

The repository already contains Symfony Form collections, Opis JSON Schema,
Dompdf and pdfmake. The first implementation should therefore require little
or no new runtime infrastructure. Client-side validation remains a convenience;
all saves and the `Finish` transition are validated again on the server.

## Datasheet editor baseline — 2026-09-04

- [x] Replace the discarded nested Symfony collection forms with one cohesive
  document-designer workspace: block palette and order on the left, the A4
  document in the center and properties of the selected item on the right.
- [x] Keep only product title, Customer, Project, Order no. and the Magnicon
  footer in the mandatory document frame; a new revision has no forced body
  blocks.
- [x] Provide an explicit component palette for headings, static text, notes,
  mapped values, component matrices, separators and page breaks.
- [x] Edit type, label/text, source, four-column width, row start, required and
  empty-value behavior in a context-sensitive inspector. Text-bearing blocks
  expose size, font family, alignment, bold, italic and underline without
  rendering another horizontal form grid inside the narrow inspector.
- [x] Let mapped values select from the current build instance, customer,
  order, project, completed protocol fields and direct installed components by
  physical position. No executable expressions are accepted.
- [x] Build component matrices with installed child instances on the X-axis
  and freely configured instance/protocol values on the Y-axis.
- [x] Preserve stable block and row keys when reordering and normalize all
  submitted positions server-side. Save in two phases so MariaDB uniqueness
  constraints cannot produce transient position collisions.
- [x] Exchange a small versioned JSON document schema, never user-authored HTML.
  Rebuild all canvas nodes using text content and validate types, lengths,
  sources, identities, limits and order again on the server.
- [x] Detect stale browser windows using a hash of the loaded revision. A save
  that would overwrite a newer editor state is rejected without changing the
  database.
- [x] Use the PDF component classes in the live A4 canvas and keep the saved
  PDF preview as an explicit separate action.

### Visual-editor research used for the replacement

- [GrapesJS](https://grapesjs.com/docs/getting-started.html) provides the most
  useful free builder pattern: canvas, block manager, layer/order view and a
  trait/property panel. Its separate
  [Data Sources](https://grapesjs.com/docs/modules/DataSources.html) model also
  confirms that layout definitions should retain references rather than copy
  measured values. The complete GrapesJS HTML editor is deliberately not
  embedded: Part-DB needs only a small allow-list of document components and
  must not accept arbitrary HTML, CSS or component scripts.
- [SortableJS](https://github.com/SortableJS/Sortable) is used only for tested
  mouse/touch ordering. Version 1.15.7 is pinned in the package lock and is MIT
  licensed; server array order remains authoritative.
- [Paged.js](https://pagedjs.org/en/about/) and the
  [W3C CSS Paged Media model](https://www.w3.org/TR/css-page-3/) are retained as
  the reference for later multi-page preview work. Switching the released PDF
  renderer is a separate reviewed migration; the current immutable Dompdf
  output is not silently replaced during this editor rebuild.

## Electronics example mapping

- The board ID identifies the concrete electronics build instance, for example
  an XXF, SEL or CSE instance.
- The case ID identifies the case build instance in which the electronics is
  intended to be or has been installed.
- `Position` is assigned in the measurement workflow and determines the
  electronics' later physical installation place in that case (currently 1, 2
  or 3). It must not merely be inferred from a pre-existing parent/slot link.
- `Ch.-ID` is the channel assigned to the electronics in software and remains
  an independently editable protocol value.
- Exact current/voltage units and validation limits are deliberately deferred;
  the first generic implementation does not encode `µA` or `µV` semantics.
- When the relevant protocol is finished, board ID, case ID and physical
  position are stored in its snapshot. Later structural changes must not
  rewrite that historical measurement context.

## Example composition supported by the generic model

- Every serialized build instance can own its own numbered protocol runs and
  can be the target of a generated datasheet.
- An electronics instance first has its own internal protocol containing its
  component-level tests and measurements.
- Further values are measured after or in the context of installing that
  electronics in a case. These installed-state values belong to the assembly
  context and must remain distinguishable from the electronics' earlier
  component-level results.
- A datasheet for the complete electronics/case demonstrates how an output can
  combine explicitly mapped values from the installed electronics' finished
  protocols with values measured later in the case. Cable and complete-system
  templates use the same mechanism with different configured fields.
- Each exported value retains its source protocol, run number and field key so
  the generated datasheet remains traceable.

## Attachment and upload hardening

- [x] Keep uploaded file content in Part-DB's protected attachment storage and
  only metadata and relations in MariaDB. Do not make upload directories
  directly web-accessible.
- [x] Store detected MIME type, size, random storage name, SHA-256 checksum,
  category, uploader and timestamps for every build-instance attachment.
- [x] Require authenticated, authorized downloads and safe response headers;
  retain extension/content validation, file-count and total-size limits.
- [ ] Add a quarantine/malware-scanning integration before public deployment.

## Release and deployment follow-up

- [ ] Rehearse the MariaDB migrations on a one-to-one copy of the production
  data.
- [ ] Run a complete order-to-reservation-to-build acceptance test.
- [ ] Rehearse backup and restore, including protected production attachments.
- [ ] Harden and document the eventual reverse-proxy/public deployment.
- [ ] Review and replace the remaining abandoned upstream Composer packages
  when compatible alternatives are available.

## Source examples reviewed

The two supplied one-page PDFs are customer datasheet outputs, not the complete
internal run sheets. They establish two initial output profiles without storing
their real customer, serial-number or account data in this repository:

- cable: serial number, customer/date, cable and connector/flange properties,
  lengths, conductor properties, pin assignment and remarks;
- electronics: enclosure/serial identifier, electronics type, customer/date,
  a repeatable position/channel measurement table and controlled supplementary
  information.

An initial internal SEL electronics run sheet has also been reviewed. Its form
structure contains identification data, assembly variants, basic-function
checks, supply checks, configurable component values, a comparison-test
checklist, a repeated measurement/check matrix, bandwidth checks, numeric
offset/temperature-coefficient values and remarks. No real identifier or
measured value from that document is stored in this repository.

The actual cable run sheet is still required. The exact meaning of the
remaining abbreviated SEL fields needs confirmation before the first internal
template revision can be finalized; measurement limits are deliberately
deferred.
