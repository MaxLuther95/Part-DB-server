# Version and maintenance review — 2026-09-13

## Scope and conclusion

Read-only review of the custom Part-DB 2.15.0 tree, locked dependencies, the
running application/database images, and official upstream release/security
information. No package update, Git fetch/checkout, database migration or
application deployment was performed by this review. The only persistent change
is this report. TLS work and regression tests running alongside this review are
documented separately.

An update path exists, but this installation is not an unmodified upstream
release: its production, protocol, datasheet and authorization extensions must be
retained and tested. The highest-priority maintenance candidates are the PHP
runtime, the native REST label authorization fix, MariaDB's patch release, and
the unresolved OpenSSL package findings below. A successful dependency audit
does not imply that the operating-system/runtime packages or application code
are free of vulnerabilities. This report is not an internet-exposure approval.

## Observed inventory and candidates

Versions were read from `VERSION`, `composer.lock`, `yarn.lock`, the running
containers, and official release metadata. Package revisions matter, especially
for Debian backports.

| Component | Observed | Candidate / assessment |
| --- | --- | --- |
| Part-DB base | 2.15.0 plus custom extensions | 2.17.0, released 2026-09-06 |
| Application-container PHP | 8.4.23 | 8.4.25 within the existing 8.4 branch |
| Host PHP | 8.4.25 | Already at the observed current 8.4 patch level |
| MariaDB container | 12.3.2 | 12.3.3 within the same LTS branch |
| Apache Debian package | 2.4.68-1~deb12u1 | Matches Bookworm tracker version; see below |
| OpenSSL / libssl3 Debian packages | 3.0.20-1~deb12u2 | Matches Bookworm version but has unresolved tracker findings |
| CA certificates | 20250419~deb12u1 | Inventoried; no full trust-store audit performed |
| HTTPS proxy | Caddy 2.11.4, official Alpine image | Latest published Caddy release; configuration-specific advisory assessed below |
| Proxy's compiled Go runtime | go1.26.3 | Inventoried via `caddy build-info`; no complete Go dependency audit performed |
| Podman client | 6.1.0 | Inventoried, not independently security-audited here |
| Host Composer | 2.10.3 | Build Dockerfile separately pins Composer 2.10.2 |
| Symfony framework | 7.4.16 | Upstream 2.17 lock uses 7.4.18 |
| API Platform | 4.3.17 | Upstream lock uses 4.3.18 |
| MCP SDK | 0.7.1 | Upstream lock uses 0.8.1 |
| Symfony AI / MCP bundles | 0.12.0 | Upstream lock uses 0.13.0 |
| Brick Math | 0.17.2 | Upstream lock uses 0.19.1 |
| WebAuthn libraries | 5.3.5 | Upstream lock uses 5.3.8 |
| Doctrine DBAL / ORM | 4.4.4 / 3.6.8 | Same versions in inspected upstream lock |
| Dompdf / PhpSpreadsheet / Twig | 3.1.6 / 5.9.0 / 3.28.0 | Same versions in inspected upstream lock |

