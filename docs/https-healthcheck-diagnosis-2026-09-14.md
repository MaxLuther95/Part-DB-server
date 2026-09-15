# HTTPS healthcheck process exhaustion — 2026-09-14

Status: cause reproduced, correction tested in isolation and deployed to the
local production proxy on 2026-09-14 at 11:51 CEST. Four normal post-deployment
healthcheck intervals passed without residual zombies. Production observation
beyond the former failure window also passed at 13:38 CEST; see below.

The proxy was subsequently recreated during the approved 2.17 rollout at
13:58 CEST with the same Caddy image and `init: true`. The earlier 61-minute
observation applies to the preceding instance. See the
[deployment record](upgrade-deployment-2026-09-14.md) for the new live checks.

## Cause and live evidence

The HTTPS service runs Caddy as PID 1 without an init process. Its BusyBox
`wget` healthcheck starts an `ssl_client` TLS helper, which exits and remains
as an orphaned zombie owned by PID 1. Caddy does not reap these helpers.
Repeated checks therefore exhaust the container's `pids_limit: 128` budget;
the budget also includes running threads and transient healthcheck processes.
This explains why a new helper can fail to fork while Caddy still serves HTTPS.

Read-only observations of the running proxy:

- `init=false`, PID limit 128, Caddy PID 1.
- At 11:29:30 CEST: 84 `ssl_client` zombies. A subsequent cgroup reading during
  the diagnostic command showed 95 tasks, with no PID-limit event yet.
- At 11:32:09 CEST: 89 `ssl_client` zombies. Growth matches the approximately
  31-second observed healthcheck cadence (30-second configured interval).
- Successful recent healthchecks do not rule out the accumulating defect.

The behavior is consistent with the upstream
[BusyBox report about orphaned HTTPS helpers](https://lists.busybox.net/pipermail/busybox-cvs/2024-March/041777.html).
The controlled local reproduction below is the evidence for this installation;
the older upstream report alone is not proof of affected current versions.

## Controlled reproduction and correction

Both test containers used the running Caddy image ID
`6b08c1b9858ca9a7d99c1da13c3695081e0e604c6cf214ca26a7ce0e2c4fd9b4`
(`caddy:2.11.4-alpine`) and the identical shell healthcheck command.
Podman was 6.1.0 and the Compose provider was podman-compose 1.6.0.

Tests used synthetic local HTTPS responses, no external networking, no host
ports and no production volumes. Temporary certificates and state existed
only on test tmpfs mounts. The read-only root filesystem, capability restrictions,
no-new-privileges, 256 MB memory limit and 128 PID limit were retained.

| Test | Without init | With init |
| --- | --- | --- |
| Initial process tree | Caddy PID 1 | podman-init PID 1, Caddy PID 2 |
| After 40 successful manual checks | 40 zombies | 0 zombies |
| After 80 successful manual checks | 80 zombies | 0 zombies |
| Accelerated check result | Request 117 fails after 116 successes | All 200 succeed |
| Final residual zombies | 116 | 0 |
| Explicit Podman healthcheck | Fails | Succeeds |
| Three further scheduled intervals | Not run after reproduced exhaustion | Healthy, 0 zombies at each observation |
| Synthetic HTTP 503 | Not needed for reproduction | Check correctly exits 1 |

The failing command reported exactly:
`wget: fork: Resource temporarily unavailable`.
Both temporary containers were stopped and removed after the tests.

The only runtime configuration correction is `init: true` on the `https`
Compose service. An init process forwards signals and reaps exited orphaned
children, as documented by
[Podman](https://docs.podman.io/en/latest/markdown/podman-run.1.html#init)
and [Compose](https://docs.docker.com/reference/compose-file/services/#init).
The healthcheck command, TLS setup and resource limits are unchanged.
Compose validation with the local deployment environment passed using
`config --quiet`, without printing resolved secrets.

## Deployment and production verification

The user explicitly approved the HTTPS-only recreation and brief interruption.
The installed provider code and a verbose dry run confirmed that the following
command replaces only the proxy, without pulling a new image:

```sh
podman compose --env-file .env.mariadb.local -f compose.mariadb.yaml \
  up -d --no-deps --force-recreate --pull=never https
```

The command completed successfully at 11:51:18 CEST. Post-deployment checks
confirmed:

- `Init=true`, podman-init as PID 1 and Caddy as its child.
- Unchanged Caddy image, PID/memory limits, read-only root filesystem and host
  port bindings. Both existing named TLS volumes were retained.
- Application and MariaDB container IDs, images and start timestamps unchanged.
  No application restart, image rebuild or database migration was performed.
- Login HTTP 200 over both localhost and the LAN address with successful TLS
  verification against the existing local CA, before and after recreation.
- Plain HTTP login navigation still returns 308 to the canonical HTTPS URL.
- Four observations across normal automatic healthcheck intervals, ending at
  11:53:30 CEST: all three services healthy; zero residual zombies in the proxy.
  The final HTTPS and redirect checks passed again.

Configuration checkpoints, selected container metadata, the private execution
plan/log and verification records are in
`var/https-healthcheck-20260914/deployment-20260914T095115Z/`.
The directory is mode 0700, files are 0600, and Git ignores the directory.
The retained pre-init Compose copy is a configuration recovery reference;
reinstating it would restore the known process leak. No data restoration is
needed for this configuration-only change.

The navigation deployment recreated the proxy at 12:37:17 CEST. During subsequent
upgrade preparation it remained healthy, with zero zombies and no PID-limit
events. The final observation at 13:38:17 CEST measured 3,660.3 seconds of uptime
since that actual recreation. Both localhost and LAN HTTPS login requests
returned 200 with successful certificate verification. The previously pending
one-hour observation is therefore complete; it is not a multi-day availability
test. Selected observations are retained in
`var/upgrade-20260914/https-long-observation.json` and its adjacent log.

The exact old failure time depends on other task/thread usage. A plain restart
cannot change a container's init setting; activating it requires recreation.

The accelerated comparison establishes that init fixes this reproduced leak.
The accelerated test alone is not a long-duration production acceptance test;
the later production observation is recorded separately above. Neither is a
general internet security assessment. Full PHP/database regression was not rerun for this
container process-management change.

The reproducible diagnostic script and structured observations are retained
under Git-ignored `var/https-healthcheck-20260914/`. No database contents,
credentials, existing certificates/private keys or customer documents were
used in the reproduction or added to Git.
