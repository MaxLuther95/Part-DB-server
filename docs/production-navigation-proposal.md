# Production navigation proposal

Status: approved and implemented on 2026-09-14. This document retains the
proposal and source findings; see [implementation and verification](production-navigation.md)
for the resulting behavior and local deployment.

## Proposed German tree

```text
Fertigung
├── Aufträge und Projekte
│   ├── Meine Aufträge
│   ├── Aufträge
│   └── Projekte
├── Fertigungsablauf
│   ├── Bauen
│   ├── Geräte / Baugruppen
│   └── Benötigte Teile
├── Vorlagen
│   ├── Systemvorlagen
│   ├── Laufzettelvorlagen
│   └── Datenblattvorlagen
└── Stammdaten
    ├── Kunden
    ├── Seriennummernkreise
    └── Importzuordnungen
```

The existing sidebar source title remains `Fertigung`. The proposed child
group `Fertigungsablauf` avoids repeating that title for a nested group.
The first two groups support everyday work; the last two group definition
and master-data maintenance. Existing Part-DB build-project navigation remains
separate from production projects.

`Meine Aufträge` moves to the first position as the personal starting point.
`Aufträge` retains the complete order overview. The shortcut previously removed
from the My orders page is not reintroduced on that page.

`Bauen` becomes a sibling of the device/assembly list. The device list no longer
acts both as a destination and the parent of an unrelated navigation action.
`Benötigte Teile` moves out of the mixed administration group into the production
workflow. Templates get their own group. Customer and import/number-range
administration form the master-data group.

## Interaction and labels

- Group headings expand/collapse; leaf items navigate to existing pages.
- Start with `Aufträge und Projekte` and `Fertigungsablauf` expanded when they
  contain visible entries. Start with `Vorlagen` and `Stammdaten` collapsed.
- Retain the existing tree search, expand/collapse controls and active-page
  selection behavior. Verify selection for links carrying query parameters.
- Omit unauthorized items and groups left empty by permission filtering.
- Keep one tree entry per destination. Put actual protocol runs and released
  datasheets on the relevant device/assembly, as in the existing workflows.
- Use translation keys for every label, including the currently hard-coded
  German serial-number-range label. No new icon/font dependencies are needed.

| German label | English label |
| --- | --- |
| Fertigung | Manufacturing |
| Aufträge und Projekte | Orders & projects |
| Meine Aufträge | My orders |
| Aufträge | Orders |
| Projekte | Projects |
| Fertigungsablauf | Production workflow |
| Bauen | Build |
| Geräte / Baugruppen | Devices / assemblies |
| Benötigte Teile | Required parts |
| Vorlagen | Templates |
| Systemvorlagen | System templates |
| Laufzettelvorlagen | Protocol templates |
| Datenblattvorlagen | Datasheet templates |
| Stammdaten | Master data |
| Kunden | Customers |
| Seriennummernkreise | Serial number ranges |
| Importzuordnungen | Import mappings |

## Existing destinations and navigation permissions

The proposal changes grouping and order, not business data or controller
authorization. The conditions below retain current leaf visibility.

| Entry | Existing route | Required permission(s) |
| --- | --- | --- |
| My orders | `production_customer_project_mine`, `scope=active` | `@production_orders.read` |
| Orders | `production_customer_project_index` | `@production_orders.read` |
| Projects | `production_project_index` | `@production_projects.read` |
| Build | `production_build` | `@production_build_instances.read` and `@production_build_instances.build` |
| Devices / assemblies | `production_build_instance_index` | `@production_build_instances.read` |
| Required parts | `production_required_parts`, `missing=1` | `@production_material.read` |
| System templates | `production_template_index` | `@production_system_templates.read` |
| Protocol templates | `production_protocol_template_index` | `@production_protocol_templates.read` |
| Datasheet templates | `production_datasheet_template_index` | `@production_datasheet_templates.read` |
| Customers | `production_customer_index` | `@production_customers.read` |
| Serial number ranges | `production_serial_range_index` | `@users.edit_permissions` or `@groups.edit_permissions` |
| Import mappings | `production_order_import_mapping_index` | `@production_import_mappings.read` |

## Source finding to address with implementation

Visibility rules are currently duplicated and inconsistent:

- `templates/_sidebar.html.twig` and `templates/components/tree_macros.html.twig`
  allow production sidebar access for protocol-template-only and
  datasheet-template-only read permissions.
- `TreeController::production()` omits both permissions from its access list.
  An account with only one of those production read permissions can therefore
  see the sidebar selector but receive HTTP 403 when the tree loads.
- `ProductionTreeBuilder` exposes serial-number ranges for administrators, but
  the sidebar/selector/tree access lists omit those administrator conditions.

These are code-inspection findings, not newly executed browser regressions.
Implementation should derive sidebar/selector/tree access from one consistent
visibility rule covering all actual leaves. Keep leaf permissions and the
destination controllers' own authorization intact. No general production
access grant should replace per-feature checks.

## Implementation and acceptance scope

Expected changes: `ProductionTreeBuilder`, production translations, and the
shared visibility used by `TreeController` and the two sidebar templates.
No schema migration, stored template edit, order mutation, dependency upgrade
or change to other Part-DB tree sources is needed.

Acceptance checks should cover:

1. Full-access tree grouping, ordering, existing route targets and query defaults.
2. Accounts with only order, material, protocol-template, datasheet-template or
   serial-range administrator access; empty groups must disappear and accessible
   trees must load successfully.
3. Denial when no production destination is available; direct destination
   authorization must remain effective.
4. Real browser navigation, search, expansion and active selection at desktop
   and narrow widths, including translated labels and links with query strings.

Use isolated synthetic users/data for integration/browser checks. The completed
implementation and actual checks are recorded in the linked implementation report.
