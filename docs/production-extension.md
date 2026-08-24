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

A build instance is assigned through exactly one project position, and a database uniqueness constraint ensures that a position cannot receive a second device. Every position and build instance has its own internal database ID; the physical build instance additionally carries its globally unique serial number. The customer project stored alongside it is derived from that position and cannot be assigned independently. Removing the position assignment releases both links while preserving the serialized build instance for reassignment. The now-empty project position remains as the project's unfulfilled requirement until it is filled again or explicitly deleted after a cancellation.

## Inventory phases

- Planning and commissioned projects only aggregate requirements and compare them with free Part-DB stock.
- In-production projects allow a builder to select a concrete Part-DB lot and a positive whole-number quantity. Fractional quantities remain available to normal Part-DB workflows but are rejected by the production extension.
- The normal Part-DB withdrawal service reduces free stock and writes its standard stock log.
- A `ProjectMaterialAllocation` records the same quantity as project stock, including source lot, actor and manufacturer serial number where applicable. Serial-tracked parts are allocated one unit at a time, producing one traceable row per serial number. The item still exists and is traceable, but is no longer available to another build.
- Requirements are recalculated from the live Part-DB BOM. Later BOM or configuration changes are shown as missing material or surplus project stock instead of rewriting past stock movements.
- Ordering suggestions and returning allocated material are separate later workflow steps.

## Dependency boundary

The extension lives below additive paths such as `src/Entity/Production`, `src/Services/Production`, `src/Controller/Production`, `src/Form/Production`, `templates/production` and matching migrations/tests. Only the native sidebar tree and tree rendering integration touch existing Part-DB files. Existing project and BOM entities remain unchanged.

References to Part-DB projects and parts use `ON DELETE SET NULL`. Production records keep the referenced name, original ID and reference type as historical snapshots, so deleting a core Part-DB object does not become blocked by the extension. Deleting a system template follows the same rule for already configured positions and serialized devices; only the template definition and its slots are removed.

Permissions deliberately reuse Part-DB project read/edit permissions and the existing `parts_stock.withdraw` permission. This avoids modifying Part-DB's central permission model.
