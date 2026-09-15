# Pre-internet security review — 2026-09-13

> Follow-up: S1 and S2 were remediated and deployed on 2026-09-13 with the
> user's approval. See [implementation and verification](security-remediation-2026-09-13.md).
> The observations below describe the initial, pre-remediation review. The
> remaining findings still apply; this is not an internet-release approval.

## Decision

**Not ready for direct internet exposure.** Resolve the authorization inconsistency
and explicitly decide which data may be public before deployment. Complete TLS,
administrator authentication and availability hardening before opening access.

This is a source/configuration review with targeted security tests, not a claim
that the entire application has been proven secure. No evidence of an actual
compromise was sought or established. No application fixes, production database
writes, password changes, container replacements or public exposure were performed.

## Scope and evidence

- Working tree based on commit `0748b53d`, including existing uncommitted changes.
- Manual review concentrated on production controllers, protocol/template
  import/export, file storage, PDF rendering and source resolution; also core
  authentication/permissions, attachment handling, OAuth/MCP gates, Apache,
  Symfony security configuration and the MariaDB compose deployment.
- Live Podman inspection and read-only SQL/ORM inspection using an explicit
  `START TRANSACTION READ ONLY`, followed by rollback. Only aggregate counts and
  non-secret security settings were reported; no customer document contents,
  passwords, tokens or keys were included.
- 218 existing selected tests passed, with 1,063 assertions: `tests/Security`,
  `tests/Controller/AuthorizationTest.php`, production controller tests,
  attachment service tests and production service tests. This was **not** a full
  project test run.
- Three additional diagnostic tests passed with 17 assertions. Two deliberately
  assert the currently inconsistent access behavior, so their success confirms
  a problem rather than certifying security. They use guarded SQLite test data
  and explicit rollback; they do not write to the live MariaDB.
- The third diagnostic test checks native XHTML upload handling in temporary
  storage. A separate Apache container and a clean Chrome profile check the
  effective browser protection. No malicious file was uploaded to the live app.
- SHA-256 comparisons confirmed that the reviewed protocol controller,
  datasheet controller and public `.htaccess` match their live counterparts.
- Composer advisory lookup completed successfully. Yarn's default registry
  timed out; the retry against the npm registry completed successfully.

## Findings

### S1 — High: related production pages bypass a read restriction enforced on the device page

**Confirmed by HTTP controller tests. Requires a logged-in account with the
corresponding production permissions, but without native `projects.read`.**

`ProductionController::buildInstanceShow()` checks both
`@production_build_instances.read` and `read` for each associated native build
project (`src/Controller/Production/ProductionController.php:993`).

Equivalent project checks are absent from:

- `ProtocolController::runShow()` and `runEdit()`
  (`src/Controller/Production/ProtocolController.php:360`, `:373`).
- `DatasheetTemplateController::prepare()`
  (`src/Controller/Production/DatasheetTemplateController.php:249`).
- The device edit entry point
  (`src/Controller/Production/ProductionController.php:1008`).

Reproduction with synthetic data and an explicit `projects.read = false`:

| Request | Observed result |
| --- | --- |
| Device detail | 403 |
| Its protocol detail | 200, including the synthetic confidential note |
| Its protocol edit form | 200 |
| Its device edit form | 200 |
| Its datasheet preparation page | 200, including the synthetic device note |

The test establishes unauthorized disclosure relative to the device page's
policy. It does not establish an anonymous bypass or a successful hostile POST.
Similar missing checks in attachment/download and source-resolution paths need
to be covered during remediation rather than assumed safe.

