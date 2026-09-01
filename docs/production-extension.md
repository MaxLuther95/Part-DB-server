# Production extension architecture

This document describes the production extension as implemented on the
`ProjectManager` branch. The extension is deliberately additive: it uses its
own domain objects, tables, controllers, templates and permissions and only
integrates with selected public Part-DB services and user-interface extension
points.

## Scope and terminology

```text
Production project (number, name, status, description, notes)
        |
        +-> one or more orders
              |
Customer -----+  (every order has exactly one customer and one project)
              |
              +-> Order positions (ordered systems/build projects)
              |     |
              |     +-> System template -> additive Part-DB base BOMs
              |     +-> Fixed slots -> allowed systems, projects or parts
              |     +-> Configured child positions and purchased parts
              |
              +-> Additional inventory accessories
              +-> Devices and assemblies (physical build instances)
              +-> Material reservations, project stock and consumption
              +-> Assigned Part-DB users ("My orders")
              +-> Notes, attachments and append-only history
```

A **production project** is the long-lived organizational container. It has a
number, name, description, status and notes, but no configuration. An
**order** represents one concrete customer commitment and owns configuration,
material planning, assigned users, attachments and build assignments. A
production project can receive several orders, including orders from different
customers. Every individual order has exactly one customer and one production
project.

For migration compatibility the PHP entity and some route/table names still
use the historical `CustomerProject` wording. In the user interface and this
document that entity is an order. The old `projectNumber` property on that
entity is likewise the order number. This internal compatibility naming avoids
an invasive rewrite and does not connect the extension to Part-DB's own project
entity.

All extension-owned tables use the `production_` prefix. Part-DB's own project
tree, BOMs, parts, lots, storage locations and users remain the source objects
that the extension references.

## System templates and order configuration

A system template is an independent, reusable configuration definition:

- It can reference zero, one or several Part-DB base projects. Their BOMs are
  additive and describe material that is always required for that system.
- It has a fixed commercial order unit (`pcs.` or `set`) used by manual and PDF
  order entry.
- Every slot has a stable position, minimum and maximum quantity, and an
  explicit allow-list of system templates, Part-DB projects and/or Part-DB
  parts.
- Allowed system templates become configurable child positions. The same
  template can be reused in several parent templates.
- Allowed Part-DB projects represent buildable assemblies; allowed Part-DB
  parts remain purchased inventory items.
- A single allowed choice is selected automatically when an order is
  initialized. Multiple choices remain visibly unresolved until a user makes a
  selection. Incomplete configuration can be saved during planning.
- Direct and indirect cyclic template nesting is rejected before saving.
- Moving or inserting a slot position shifts following positions instead of
  producing a uniqueness error.
- Slots with a maximum quantity of one derive their quantity from the
  selection. A quantity input is only shown for slots that allow more than one
  item.
- Template identity is its database ID and required name; no redundant template
  code is stored.

An order position selects exactly one system template or one direct Part-DB
build project. Nested position numbers (`0`, `0.0`, `0.1`, ...) are display
paths; every position also has its own database ID. A position has no separate
production status. The order view shows the status of its assigned physical
device/assembly or an unassigned marker.

Positions without physical assignments can be deleted together with their
nested configuration. Positions with assigned devices and orders that hold
allocated material are protected where deleting them would destroy production
traceability.

## Devices, assemblies and build rules

A device or assembly is a physical build instance. It references either a
system template or a Part-DB build project and can be linked to one order
position. Database constraints enforce one physical instance per order
position and globally unique non-empty serial numbers.

A serial number is optional while building. Without one, notes containing the
reason are mandatory and the internal ID is used as display identifier. An
order can only enter `completed` or `delivered` when every configured position
has exactly one assigned instance with a non-empty serial number. The rule is
validated both in the form and on the entity status transition.

Assignment is the single source of truth. Releasing an instance removes its
order and position relation while preserving the physical instance so it can be
reassigned. Physical parent/slot relations are synchronized with the configured
order hierarchy. Only matching, unassigned instances are offered for a slot.

The build wizard is session-backed and creates no incomplete database rows.
The deliberately limited workflow is:

1. A free build creates exactly the selected top-level system or direct
   Part-DB build project. For a system, only its additive base-project BOMs are
   consumed. Slot contents and nested systems are **not** built automatically.
2. Building from an order position also builds only that selected position.
   Configured child systems/projects must already exist and are assigned
   separately as matching devices or assemblies.
3. Select one top-level production site, enter an optional serial number and
   notes, then select concrete Part-DB lots from that site or its descendants.
4. The review step rechecks live availability. Final confirmation creates the
   instance, consumes project stock and reservations, withdraws selected lots
   through Part-DB's stock service and stores immutable usage rows in one
   database transaction.

This boundary avoids silently building or reconfiguring an entire nested
system when only one physical assembly is being manufactured. Configuration
can still be changed until an order is delivered.

## Material lifecycle

- Planning aggregates integer requirements and displays availability without
  changing stock.
- Commissioned and in-production orders can reserve concrete whole-number
  quantities at one top-level production site. Descendant storage locations are
  included. Optional receipts from another top-level site remain explicitly
  traceable.
- Reservations do not withdraw Part-DB stock. Production availability subtracts
  reservations belonging to other orders so the same stock is not planned
  twice.
- A reservation becomes stale when BOM demand, configuration, consumption,
  project stock or reserved quantities no longer match. The user is informed
  and can reconcile it; normal Part-DB stock changes remain visible as
  conflicts.
