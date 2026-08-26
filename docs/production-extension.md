# Production extension architecture

The production extension stays separate from Part-DB's core project and inventory entities. All owned tables use the `production_` prefix. Core entities only appear as unidirectional targets of extension relations, which keeps later upstream merges manageable.

## Configuration model

```text
Customer (optional) -> Production project
                         |
                         +-> Project position (one ordered system)
                         |     |
                         |     +-> System template -> optional Part-DB base project/BOM
                         |     +-> Fixed slots -> allowed Part-DB projects or parts
                         |     +-> Child positions (self-built content)
                         |     +-> Part requirements (purchased content)
                         |
                         +-> Additional inventory accessories
                         +-> Build instances with serial numbers
                         +-> Project material allocations
                         +-> Non-withdrawing material reservations
                         +-> Assigned Part-DB users ("My projects")
                         +-> Project and position notes
                         +-> Append-only history
```

A system template is an independent configuration definition. It can optionally reference one Part-DB base project whose BOM contains the enclosure and all components that are always needed. A pure grouping system needs no artificial base project. Every slot defines a minimum and maximum quantity and an explicit allow-list:

- Allowed system templates become configurable child positions directly, without being inferred through a Part-DB base project.
- Allowed Part-DB projects become standalone child project positions and later serialized build instances.
- Allowed Part-DB parts remain normal purchased inventory. Slots can require manufacturer serial-number tracking.
- Nested systems do not change Part-DB's project hierarchy.
- Direct and indirect cyclic template nesting is rejected before saving.
- Multiple top-level positions represent multiple separately handled and serialized systems in one order.
- For slots with a maximum quantity of one, the project configuration derives the quantity from the selection: empty is zero and selected is one. Only slots that allow more than one item show a quantity input.
- System templates are identified internally by their database ID and in the interface by their required name; no redundant template code is stored.

When adding a project position, one grouped field selects either a system template or a standalone Part-DB build project. These are mutually exclusive relations. If a system template has an optional base project, that project is used only as its fixed BOM and is not stored as a second position selection.

Project positions can be removed together with their nested configuration while they have no build instances. Positions with serialized build instances, and projects that already hold allocated material, are protected from deletion so production history and stock remain traceable.

A project position has no independent production status. The project view displays the status of each assigned physical build instance, or an unassigned marker while the position is still open.

A build instance is assigned through exactly one project position, and a database uniqueness constraint ensures that a position cannot receive a second device. Every position and build instance has its own internal database ID. A physical build can additionally carry a globally unique serial number; if it does not, a reason in its notes is mandatory and the internal ID is used as its display identifier. The customer project stored alongside it is derived from that position and cannot be assigned independently. Removing the position assignment releases both links while preserving the build instance for reassignment. The now-empty project position remains as the project's unfulfilled requirement until it is filled again or explicitly deleted after a cancellation.

A project can enter `completed` or `delivered` only when every project position has exactly one assigned build instance with a non-empty serial number. This rule is checked by form validation and again on the persistence-layer status transition.

## Build workflow

The build wizard is session-backed, so navigating through its preparation steps does not create incomplete database records. A build originating from a project position uses that position's already approved nested configuration. A standalone system-template build configures every nested system individually, allowing otherwise identical child systems to receive different contents and serial numbers.

1. Configure all template slots. Purchased Part-DB parts become material requirements; nested system templates and Part-DB build projects become separate build instances.
2. Select a production site and enter the optional serial number and notes for every resulting device or assembly.
3. Resolve the live integer BOM against material already held by the production project, then select concrete Part-DB lots at the chosen site or one of its descendants.
4. Review and confirm. Only the final confirmation creates the complete parent/child build hierarchy, consumes project stock, withdraws selected free lots using Part-DB's normal stock service, and writes immutable material-usage rows. These changes are committed in one database transaction.

## Inventory phases

- Planning projects only aggregate requirements and compare them with stock not already reserved for committed projects.
- Commissioned and in-production projects can reserve concrete integer quantities from lots at a selected production site. Reservations do not change Part-DB stock, but every production availability calculation subtracts reservations belonging to other projects.
- A reservation is considered stale when current BOM demand, consumed material, project stock and the reserved quantity no longer agree. The project view keeps this visible until the user reconciles it. If normal Part-DB activity reduces a reserved lot, the project shows a reservation conflict.
- The aggregated "Required parts" view includes only commissioned and in-production projects and derives purchasing demand from committed demand, consumed material, project stock and current physical inventory.
- In-production projects allow a builder to select a concrete Part-DB lot and a positive whole-number quantity. Fractional quantities remain available to normal Part-DB workflows but are rejected by the production extension.
- The normal Part-DB withdrawal service reduces free stock and writes its standard stock log.
- A `ProjectMaterialAllocation` records the same quantity as project stock, including source lot, actor and manufacturer serial number where applicable. Serial-tracked parts are allocated one unit at a time, producing one traceable row per serial number. The item still exists and is traceable, but is no longer available to another build.
- Requirements are recalculated from the live Part-DB BOM. Later BOM or configuration changes are shown as missing material or surplus project stock instead of rewriting past stock movements.
- Ordering suggestions and returning allocated material are separate later workflow steps.
- Providing material or confirming a build consumes the applicable reservation in the same transaction as the real Part-DB withdrawal. Cancelling or otherwise moving a project out of the committed production states releases its remaining reservations and records the event in project history.

## Dependency boundary

The extension lives below additive paths such as `src/Entity/Production`, `src/Services/Production`, `src/Controller/Production`, `src/Form/Production`, `templates/production` and matching migrations/tests. Only the native sidebar tree and tree rendering integration touch existing Part-DB files. Existing project and BOM entities remain unchanged.

References to Part-DB projects and parts use `ON DELETE SET NULL`. Production records keep the referenced name, original ID and reference type as historical snapshots, so deleting a core Part-DB object does not become blocked by the extension. Deleting a system template follows the same rule for already configured positions and serialized devices; only the template definition and its slots are removed.

Permissions deliberately reuse Part-DB project read/edit permissions and the existing `parts_stock.withdraw` permission. This avoids modifying Part-DB's central permission model.