**Recommendation:** define one shared authorization policy for a build instance
and apply it to all routes that access it or its associated data. Include child
instances, selected protocol runs, attachment downloads and generated PDFs.
Do not rely on hidden navigation buttons or knowledge of an ID. Add negative
tests for each route and explicitly test permitted/forbidden child sources.
This follows [OWASP's per-request authorization guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).

Native project permissions here are module-level permissions; this finding is
**not** proof that customer-specific tenant isolation already exists. The
production module is not a ready-made customer portal. If customers will log in,
an explicit ownership/tenant access model must be agreed and tested first.

### S2 — High for a private installation: anonymous part access and public attachment storage

**Confirmed in the current runtime. These are independent exposure paths.**

- The anonymous user's effective `parts.read` permission is enabled.
- The checked production read permissions and native `projects.read` are denied
  for the anonymous user.
- 366 native attachment records reference the public media area. A sampled PDF
  returned HTTP 200 with `application/pdf` without authentication.
- The remaining 657 attachment records fall outside that public-media category;
  this aggregate does not mean they are all locally stored or protected files.

Public attachment URLs are served directly by Apache. Disabling anonymous part
viewing alone does **not** protect those files. Random filenames are not access
control; a known/shared URL still works. These are existing PartDB capabilities,
not automatically a software defect. The security issue is exposing them without
an explicit publication decision.

**Recommendation:** decide whether any catalog data should be public. For a
private installation, disable anonymous business-data permissions and classify
existing attachments. Move private documents through a controlled migration to
protected storage, retain references and backups, and verify that old URLs no
longer work. Do not blindly delete or move all images: some may intentionally be
public. Default new confidential uploads to protected storage. See
[OWASP file-storage guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).

No claim is made that all 366 files contain confidential information; their
contents were not inspected for classification.

### S3 — Internet-release blocker: current endpoint is HTTP, not a hardened HTTPS deployment

**Confirmed deployment configuration, not a claim of current public exposure.**

- App port: `0.0.0.0:8081 -> 80/tcp`.
- Login is served successfully over ordinary HTTP.
- The current compose file has no TLS endpoint or reverse proxy.
- Session cookie security is `auto`, so it depends on correctly recognized HTTPS
  (`config/packages/framework.yaml:25`).
- Trusted hosts are explicitly restricted; this is positive but does not encrypt
  traffic or replace a firewall.

**Recommendation:** use an HTTPS reverse proxy with a valid certificate; keep
the backend inaccessible directly from the internet; configure trusted proxy
addresses and forwarded headers narrowly; test secure cookies, redirects and
mixed content through the final hostname. Add HSTS only after HTTPS works
reliably. Do not expose MariaDB. A router/firewall or external IPv6 exposure
assessment was not performed. See [OWASP TLS guidance](https://cheatsheetseries.owasp.org/cheatsheets/Transport_Layer_Security_Cheat_Sheet.html).

### S4 — Medium: privileged account has no configured second factor

**Confirmed aggregate runtime inspection:** one enabled account with user/group
permission-management authority; that account has neither TOTP nor WebAuthn
configured and is not identified as a SAML account.

The application supports 2FA and login throttling is configured at five attempts
per minute. Those are useful defenses, but throttling does not protect against
an already stolen password.

**Recommendation:** require 2FA for administrative access before public release,
use individual accounts, retain recovery codes securely, and rotate any
credentials reused from test/transfer environments. No passwords were tested,
reset, or disclosed during this review.

### S5 — Medium hardening gap: availability and container boundaries

**Configuration findings; no denial-of-service attack was attempted.**

- No explicit memory limit was reported for either container; inspected PID
  limits were zero. The Podman VM's own resource boundary is a separate limit.
- Both root filesystems are writable. The app container starts as root, while
  Apache/PHP worker privilege separation must not be confused with that initial
  container identity. Host-root compromise is not implied by this finding.
- Production PDF rendering is synchronous. Application input bounds exist, but
  the reviewed production routes do not have dedicated rate/concurrency limits.
- The Docker PHP configuration accepts uploads up to 256 MB and request bodies
  up to 300 MB; individual application validators impose additional limits.

**Recommendation:** introduce tested memory/PID/concurrency limits and appropriate
request limits at the proxy; budget uploads and generated PDFs across the whole
instance; monitor storage; consider a bounded worker for expensive rendering.
Evaluate least-privilege and read-only filesystem settings in a staging build,
allowing the required cache/session/upload paths. Do not apply generic container
restrictions blindly to the existing Apache/FPM startup.

### S6 — Low immediate runtime relevance: dependency maintenance

- `composer audit --locked --format=json`: no advisories. Two abandoned packages
  are reported: `composer/package-versions-deprecated` and
  `php-http/message-factory`. Abandonment is not itself a proven vulnerability.
- Yarn/npm audit: one moderate advisory, `GHSA-w5hq-g745-h8pq`, affecting
  `uuid@8.3.2` through `webpack-notifier > node-notifier > uuid`.
- The reported path is a build-tool dependency, not demonstrated to be a
  remotely reachable application path. No production exploit was established.

The upstream advisory concerns missing output-buffer bounds checks in certain
UUID APIs; it does not mean all generated UUIDs or our PHP serial numbers are
unsafe. Update the compatible dependency chain and rerun the build/audit rather
than forcing a cross-major UUID replacement. [Upstream advisory](https://github.com/uuidjs/uuid/security/advisories/GHSA-w5hq-g745-h8pq).

### S7 — Low: CSP reporting still uses a placeholder host

The live login response advertises
`report-uri https://partdb.changeme.invalid//csp/report`. This makes that reporting
destination unusable. It does not disable CSP enforcement itself.

**Recommendation:** correct the canonical deployment URL/report endpoint and
verify that meaningful security events reach monitored logs before launch.

## Confirmed protections and a rejected false positive

- Production template mutations are guarded by explicit permissions and
  administrator checks; reviewed mutation paths use CSRF-protected forms/tokens.
- Protocol import has a 2 MiB limit, bounded JSON depth/collections, schema
  validation, session ownership, expiration and preview confirmation.
- Production file uploads have extension/MIME checks, randomized names and
  protected storage/path checks; download responses use defensive headers.
- Datasheet rendering uses fixed Twig templates with escaping. Remote fetching
  and PHP evaluation are disabled in Dompdf; the logo is a fixed local asset.
- App debug mode is off, external attachment downloading is disabled, MariaDB
  has no host-published port, and OAuth server/DCR environment flags are off.
- The native attachment handler accepts XHTML under an unrestricted attachment
  type and retains its script text. **However**, Apache's inherited public
  `.htaccess` sends `script-src 'none'; sandbox` for static content. The isolated
  XHTML response had that header, and Chrome's rendered root element did not
  acquire the harmless script's execution marker. This is **not a confirmed
  stored-XSS vulnerability in the present deployment**. Preserve and test that
  webserver protection when changing the hosting stack; the application-layer
  blacklist alone is not sufficient.

## Recommended order

1. Correct S1 with a shared, explicit policy and regression tests.
2. Agree the publication policy and resolve S2 without losing attachments.
3. Prepare HTTPS, firewall/proxy boundaries and administrator 2FA (S3/S4).
4. Add availability limits, monitoring and dependency maintenance (S5–S7).
5. Test the final deployment, restore a backup in isolation, then commission or
   perform a further external review before enabling internet access.

## Limitations and remaining release checks

No exhaustive audit of every route, API serializer, SAML provider, third-party
integration or Git-history secret was performed. No OS-package/container CVE
scanner, external network scan, load test, password attack or live exploit was
run. Review inherited accounts, active API tokens, CI/secrets, update policy,
backup encryption/recovery, session persistence and the final proxy deployment
as separate release gates. Current PHP/Yarn advisory results are a dated
snapshot, not a continuing guarantee.

Diagnostic scripts/logs were kept in `/private/tmp` under names beginning with
`partdb-security`, plus `ProductionSecurityAuditTest.php` and
`partdb-runtime-security-audit.php`; these temporary artifacts are not durable
regression tests. The actionable evidence and reproduction conditions are
recorded above. Existing application source changes were left untouched.
