# Part-DB with MariaDB on Windows, Apple Silicon and Linux

`compose.mariadb.yaml` is the portable MariaDB runtime for this branch. It is
intended for the current Windows workstation, an Apple Silicon development
machine and a later Linux host. Productive data is never part of the source
repository or an application image.

The default image is pinned to MariaDB 11.4.10. Before importing the real
database, compare that setting with `SELECT VERSION();` on the source system.
Keep the same major/minor for the first rehearsal unless a separately tested
upgrade is intended.

## Data and security boundary

- `.env.mariadb.local` contains local secrets and is ignored by Git and Docker.
- MariaDB has no published host port and is reachable only from Part-DB's
  internal container network.
- Part-DB listens on `127.0.0.1:8081` by default. LAN access requires an
  explicit bind-address and trusted-host change.
- MariaDB data, Part-DB uploads and public media use separate persistent named
  volumes. `compose down` preserves them; `compose down --volumes` deletes
  them and must never be used on data that has not been backed up.
- Automatic migrations are disabled by default. Schema changes remain an
  explicit, observable operation.

Named volumes are appropriate for the portable development setup. A later
Synology deployment can add a host-specific override with backed-up bind
mounts without changing this base file.

## One-time setup

Install Git and the recommended Podman Desktop `.dmg` on macOS. Let Podman
Desktop create its Linux machine and install the Compose provider. The
published `current` image contains both AMD64 and ARM64 variants, so Podman
selects the native Apple Silicon variant on an M1 automatically.

Create the untracked settings file:

```shell
cp .env.mariadb.example .env.mariadb.local
```

On Windows PowerShell use:

```powershell
Copy-Item .env.mariadb.example .env.mariadb.local
```

Replace all three `REPLACE_...` values with independent URL-safe random hex
strings. A password manager or `openssl rand -hex 32` can generate them.

Validate the fully expanded configuration before starting anything:

```shell
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml config
```

## Create a fresh database

Pull the current application and database images, then start MariaDB first:

```shell
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml pull
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml up -d database
```

Run all migrations once with the pulled Part-DB image:

```shell
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml run --rm --user www-data --entrypoint php partdb bin/console doctrine:migrations:migrate --no-interaction
```

Then start Part-DB:

```shell
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml up -d partdb
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml ps
```

With the example settings, Part-DB is available at
`http://127.0.0.1:8081`. Logs can be inspected without exposing credentials:

```shell
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml logs --tail 100 partdb database
```

Stop the services while retaining all data:

```shell
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml down
```

For deliberate local image development, add the build override to the
commands and build before starting the services:

```shell
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml -f compose.mariadb.build.yaml build partdb
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml -f compose.mariadb.build.yaml up -d
```

## Rehearse the real MariaDB migration

Do this only with a fresh backup copy, never against the only productive
database:

1. Record `SELECT VERSION();`, Part-DB's source commit/version and row counts.
2. Create and verify a consistent MariaDB dump plus a separate archive of
   `uploads` and `public/media`.
3. Store the dump outside the repository and transfer it encrypted.
4. Initialize an empty target MariaDB with a compatible pinned version.
5. Restore the original Part-DB dump before running this branch's migrations.
6. Run `doctrine:migrations:migrate --no-interaction` from the new Part-DB
   image; this adds the production-extension schema to the restored core data.
7. Restore uploads/media, clear the cache and validate users, part counts,
   stock totals, projects, BOMs and attachments.
8. Exercise one complete order, reservation and build flow on the copy.
9. Keep the original system unchanged until backup restoration and acceptance
   checks have both succeeded.

The exact dump/restore commands and target MariaDB tag will be fixed after the
source server version is known. A Synology target also needs a separate review
of CPU architecture, Container Manager/Compose support, volume backup and
reverse-proxy/TLS configuration.

## Verified development baseline (2026-09-01)

The configuration was exercised locally with the official
`mariadb:11.4.10` image and synthetic credentials:

- Compose expansion and its service, image and volume boundaries validated;
- MariaDB initialized without a published database port and became healthy;
- all 70 migrations completed through `Version20260901200000`;
- 20 `production_*` tables were created;
- the Part-DB container became healthy and answered on
  `http://127.0.0.1:8081`;
- the same migration chain completed on a disposable SQLite database, so the
  index normalization remains backward compatible.

Doctrine's MariaDB schema comparison now reports only two existing Part-DB
core representation differences (`bulk_info_provider_import_jobs` JSON column
types and `log.level`). No `production_*` table or index remains in the diff.
These core differences were deliberately not changed by the extension.
