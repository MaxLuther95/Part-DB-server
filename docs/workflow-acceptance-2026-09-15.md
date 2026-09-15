# Connected production workflow acceptance

The complete workflow was exercised in Chrome against the actual candidate
image, with an isolated MariaDB database and synthetic fixtures. Only master
data was seeded; the order, position, material operations, build, protocol runs,
released PDFs and attachment were created through the application interface.

## Finding and correction

Opening material provision for an eligible order returned HTTP 500 before any
stock mutation. The quantity field used Symfony `IntegerType` with the
unsupported `html5` option. Removing this option restores the form;
`IntegerType` already renders a numeric input. Existing minimum, maximum, step,
server-side quantity validation and CSRF protection are retained.

Application changes are limited to this one option in `ProductionController`.
No migration or asset rebuild is required.

`MaterialAllocationControllerTest` reproduces the failure before the fix and
covers valid partial provision, zero quantity, excess quantity, fractional
quantity and invalid CSRF submissions afterward. All five cases pass with
37 assertions, including persisted stock, reservation and allocation checks.

## Browser acceptance results

- Created an order and a position for a system template with a two-component
  BOM. Attempted completion before building was rejected with HTTP 422.
- Reserved two components from a stock of ten: stock remained ten.
- Provided one reserved component: stock became nine, reservation one,
  allocated project stock one.
- Built the device with a confirmed serial number: the build consumed one
  component from project stock and one directly from the lot. Stock became
  eight, reservations and allocations became zero, and recorded usage was two.
  The serial counter advanced once.
- Completed a required decimal measurement and released its datasheet. The
  downloaded PDF checksum matched the stored checksum, and its source snapshot
  referenced the selected completed run.
- Completed a second run and released revision two. Revision one's downloaded
  bytes and checksum remained unchanged.
- Uploaded and downloaded a synthetic attachment byte for byte, verified the
  `nosniff` header and rejected anonymous access to both attachment and PDF.
- Explicitly completed and then delivered the order. Stock and material usage
  remained unchanged. No JavaScript errors were observed.

The accepted behavior for simultaneous absolute stock updates and for assembly
removal remains unchanged. This test does not claim NAS/public deployment
readiness or coverage of every possible template configuration.

## Verification artifacts

The final full MariaDB suite passes: 2,536 tests, 7,988 assertions, one existing
skip and nine existing PHPUnit deprecation notices. PHPStan level 5 reports no
errors, and all 48 production Twig files pass syntax validation. The candidate
controller was compared byte for byte with the running source: the only
application delta is removal of the unsupported form option.

Private scripts, screenshots, synthetic PDFs, step results and logs are retained
under Git-ignored `var/workflow-acceptance-20260915/`. The full test suite must
use fresh fixtures after the browser scenario because baseline tests depend on
the original fixture records. The exploratory run with additional browser data
was stopped. Its failed logins also left throttling state in the test cache;
the subsequent clean run therefore resets both fixture data and the isolated
`cache.rate_limiter` pool. Production login protection is unchanged.

No company data or runtime artifacts were staged or pushed to Git.

## Deployment

Deployed and verified at 07:23 CEST on 2026-09-15:
`localhost/partdb-material-allocation:2026-09-15`, image
`453fcd748047229e04a10cce7e8c3bc3dac376750cffb991f159159ec26a636d`.

A fresh full backup was verified before application replacement. All 75
database tables and 1,305 persistent files matched their pre-deployment
fingerprints exactly. The database container was retained without restart;
volumes, networks, port bindings and proxy initialization were preserved.
No migration ran. The deployed controller checksum matches the tested source.

All services are healthy. Localhost HTTPS returned HTTP 200 with certificate
verification. The previously configured LAN IP is absent from the current
network, so the direct check timed out. HTTPS also passed via the current
interface using the configured certificate hostname. Network configuration was
left unchanged; the user confirmed that the computer is temporarily connected
through a phone hotspot.

The three isolated test containers and their network were removed after
verification. Live services remain healthy, migrations are current, and the
proxy has no residual zombie processes.

The protected backup/checkpoint pointer is
`var/workflow-acceptance-20260915/deployment-checkpoint.txt`.
