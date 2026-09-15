# Production navigation and consistent access checks

The approved navigation groups daily work, template maintenance and master
data separately. The same permitted tree entries now determine whether the
sidebar, data-source selector and JSON tree endpoint are available.

## Navigation

```text
Fertigung
├── Aufträge und Projekte
│   ├── Meine Aufträge
│   ├── Aufträge
│   └── Projekte
├── Fertigungsablauf
│   ├── Bauen
│   ├── Geräte und Baugruppen
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

The first two groups start expanded; the last two start collapsed. The current
page can reveal its containing group through the existing tree selection logic.
Group headings only expand/collapse. Leaves retain their existing routes,
including `scope=active` for My orders and `missing=1` for required parts.
The existing device-list label is retained in both languages.

The standard tree stylesheet now allows long labels to wrap within their flex
row while preserving icon and indentation widths. This prevents labels from
being clipped by a narrow sidebar. Search, native tree sources and page
navigation continue using the existing tree component.

## Authorization correction

Previously, a user with only protocol-template or datasheet-template read
permission could see the sidebar but receive HTTP 403 from its tree endpoint.
Serial-number administrators could have a permitted leaf in the builder while
the sidebar and endpoint denied the entire tree.

`ProductionTreeBuilder` remains responsible for individual leaf permissions and
omits empty groups. Its `hasVisibleEntries()` derives availability from the actual
tree instead of maintaining another list of permissions. The new
`ProductionNavigationExtension` exposes that result as the Twig function
`production_navigation_available()`, used by both sidebar templates.
`TreeController::production()` returns the same permitted tree, or HTTP 403 when
it is empty. No permission result is cached across requests/users.

Destination controllers retain their own authorization. In particular:

- Build navigation requires device read and build permissions.
- Serial-number ranges require user- or group-permission administration rights.
- Access to one template module does not grant material or other module access.
- With no permitted leaves, the dedicated sidebar and selector entry are absent
  and the tree endpoint denies access.

## Verification — 2026-09-14

- 22 targeted MariaDB tests / 220 assertions passed: the new navigation suite,
  existing device-access regressions and required-parts controller tests.
- Coverage includes all twelve individual permission combinations, group/order
  structure, query defaults, translated labels, read-plus-build behavior,
  serial-number administrators, no accessible leaves, revoked permissions,
  authorized leaf requests and forbidden unrelated destinations.
- PHPStan level 5 for the changed PHP classes passed; both sidebar Twig files
  and production translation files passed syntax validation.
- Production frontend build succeeded using existing project dependencies. The
  existing public-path warning remains intentional in `webpack.config.mjs`.
- Real Chrome checks against the candidate container image passed for an admin,
  protocol-only user, datasheet-only user, serial-range administrator and user
  without production access. Fresh profiles and synthetic accounts were used.
- Browser checks cover initial group expansion, actual tree clicks, active
  selection on tested destinations, query defaults, search, permitted/forbidden
  requests and no JavaScript errors. Screenshots were inspected at 1440 and
  1024 pixels; long labels fit within their rows after the CSS adjustment.

The tests used an isolated MariaDB 12.3.2 instance. Its database, browser uploads,
media and sessions were temporary test storage. The actual business database
was not used for fixtures or browser accounts. Full PHPUnit regression was not
rerun for this change; the stated count refers to the targeted suites above.

## Local deployment

The change was deployed to the existing LAN stack on 2026-09-14 at 12:37 CEST:

- App image: `localhost/partdb-navigation:2026-09-14`.
- Image ID: `07096a7ba9befe7fc04a0b1d3f21d1d311bed13a3782a9cee1ef3e4ab355bd48`.
- Base image retained: `localhost/partdb-required-parts:2026-09-14`.
- The image overlay contains eight selected application/source files plus
  compiled frontend assets. Composer's authoritative class map was regenerated
  offline so the new Twig extension is available in the runtime container.
- Compose replaced the application and dependent HTTPS proxy. MariaDB's
  container identity and start time are unchanged; all named application,
  database and TLS volumes were preserved. No schema migration was required.
- The proxy retains `init: true`. The live application cache was cleared as
  `www-data`. Changed runtime file hashes and the asset entrypoints match the
  workspace, and both HTTPS login endpoints pass certificate verification.
- Three consecutive post-deployment health-check intervals passed: all services
  remained healthy and the HTTPS proxy had no zombie processes. This short
  deployment check was followed by a successful 61-minute production observation
  at 13:38 CEST; see the [healthcheck report](https-healthcheck-diagnosis-2026-09-14.md).
- The two temporary test containers and their isolated network were removed
  after verification; production containers and volumes were preserved.

The complete pre-deployment backup was inspected: all 1,757 ZIP entries passed
CRC verification and the SQL dump was nonempty with schema statements.
The environment/configuration checkpoint, archive and deployment verification
are retained under Git-ignored
`var/checkpoints/2026-09-14-navigation-20260914T103702Z/` (directory 0700,
sensitive files 0600). Never replace newer business data with this checkpoint
merely to roll back application code.

Reproduction scripts, test logs, frontend build context and browser screenshots
are retained under Git-ignored `var/navigation-check-20260914/`.
No Git commit, push or public internet exposure was performed.
