# Site-aware material status on orders

Additional accessories and both kinds of inventory-part assignments in the
order tree previously compared each row's quantity with the global physical
stock. These badges ignored the production site, other reservations, and demand
for the same part elsewhere in the order. This contradicted the material plan.

All three paths now use one Twig macro reading the existing material plan:

- Without a production site, show a neutral “Production site not set”.
- With a site, show whether the total order demand for that part is covered or
  the quantity missing for the order, rather than promising stock to each row.
- Explain site, total demand, free local stock, covered local reservations,
  provided stock and installed quantities in the tooltip.
- Without material-read permission or a surviving part reference, show no
  material figures. Preserve the existing note marker.

The planner, reservation calculations and database schema are unchanged.

## Verification

Ten controller scenarios cover missing site, remote-only stock, repeated demand,
child storage locations, other orders' reservations, covered and uncovered own
reservations, provided stock, unknown stock and material-read permission. Each
stock scenario checks the additional accessory, slot assignment and nested
detached assignment against the material table. Result: 150 assertions passed.

Production regression suite: 298 tests, 2,507 assertions, one existing
database-specific skip. Twig and YAML lint passed. An isolated browser instance
using the candidate image passed the German no-site, shortage and covered cases,
including actual tooltip text and no JavaScript errors. All fixtures are
synthetic; no customer data is included in tests or the image build context.

## Deployment

Deployed on 2026-09-17 as `localhost/partdb-material-availability:2026-09-17`
after a verified full backup. Comparisons confirmed all 78 database tables,
migration history and 1,320 persistent files unchanged. No migration was needed.
All live containers are healthy; both local HTTPS addresses returned HTTP 200
with certificate verification, and deployed source hashes match the candidate.
The isolated test containers and network were removed after verification.
