# Part-DB 2.17 LAN deployment — 2026-09-14

The user-approved custom 2.17 release is live. HTTP writers were stopped at
13:47:10 CEST; all services were healthy and both HTTPS login endpoints were
verified at 13:58:48 CEST. User acceptance of the updated interface remains open.
This deployment does not open the application to the internet.

Later application-only update: the [stale build guard](stale-build-guard-2026-09-14.md)
was deployed at 20:46 CEST. The versions and preservation evidence below describe
the original 2.17 rollout.

## Deployed versions

| Component | Deployed version / identity |
| --- | --- |
| Part-DB | Custom 2.17.0, retaining production features and navigation |
| Application image | `localhost/partdb-upgrade-trixie:2026-09-14` |
| Application image ID | `254ee86e9f3fd15a89c69aa5ba161288cb8d682b207266ae5968717f85ec24f3` |
| MariaDB | 12.3.3 |
| Database image ID | `be61ac2ac5a1cbda9bba998dc066aa3d0410bf2518c393aea528f632d2083bf1` |
| Debian | 13.7, Trixie |
| PHP CLI/FPM | 8.4.25 |
| OpenSSL | `3.5.7-1~deb13u2` |
| Apache | `2.4.68-1~deb13u1` |

The complete isolated acceptance results are in the
[candidate report](upgrade-candidate-2026-09-14.md): 2,482 PHPUnit tests and
7,643 assertions, PHPStan level 5, browser/permission checks, large protocol
forms, PDF rendering, migration rehearsal and full backup/restore checks.
These tests used isolated environments; no fixtures were loaded into live data.

## Consistency and preservation

With application and HTTPS stopped, a full logical backup passed ZIP CRC
verification. MariaDB was then stopped gracefully and all six named volumes
were exported, read through for archive validation and hashed. This includes
the database, private uploads, public media, sessions and both TLS volumes.

Only the three planned local environment values were changed: application
image, MariaDB image and Doctrine server version. The Compose plan was checked
before execution. It recreated the project's containers and networks, retained
all named volumes and preserved port bindings, private-upload enforcement and
HTTPS `init: true`. Automatic migration remained disabled; MCP and OAuth remain
disabled. Existing credentials were retained.

Only `DoctrineMigrations\Version20260827164156` was pending and applied.
Fingerprints of all existing records across 75 tables match, allowing the
expected legacy CLI-log username normalization and new log columns. Exactly
one expected database-update log event was added. Hashes of 1,294 persistent
files, including attachments and sessions, match before and after migration.
The migration status reports up to date. Temporary maintenance containers were
removed before opening HTTP access.

The private checkpoint is
`var/checkpoints/2026-09-14-upgrade-20260914T114642Z/`. It contains backups,
checksums, private environment snapshots, migration output and deployment
events. The directory is protected and Git-ignored. Never commit its contents.

## Live acceptance checks

Final read-only acceptance passed at 14:48 CEST. Application, MariaDB and HTTPS
were healthy; repeated scheduled healthcheck observations showed zero proxy
zombies. HTTPS login returned 200 with certificate verification on localhost
and the LAN address. HTTP returned 308 to the configured LAN HTTPS address.

Anonymous production-dashboard and attachment-list requests redirect to login;
a nonexistent attachment returns 404. Effective session configuration requires
Secure, HttpOnly and SameSite=Lax. The anonymous login GET itself did not set
a session cookie, so cookie protection was verified from effective configuration.
No user credentials or existing sessions were borrowed for these checks.

1,358 live application/source files, dependency locks and asset entrypoints
match the candidate. The media volume overlays the image's `.htaccess`; the
active Apache virtual-host configuration independently blocks PHP-like files
there. That configuration matches the candidate, and a nonexistent PHP probe
in the media directory returns 403. The final report is `live-acceptance.json`
in the private checkpoint. Package versions were read from the live container.

## Working source and recovery

405 changed source paths were synchronized from the tested candidate after
successful deployment, with concurrent-change checks and backups of replaced
files. Newer documentation was retained. Built assets were copied from the
verified candidate, and local Composer/Yarn dependencies were installed using
the matching lockfiles. No Git commit, stage or push was performed.

The source-manifest comparison still passes after local dependency installation.
`git diff --check` reports three formatting findings inherited unchanged from
the tested upstream candidate (two trailing spaces and one final blank line);
the deployment documentation adds no whitespace findings.

The previous application image
`07096a7ba9befe7fc04a0b1d3f21d1d311bed13a3782a9cee1ef3e4ab355bd48`
and database image
`2fabdcd1b066b18b6f7ec91ee4fc3a91227b20c3926a5b2a01dcdcf403c0030e`
are retained. Do not downgrade the migrated MariaDB directory in place.
Restoration requires a matched application/database/files checkpoint and a
plan for preserving business activity entered since the backup.

The HTTPS proxy was recreated during this rollout with the same image and
the verified init correction. The earlier 61-minute observation belongs to
the preceding proxy instance; it is not the new instance's uptime.

NAS/public deployment, user acceptance, administrator 2FA and the remaining
security roadmap items are separate work. This record is not a general
internet-security acceptance.
