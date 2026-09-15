# Part-DB 2.17 maintenance candidate — 2026-09-14

Status: isolated integration and verification complete; this candidate was
deployed to the live LAN stack on 2026-09-14 at 13:58 CEST after user approval.
See the [deployment record](upgrade-deployment-2026-09-14.md) for the fresh
backups, controlled migration and live checks. No Git commit, push or internet
deployment has been performed. The sections below retain the candidate's
pre-deployment verification evidence.

The previously pending HTTPS observation also passed: at 13:38 CEST the live
proxy had run for 61 minutes since its actual last recreation, remained healthy
and had zero zombies. Both HTTPS login endpoints passed certificate verification.

## Source preservation and merge

The current source state was frozen before integration, including uncommitted
production features, migrations, tests and security corrections. The snapshot
contains 4,024 selected source files with SHA-256 hashes. Runtime data,
environment overrides, customer attachments and local credentials were excluded.
The snapshot and candidate have no separate Git repository.

The official [2.17.0 release](https://github.com/Part-DB/Part-DB-server/releases/tag/v2.17.0)
was downloaded from the upstream repository. Its tag resolves to commit
`0f0ee60dd887e141c480f9caa30e0d576ce8c99a`. Three-way comparison against the
2.15 version commit `350f47d5` found 410 upstream-changed paths, 334 locally
changed paths, 19 overlaps and five textual merge conflicts. These counts
include documentation/dependencies and changes already shared with upstream.

| Conflict | Resolution |
| --- | --- |
| `composer.json` / `composer.lock` | Use the coherent upstream 2.17 dependency set, including MCP SDK 0.8.1 without the obsolete alias. |
| `yarn.lock` | Retain the local fast-uri resolution and SortableJS addition while taking upstream frontend additions. |
| Delete-button controller | Preserve plain-text production messages and draft/complete behavior, and include upstream's explicit HTML-title option. |
| Permission-schema subscriber | Retain targeted transactional user/group JSON updates. Do not reintroduce a global ORM flush during request processing. |

The upstream automatic-schema-update log comment is not applied to the custom
subscriber: it assumes an ORM flush, whereas this installation deliberately
persists only permission columns. Assigning its comment to a later unrelated
flush would be misleading. General production-history integration remains a
separate roadmap item.

Reviewed automatic merges preserve private-attachment enforcement/headers,
production permission definitions and presets, Doctrine compatibility and the
navigation availability function. The upstream REST label target-read check is
included. New regression tests explicitly check target denial and preservation
of pending API entities/explicit permission denials during schema upgrades.

## Verification completed

- Host PHP 8.4.25 with isolated MariaDB 12.3.3: **2,482 tests / 7,643
  assertions**, no failures/errors; one skip and nine PHPUnit deprecations.
  The first run lacked fresh synthetic OAuth RSA keys; after generating them
  as prescribed by upstream CI, the complete suite passed. No live key was used.
- PHPStan level 5 over application source: no errors. All 227 Twig files and
  72 YAML files pass syntax validation.
- Locked Composer audit: no advisory matches and no abandoned packages.
- Frozen-lock frontend installation and production build pass. The existing
  build-tool UUID advisory remains moderate, under webpack-notifier/node-notifier;
  it is not represented as an application data-access finding.
- Real Chrome checks pass for admin, protocol-only, datasheet-only, serial-range
  administrator and no-production-access accounts. Grouping, active navigation,
  search, narrow layouts and permission denials work without JavaScript errors.
- An actual HTTP/FPM form with 1,100 decimal answers saves every value, including
  the final tiny decimal and trailing precision. A recognized form truncated
  beyond 8,000 input variables is rejected without changing saved answers.
  Explicit completion, escaped notes and immutability of the completed run pass.

These checks use synthetic fixtures. Browser access is bound to loopback and
test containers use an internal network. MCP editing and OAuth provisioning
remain disabled in the runtime candidate; OAuth is enabled only inside its
  dedicated synthetic regression environment.

The complete suite also passes inside the final Debian Trixie/PHP image against
its own synthetic MariaDB database, with the same 2,482 tests / 7,643 assertions.
Navigation and the large-form browser checks pass against that final image as
well. A focused JavaScript check covers the merged dialog's text/HTML modes,
draft bypass, submitter preservation, single submission and cancellation.

## Migration rehearsal with a protected copy

The CRC-verified pre-navigation backup from 12:37 CEST was restored into a new
isolated MariaDB 12.3.3 database. This is a copy of that checkpoint, not a claim
to include changes entered after the checkpoint. Only the missing upstream
`Version20260827164156` ran, adding three native-log columns and indexes.

All existing records across 75 data tables retain their normalized fingerprints.
The only expected changes are legacy CLI username normalization, migration
metadata, and one new native `database_updated` CLI event. The resulting schema
reports up to date. No application browser or test fixtures were pointed at this
business-data copy. Live data was not modified.

## Runtime maintenance

The first comparison image uses Bookworm with PHP 8.4.25 and
`max_input_vars=8000`. The actual PHP/FPM package is
`8.4.25-1+0~20260828.55+debian12~1.gbp259445`. OpenSSL remains
`3.0.20-1~deb12u2`; the [Debian tracker](https://security-tracker.debian.org/tracker/source-package/openssl)
still lists the six previously documented findings for Bookworm, while listing
their fixes for Trixie. The selected candidate therefore uses Trixie.

| Final candidate component | Verified identity/version |
| --- | --- |
| Application image | `localhost/partdb-upgrade-trixie:2026-09-14` |
| Application image ID | `254ee86e9f3fd15a89c69aa5ba161288cb8d682b207266ae5968717f85ec24f3` |
| Debian | 13 (Trixie), image reports 13.7 |
| PHP CLI/FPM | `8.4.25-1+0~20260828.55+debian13~1.gbp259445` |
| OpenSSL / libssl3t64 | `3.5.7-1~deb13u2` |
| Apache | `2.4.68-1~deb13u1` |
| MariaDB candidate | `docker.io/library/mariadb:12.3.3` |
| MariaDB image ID | `be61ac2ac5a1cbda9bba998dc066aa3d0410bf2518c393aea528f632d2083bf1` |

The final image passes Composer's runtime platform checks. Its OpenSSL package
matches the Trixie version listed as fixed for the previously documented six
findings; this is not a full OS/transitive-dependency vulnerability scan.

The candidate Dockerfile defaults to Trixie, creates empty runtime directories
explicitly, retains protected-media configuration, and enforces the Yarn lock
during image builds. Database/uploads/cache from the working installation are
not build inputs. PHP remains on the 8.4 branch.

The final runtime also renders the synthetic multipage datasheet. A real full
backup with MariaDB SQL and private text/PDF attachments was restored into a
separate empty test database/file directory: all table checksums and restored
attachment hashes match. An intentionally failing dump returns failure, keeps
the preceding valid archive intact and creates no incomplete replacement.
The inspected PDF has ten A4 pages. Runtime hashes for 1,503 application/source
files match the prepared candidate, as do both dependency locks, asset
entrypoints and the protected-media `.htaccess` file.

## Reproduction and remaining acceptance

Source snapshots, merge inventory, protected verification logs, scripts and
candidate build contexts are retained under Git-ignored
`var/upgrade-20260914/` (parent directory 0700). The business-data checkpoint
remains in its existing protected checkpoint directory. Do not add these
directories to Git or use the source-only snapshot as a business-data backup.
The four disposable test containers and their internal network have been
removed, including the temporary business-data copy. The selected image and
source remain available. After deployment, the working application's source
and built assets were synchronized with `var/upgrade-20260914/candidate/`,
preserving newer roadmap/review documents and backing up replaced files.

For future rollouts, take a fresh consistent deployment backup. Preserve
all current named volumes, private-upload enforcement, sessions, HTTPS `init`
and network restrictions. Do not run the native self-updater over this custom
tree or replace it with a stock upstream image.

The prepared deployment changes are limited to these existing environment
settings: `PARTDB_IMAGE=localhost/partdb-upgrade-trixie:2026-09-14`,
`MARIADB_IMAGE=docker.io/library/mariadb:12.3.3`, and
`MARIADB_SERVER_VERSION=12.3.3-MariaDB`. Keep automatic migration disabled and
run the one pending migration explicitly while HTTP writers are stopped. Do
not rewrite secrets or other environment settings. Check the Compose execution
plan before applying it, because the installed provider can also recreate the
dependent HTTPS proxy.

Retain the preceding application image
`07096a7ba9befe7fc04a0b1d3f21d1d311bed13a3782a9cee1ef3e4ab355bd48`
and database image
`2fabdcd1b066b18b6f7ec91ee4fc3a91227b20c3926a5b2a01dcdcf403c0030e`
as references. Do not downgrade a migrated MariaDB data directory in place or
restore an older database over newer business activity merely to roll back
application code. A database rollback needs a matched, validated restoration
plan and must account for all activity since the deployment backup.

Public/NAS deployment, administrator 2FA, malware scanning and the confidential
API-token follow-up remain separate work. Passing the upgrade checks does not
constitute a general internet-security acceptance.
