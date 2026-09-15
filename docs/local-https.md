# Local HTTPS deployment

Configured on 2026-09-13 for the existing Podman/MariaDB installation. This is a
LAN deployment, **not an approval for internet exposure**. Account 2FA setup and
the remaining security/update findings are separate work.

## Access

The stable local-hostname setup described at the end of this document supersedes
the original IP-based addresses below. Read the current canonical host from the
private deployment configuration. The IP setup below records the initial LAN
installation.

- On this Mac or another LAN computer: `https://192.168.178.20:8443/`.
- On this Mac only: `https://localhost:8443/`.
- Old HTTP bookmarks on port `8081` redirect GET/HEAD to the canonical HTTPS
  address, preserving the path and query. Other HTTP methods return **400**;
  form submissions must start on HTTPS. A redirect cannot protect credentials
  that a client has already sent over HTTP.
- Use the LAN address or `localhost`, not `https://127.0.0.1:8443`: clients omit
  SNI for IP addresses and the configured default certificate is the LAN IP.
- No router forwarding or public DNS changes were made. Keep the host and
  router firewall restricted to the intended LAN. `0.0.0.0` in the local
  configuration binds the published ports on all host IPv4 interfaces; it is
  not by itself a firewall rule limiting access to a particular subnet.

## Client trust

Caddy issues certificates using a dedicated local CA. Its **public** certificate
is `var/certificates/PartDB-Lokal-Stammzertifikat.crt`, with SHA-256 fingerprint:

```text
28:9A:F0:97:86:B2:15:62:6D:01:DB:FB:34:5B:AE:35:2F:A1:15:FE:8F:4A:12:C8:20:B1:D6:46:2F:22:59:3F
```

The user explicitly approved installation. It was added to this user's macOS
login keychain as a trusted root for SSL/TLS. macOS system curl then verified
the LAN certificate successfully **without** an explicit CA file or disabling
verification. Other browsers with independent certificate stores may require
their own trust configuration.

Each other LAN computer needs to trust the same public root certificate after
its fingerprint has been checked over a trusted channel. Transfer only the
`.crt` above, never the Caddy data volume or the checkpoint archive. Do not use
browser certificate-warning bypasses as the normal setup.

The CA private keys stay in the persistent `partdb-mariadb_partdb_tls_data`
volume. Trusting this root authorizes certificates signed by its private key;
therefore that volume and its backups must be kept confidential. Remove the
local root trust when this development setup is retired.

## Deployment structure

`compose.mariadb.yaml` remains the startup entry point:

```sh
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml up -d
```

- Caddy `2.11.4-alpine` terminates TLS on host port `8443`; port `8081` serves
  redirects/rejections only. Apache no longer has a host-published HTTP port.
- MariaDB remains on the internal database network without a published port.
- Caddy reaches Apache over the existing private container frontend network.
  Its exact fixed IP (`10.89.1.254`) is the only trusted proxy. Clients and whole
  LAN/private address ranges are not trusted as proxies.
- Forwarded For/Host/Proto are set by the proxy; client-supplied Forwarded,
  X-Forwarded-Port and X-Forwarded-Prefix are removed. Unknown HTTPS hosts are
  rejected with **421**, not forwarded to Part-DB.
- `.docker/https/framework.yaml` is mounted read-only into the application's
  `docker` configuration. Session, remember-me and 2FA trusted-device cookies
  are configured HTTPS-only. This does not enable or change any account's 2FA.
- The existing private attachment policy, file CSP and authentication checks
  remain in place. No schema or business-data migration was performed.
- Caddy runs with a read-only root filesystem, limited capabilities and resource
  limits; only its state volumes and temporary directory are writable. Its admin
  API is disabled, so apply a validated Caddyfile by restarting the proxy.
- HTTP/1.1 and HTTP/2 are enabled. No HTTP/3 UDP port is published. HSTS preload
  and includeSubDomains were deliberately not configured for this temporary IP
  setup.

The ignored `.env.mariadb.local` defines `PARTDB_HTTPS_HOST`,
`PARTDB_HTTPS_PORT`, `PARTDB_PROXY_IP`, and `PARTDB_FRONTEND_SUBNET`. Preserve its
DB credentials and other existing settings. Keep the LAN IP stable through a
DHCP reservation. If the host IP changes, update the canonical HTTPS host and
trusted-host allowlist, then recreate the app/proxy and verify the new
certificate. A certificate for the new address does not require a new CA trust
installation while the same CA data volume is retained.

## Checkpoints and cleanup

The protected, Git-ignored `var/checkpoints/2026-09-13-local-https/` contains the
previous Compose file/environment, SQL dump, session archive and new TLS state
archive. The database/session checkpoint was taken with the web app stopped.
The TLS archive contains private keys and must **not** be shared. Existing
uploads/public-media/database/session volumes were preserved. The earlier
full file backup remains in `var/checkpoints/2026-09-13-private-access/`.

Recovery should restore only the configuration/state actually affected by a
failure. Do not blindly restore an old SQL dump over newer work or delete
volumes. Re-enabling host HTTP application access would undo the TLS transport
protection and requires a separate decision.