- Providing material withdraws a concrete Part-DB lot through the normal stock
  service and records an integer project-stock allocation with source lot,
  actor and optional manufacturer serial number.
- Building consumes applicable project stock and reservations in the same
  transaction as the real withdrawal. Immutable material-usage rows preserve
  the relationship between consumed lots and the built instance.
- Moving an order out of the commissioned production states releases remaining
  reservations and records the event in order history.
- The aggregated **Required parts** view contains only commissioned and
  in-production orders. Ordering proposals and returning project stock remain
  later workflow steps.

Fractional quantities are intentionally rejected by the production extension.
They remain valid in normal Part-DB workflows and are not modified there.

## PDF order import and attachments

The order page offers a review-first import for digitally generated PDF order
confirmations. It recognizes order/customer/project numbers, order date,
customer reference and line items. Every extracted value remains editable and
must be confirmed by the user. Unknown descriptions stay unmapped; a line may
also deliberately remain a note-only order position.

Reusable, normalized description mappings can target one system template, one
Part-DB build project or one Part-DB part. Imported Part-DB parts always use
`pcs.`; system-template units are fixed on the template. Duplicate order
numbers are rejected by validation and by a database uniqueness constraint.

Security limits are applied before parsing and again before persistence:

- the upload maximum comes from Part-DB's `Maximum file size for attachments`;
- only validated PDFs are accepted for import, with bounded file, stream,
  decompression, text-block and line counts;
- scanned/OCR PDFs and active PDF execution are not supported;
- import drafts use random session tokens and private temporary files that are
  purged after 24 hours;
- a single import is capped at 500 source lines and 2,000 generated positions;
- final persistence runs in one transaction and repeats duplicate/reference
  validation.

Order attachments reuse Part-DB's configured upload size and secure attachment
path resolution. Files receive random storage names outside the public web
root, restrictive permissions, MIME/extension/content checks and safe download
headers. Downloads require the order read permission; upload and deletion
require order edit permission. Current defensive limits are 100 attachments and
250 MiB total stored size per order.

## Permissions and Part-DB integration boundary

Production permissions are managed through Part-DB's normal user/group settings
and presets. Separate operations exist for:

- orders (`read`, `create`, `edit`, `delete`, `import`);
- production projects, customers, templates and import mappings (CRUD);
- devices/assemblies (CRUD, assign, build);
- material (read, reserve, provide, withdraw).

Providing or withdrawing material additionally requires the corresponding
native Part-DB stock permission. Reading orders, devices, templates or import
mappings also grants the native read-only prerequisites for referenced Part-DB
projects and parts; no native edit permission is implied. Existing
administrators receive the production permissions during the schema upgrade;
other users and editor/read-only presets receive no implicit production access.

The extension lives mainly under `src/*/Production`, `templates/production`,
`assets/controllers/production`, `public/js/production`, matching translations,
migrations and tests. Existing Part-DB files are touched only for:

- registering the sidebar tree and its rendering behavior;
- registering the production permission schema and administrator upgrade;
- reproducible container builds (pinned Composer image).

No Part-DB part, lot, project or BOM entity is extended with production-owned
columns. References to deletable Part-DB projects/parts and system templates use
nullable relations plus historical names/IDs where traceability requires it,
so deleting a core object is not blocked solely by the extension.

## Scalable lists and expected volume

Orders, production projects and devices/assemblies use Part-DB's server-side
DataTables integration. Part-DB's configured default page size, page-size menu,
sorting and column controls are reused. Filters and searches are applied in SQL
before pagination:

- orders: active/all, status, customer, order year, order/project/customer
  number and name;
- production projects: active/all, status, customer through orders, creation
  year, number/name/description;
- devices/assemblies: active/all, status, customer through assigned order,
  creation year, serial number, system/build project, location, order and
  position.

Active scopes exclude completed/delivered/cancelled or
completed/installed/scrapped records as appropriate. Composite indexes cover
the common status/date, customer/date and order/status paths. This keeps
responses bounded for the expected ten-year scale (thousands of orders and
tens of thousands of physical instances rather than loading full history into
Twig).

## Data separation and deployment

Application source, migrations and documentation belong in Git. Database
copies, uploaded attachments, exported backups, browser profiles, screenshots,
generated assets and local environment files do not. The repository ignore
rules cover `var`, uploads, database files, Docker data, local backups,
`public/build*` and `.tmp`.

The container boundary follows the same rule. `.dockerignore` explicitly
excludes `db`, `development_backups`, `public_media`, `.tmp` and generated
`public/build.*` trees. Runtime data may be mounted where required, but it must
never be copied into a distributable application image.

The current workstation database is disposable test data. The portable
MariaDB environment and its data boundary are documented in
`docs/development-mariadb.md`. Before productive deployment, migrations must be
verified again against a fresh MariaDB copy of the real Part-DB installation,
followed by backup/restore and an end-to-end order-to-build scenario on the
selected Linux or Synology host and its reverse proxy.

Container builds pin Composer `2.10.2` instead of the moving `latest` tag so an
unrelated upstream image update cannot invalidate routine dependency builds.

## Deferred work

- archive completed projects/orders/devices instead of using deletion for
  normal lifecycle cleanup;
- ordering proposals and return/reversal of allocated project material;
- productive MariaDB migration rehearsal and real-data consistency cleanup;
- complete end-to-end acceptance scenario, backup/restore test and hardened
  Internet-facing deployment validation.