The target PHP-package versions above are the coherent dependency set shipped
by Part-DB 2.17.0, not a claim that every package is the newest available version
independently. Sources: [official Part-DB release](https://github.com/Part-DB/Part-DB-server/releases/tag/v2.17.0),
[upstream lockfile](https://github.com/Part-DB/Part-DB-server/blob/v2.17.0/composer.lock),
[PHP downloads](https://www.php.net/downloads.php),
[MariaDB maintenance announcement](https://mariadb.com/resources/blog/mariadb-community-server-q3-2026-maintenance-releases/).

## Part-DB upgrade compatibility

The official comparison contains 104 commits between 2.15.0 and 2.17.0.
The entire recursive 2.17.0 file tree was also checked, without truncation,
against local migration filenames. [Upstream comparison](https://github.com/Part-DB/Part-DB-server/compare/v2.15.0...v2.17.0)

- Exactly one upstream migration is missing locally:
  [Version20260827164156](https://github.com/Part-DB/Part-DB-server/blob/v2.17.0/migrations/Version20260827164156.php).
  It adds `access_method`, `request_id`, and `transaction_id` plus indexes to
  the native `log` table and backfills legacy CLI log entries. No migration-name
  collision was found, but execution/order must be tested on a MariaDB copy
  containing the custom production migrations. This does not itself integrate
  the custom production history with native Part-DB history.
- Native project/BOM editing, deletion/merging, withdrawal dialogs, attachments,
  and several shared entities/services changed. These intersect the custom
  workflows and private-attachment hardening. Do not overwrite custom source
  files or replace the application's image with a stock upstream image.
- Preserve private-upload enforcement, protected attachment responses,
  descendant-aware production authorization, anonymous access restrictions,
  persistent sessions and deployment configuration during integration.
- The container reports `max_input_vars=1000`; upstream raises the image setting
  to 8000 in 2.16. Large template forms need explicit coverage against truncated
  submissions. The higher value must be reviewed alongside request-size and
  execution limits, not treated as an unlimited-resource setting.
- Brick Math changes require exact-decimal, measurement display, material
  quantity and rounding regression tests. Upgrade the MCP/AI dependency family
  coherently; upstream removes the old SDK version-alias workaround.
- Upstream introduces MCP write tools. Keep MCP and its write functionality
  disabled unless explicitly required and separately authorized.
- PHP remains `^8.2`; the frontend build requires Node >=22. No forced migration
  to PHP 8.5 is implied. Frontend additions include CodeJar and Highlight.js for
  the label-template editor. Rebuild and browser-test assets after integration.

Relevant release notes: [2.16.0](https://github.com/Part-DB/Part-DB-server/releases/tag/v2.16.0),
[2.16.1](https://github.com/Part-DB/Part-DB-server/releases/tag/v2.16.1),
[2.17.0](https://github.com/Part-DB/Part-DB-server/releases/tag/v2.17.0).

## Security findings versus upstream versions

The native REST label target-read check is already fixed upstream by
[commit 7cf3e2ca](https://github.com/Part-DB/Part-DB-server/commit/7cf3e2cadb75b307a610424be0162a90012184ac),
between 2.15 and 2.16, but is absent locally. A maintainer report must not present
it as a newly discovered vulnerability of current upstream. The browser label
controller already checks target read permission locally; that does not cover
the separate REST processor.

The separately reviewed API-token/account-settings concern still needs a full
isolated reproduction against current upstream. `UserSettingsController.php`
was byte-identical between the local tree and official 2.17.0; inspected
authenticator/helper changes were stylistic. This is evidence for follow-up,
not a claim of completed current-release verification. Private reproduction
details are intentionally excluded from this document.

The MCP SDK SSE-buffer issue CVE-2026-53965 is already covered locally by SDK
0.7.1; the affected range ends before 0.7.1.
[Maintainer advisory](https://github.com/advisories/GHSA-7m52-jw36-44r3)

Existing PHP/PHAR upload restrictions, server-level execution denial in media
storage and HTML sanitization of native log extras are present in the local
source. Their presence is not a blanket assurance for all upload/logging paths.

## Dependency checks actually performed

- `composer validate --no-check-publish --no-check-all`: valid.
- `composer check-platform-reqs --no-dev`: successful on the host PHP 8.4.25.
  This is not a substitute for the same check in a newly built target image.
- `composer audit --locked --format=json`: no advisory matches among 307 locked
  PHP packages; two abandoned packages, `composer/package-versions-deprecated`
  and `php-http/message-factory`. Both are removed from the 2.17.0 upstream lock.
- Yarn audit against the public npm registry, using the existing Node 22 image
  with a read-only repository mount: 597 dependencies, one moderate advisory in
  `webpack-notifier -> node-notifier -> uuid@8.3.2`. This is a build-tool path,
  not a confirmed Part-DB data-access exploit. Do not force a major UUID override
  without checking consumer compatibility.
  [UUID maintainer advisory](https://github.com/uuidjs/uuid/security/advisories/GHSA-w5hq-g745-h8pq)
- No install/update commands were run. The ephemeral audit container was removed.

## Operating-system and runtime security maintenance

### Caddy: network-facing HTTPS entry point

The added LAN HTTPS proxy uses `docker.io/library/caddy:2.11.4-alpine`, confirmed
at runtime as Caddy 2.11.4 built with Go 1.26.3. Its observed arm64/Linux image ID
is `6b08c1b9858ca9a7d99c1da13c3695081e0e604c6cf214ca26a7ce0e2c4fd9b4`.
This records the local image identity, not an independently verified registry
signature. Caddy/Go terminates client TLS; Apache and OpenSSL in the application
container are not the client-facing TLS implementation. The OpenSSL findings
below remain relevant to other application/library uses and must not be
represented as demonstrated weaknesses in this Caddy TLS connection.

The official release endpoint still identifies 2.11.4 (2026-06-03) as latest.
Its release notes include security corrections for path matching, placeholder
handling, HTML stripping and header normalization, plus client-auth/TLS fixes.
[Caddy 2.11.4 release notes](https://github.com/caddyserver/caddy/releases/tag/v2.11.4)

All 17 published Caddy repository advisories were retrieved for comparison.
One current advisory, [GHSA-6365-7ppr-5r92](https://github.com/caddyserver/caddy/security/advisories/GHSA-6365-7ppr-5r92),
lists versions below 2.11.5 as affected by incorrect connection selection when
`forward_auth` and `reverse_proxy` are combined. The running Caddyfile has no
`forward_auth`; it uses `reverse_proxy partdb:80`, `tls internal`, and `admin off`.
Consequently the documented affected configuration is absent here. Reassess
before adding external authentication or changing the proxy arrangement; do not
claim the binary is free of known advisories merely because it is the latest
published release. No speculative unreleased upgrade was installed.

The other reviewed advisories identify fixes in 2.11.4 or earlier, or explicitly
limit affected versions to older releases; 2.11.4 includes their stated fixes.
[Published Caddy advisories](https://github.com/caddyserver/caddy/security/advisories)
This narrow check did not perform a full vulnerability scan of the compiled Go
runtime, its transitive modules or Alpine packages, and does not replace the
separate TLS/configuration regression checks or authorize internet exposure.

### Apache: reviewed Debian version is current

Installed `apache2` is `2.4.68-1~deb12u1`, matching the Bookworm version reported
by the [Debian source-package tracker](https://security-tracker.debian.org/tracker/source-package/apache2).
Its remaining open entries are in Debian's unimportant category, not an empty
list of all historical concerns. Spot checks confirm this exact revision as
fixed for [CVE-2026-49975](https://security-tracker.debian.org/tracker/CVE-2026-49975),
[CVE-2026-48913](https://security-tracker.debian.org/tracker/CVE-2026-48913), and
[CVE-2026-42536](https://security-tracker.debian.org/tracker/CVE-2026-42536).
No package-version maintenance gap was identified for Apache in this check.
Enabled-module/configuration hardening and denial-of-service limits remain
separate work; the tracker result does not certify the whole web deployment.

### OpenSSL: current Bookworm version still has open findings

Installed `openssl` and `libssl3` are both `3.0.20-1~deb12u2`. The
[Debian tracker](https://security-tracker.debian.org/tracker/source-package/openssl)
reports that same Bookworm version, but explicitly lists these unresolved issues:

| Public advisory | Feature / consequence | Tracker status for installed revision |
| --- | --- | --- |
| [CVE-2026-75803](https://security-tracker.debian.org/tracker/CVE-2026-75803) | Particular empty-message AEAD authentication check | Vulnerable |
| [CVE-2026-63072](https://security-tracker.debian.org/tracker/CVE-2026-63072) | CMS decryption heap write / denial of service | Vulnerable |
| [CVE-2026-54874](https://security-tracker.debian.org/tracker/CVE-2026-54874) | DTLS handshake memory amplification | Vulnerable |
| [CVE-2026-63076](https://security-tracker.debian.org/tracker/CVE-2026-63076) | CMP message verification crash | Vulnerable |
| [CVE-2026-63074](https://security-tracker.debian.org/tracker/CVE-2026-63074) | CMP certificate-cache memory exhaustion | Vulnerable |
| [CVE-2026-42767](https://security-tracker.debian.org/tracker/CVE-2026-42767) | CMP client crash | Vulnerable; no DSA, minor issue |

The tracker also retains an unimportant PowerPC-specific issue; the observed
container is aarch64. The six issues above must not be dismissed merely because
the package is current for Bookworm. Conversely, they are not proof that an
unauthenticated browser can read Part-DB data: most require specialized crypto
APIs/protocols, and their reachability in this deployment was not demonstrated.
A narrow application-source search found no direct use of the listed specialized
APIs, but did not establish absence through every dependency or executable.

The tracker references upstream 3.0.21/3.0.22 fixes and fixed Trixie revisions,
but does not identify a fixed Bookworm revision for these entries at review time.
Before public deployment, recheck distribution maintenance, evaluate an updated
supported base image in isolation if necessary, and document any remaining
applicability decisions. Do not mix Debian release repositories or replace system
crypto libraries ad hoc. Package changelogs were absent from the stripped image;
backport assessment therefore uses installed revision plus Debian's exact-version
tracker entries, not an independently rebuilt package or exploit test.

### PHP: 8.4.23 is behind security and robustness fixes

The running PHP CLI/FPM/GD packages are
`8.4.23-1+0~20260703.52+debian12~1.gbp817a0c`, from the image's Sury package source,
not Debian's native PHP branch. PHP 8.4.24 lists CVE-2026-17544 (BCMath),
CVE-2026-9672 (GD), CVE-2026-17543 (PostgreSQL escaping), and CVE-2026-7260
(Phar recursion). PHP 8.4.25 adds DOM, recursion, session and other memory-safety
fixes without individually labeling those entries as CVEs.
[Official PHP changelog](https://www.php.net/ChangeLog-8.php#8.4.25)

BCMath, PostgreSQL and Phar extensions are loaded, but the application database
is MariaDB; a PostgreSQL escaping bug is not evidence of a MariaDB query bypass.
The BCMath maintainer explicitly marks versions below 8.4.24 as affected, making
the current runtime a priority update candidate. No triggering payload was run.
[BCMath advisory](https://github.com/php/php-src/security/advisories/GHSA-x692-q9x7-8c3f)

GD is dynamically linked to external `libgd3`, whose installed revision is
`2.3.3-14+0~20260731.21+debian12~1.gbp938f32`. Its individual backport status was
not established, so the PHP version alone must not be used to claim that the GD
CVE is exploitable here. Updating/rebuilding the runtime and checking its actual
resulting packages is required; the host already using PHP 8.4.25 does not update
the container.

### MariaDB: apply the same-series maintenance release after testing

The [12.3.3 release notes](https://mariadb.com/docs/release-notes/community-server/12.3/12.3.3)
list six security fixes: CVE-2026-61081, CVE-2026-60585, CVE-2026-60331,
CVE-2026-60747, CVE-2026-47023 and CVE-2026-60184, with published CVSS scores
between 2.7 and 6.6. The installed database reports 12.3.2. Individual attack
preconditions were not reproduced in Part-DB; these are patch-maintenance
findings, not demonstrated browser-access exploits. The database has no published
host port in the inspected deployment.

Use 12.3.3 as the maintenance candidate within the existing LTS branch, with a
verified backup/restore and tests of stock transactions, locking and migration
behavior. MariaDB 12.3 already changed the default of `innodb_snapshot_isolation`
to ON; retain this context when comparing behavior with older test environments.
[12.3 LTS announcement](https://mariadb.org/mariadb-server-12-3-lts-released/)

## Proposed next phase and limits

1. Preserve the complete custom source state and verify database/file recovery.
2. Build an isolated upgrade candidate, with a MariaDB copy and no public access.
3. Integrate upstream 2.17 intentionally, retaining local production and security
   changes; review and execute the missing migration only in that test copy first.
4. Test runtime/database patch updates separately enough to attribute regressions.
   Resolve or explicitly reassess the OpenSSL package findings before internet use.
5. Run full application tests plus real MariaDB workflow tests and browser/PDF
   checks. Cover private attachments, permissions, API tokens, large editors,
   decimal values, serial numbers, material withdrawal and composed datasheets.
6. Record image digests, resulting package revisions, migration status, regression
   results and rollback procedure before asking to deploy the update candidate.

This review did not scan every OS/transitive library, audit the Podman VM/kernel,
test the future NAS, run exploit payloads against runtime packages, or complete
an upstream 2.17 end-to-end reproduction of confidential findings. Advisory
databases change; repeat the checks for the actual image selected for deployment.