The explicitly unneeded second container, `funny_shirley` (previously port
`9000`), was removed. After verifying that no remaining container referenced
them, these two volumes were also removed with `podman volume rm` without force,
as separately approved by the user:

- `1ad4b302dc9f510bc3868b170e3915ba73e0c55386e13cc3614b2b8ea122b045`
- `cb8994d4c8b36917b35b563f6449cdfa70d1ce4105463e3576de13912fdbb14c`

There is no separate recovery backup for that discarded instance. These are
not the main installation's named volumes; the main MariaDB data was untouched.

## Verification and limits

- Caddy validation and deployment-environment Symfony container lint passed.
- LAN and localhost login pages returned **200** with certificate verification.
- System macOS trust validated the LAN address without `--cacert` or `-k`.
- HTTP GET/HEAD redirect to HTTPS; a plaintext POST is rejected. A spoofed Host
  cannot alter the canonical redirect. Forwarded-header spoofing did not change
  the application's HTTPS authentication redirect.
- Anonymous production and attachment requests redirect to login. A session
  cookie issued by a protected-page request has Secure, HttpOnly and SameSite=Lax.
- Live MariaDB mapping/schema validation passed; existing migrations are all
  applied. No pending local migration was executed.
- Full current-source regression: **2,118 tests, 6,052 assertions**, no failures
  or errors, one skip; PHPStan clean; 221 Twig and 72 application YAML files valid.
  PHPUnit uses an isolated SQLite test database, not the live MariaDB. Deployment
  configuration is also checked separately in the actual container environment.
- This is not an authenticated manual browser acceptance test: no real user
  password or existing session was borrowed. Login/save/download through the
  browser should still be checked by the user. The regression suite tests those
  application paths with synthetic users/data.

When moving to the NAS, replace the local endpoint with the intended DNS name,
NAS reverse proxy and publicly trusted certificate; update the canonical URL,
exact proxy trust and allowed hosts accordingly. Complete the remaining security
findings and verify backups/restore before enabling internet access.

## Healthcheck process exhaustion — corrected 2026-09-14

The configured BusyBox `wget` HTTPS healthcheck leaves exited `ssl_client`
helpers behind when Caddy runs as PID 1. These accumulate until the container
cannot fork another helper. An isolated reproduction with the exact running
image failed on request 117 with 116 zombies and the original 128 PID limit.

The Compose configuration now sets `init: true` for the HTTPS service. With
the same image and limits, the comparison completed 200 requests plus normal
scheduled healthchecks without residual zombies. A synthetic HTTP 503 still
failed the check. See the [diagnosis report](https-healthcheck-diagnosis-2026-09-14.md)
for evidence, isolation and deployment checks.

**Deployed at 11:51 CEST:** the approved recreation replaced only the HTTPS
container and retained its image, limits and TLS volumes. Application and
MariaDB container identities/start times are unchanged. Both login endpoints
passed certificate verification, and HTTP navigation still redirects to HTTPS.
Four normal healthcheck intervals passed with all services healthy and zero
residual zombies. The later observation at 13:38 CEST also passed after 61 minutes
since the proxy's actual 12:37 recreation, with zero zombies and both HTTPS
login endpoints returning 200 with certificate verification. A plain restart cannot add init to an existing
container; changing this setting requires recreation.

## References

- [Caddy local HTTPS and CA trust](https://caddyserver.com/docs/automatic-https)
- [Caddy SNI/global options](https://caddyserver.com/docs/caddyfile/options#default-sni)
- [Caddy forwarded headers](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy#headers)
- [Symfony trusted proxies](https://symfony.com/doc/current/deployment/proxies.html)

## Stable local hostname — 2026-09-15

The local deployment now supports the Mac's existing Bonjour/mDNS hostname as
its canonical HTTPS host. Its actual value is private deployment configuration
(`PARTDB_HTTPS_HOST` in the ignored `.env.mariadb.local`). The URL keeps port 8443.
No operating-system hostname or router/DNS settings were changed.

`PARTDB_HTTPS_EXTRA_SITES` optionally adds explicit Caddy HTTPS site addresses,
currently the temporary hotspot IP. `PARTDB_HTTPS_DEFAULT_SNI` selects the IP
certificate for clients that omit TLS SNI; by default it follows the main host.
The hostname itself uses normal SNI and its own automatically renewed certificate
from the existing local CA. Unknown HTTP Host values are still rejected with 421.
The application's trusted-host allowlist includes the exact local hostname.

Clients need to be on the same reachable network, resolve `.local` names through
mDNS, and trust the existing public local CA certificate. The server-side checks
cannot establish whether every other client's network supports multicast or
whether its trust store is configured. The hostname follows the Mac's address
when it changes networks; direct IP bookmarks and the optional IP certificate
selection remain address-specific. Use the hostname when returning to the LAN.

Protected checkpoints under `var/checkpoints/2026-09-15-local-name-*` preserve the
previous environment, Compose file and Caddyfile. No database, uploads, sessions
or CA volumes were replaced. This remains a local-network deployment.
