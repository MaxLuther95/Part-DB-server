# Shared serial-number ranges

Rollout status (2026-09-11): implemented, tested and deployed after renewed user
authorization. BID and CID are available with next number 1 and four minimum
digits. Their build-type assignments are intentionally empty and configurable
by administrators. A protected full database backup was created before rollout.

Administrators configure ranges under **Production → Management → Serial-number
ranges** (`/production/serial-number-ranges`). Each range has a name, a unique
3–4 character ASCII alphanumeric prefix, the next suggested integer and a
minimum digit count (default 4). Prefixes are normalized to uppercase. Numbers
are positive integers up to 999,999,999; a next-number value of 1,000,000,000
indicates exhaustion. Larger numbers are never truncated to the minimum width.

The same editor assigns any number of system templates and native PartDB build
projects. Each build type can belong to only one range. Assignments are direct,
not inherited through a system's BOM or installed children. For example, several
board types can share BID while their enclosing device uses CID. No assignments
are guessed from project names. Move a type by unassigning it from its existing
range before assigning it to another one. Ranges without assignments remain
available for later reuse.

## Instance entry and building

Both instance registration/editing and the build wizard provide separate prefix
and number inputs. The complete identifier is stored as `PREFIX-number` without
spaces. The number remains manually editable. A confirmation checkbox is checked
by the user, never automatically; changing an input clears it in the browser.
The server also requires confirmation for every non-empty serial being saved.
The existing ability to omit a serial with an explanatory note remains intact.
An unassigned build type permits an unprefixed manual identifier, but not an
arbitrary prefixed identifier that bypasses range assignment.

Suggestions are read-only, not reservations. A confirmed identifier is never
silently replaced. The last step validates the current assignment and format,
locks the range, checks uniqueness and advances the counter within the same
transaction as the instance and stock changes. Manual numbers below the counter
do not rewind it; larger ones advance it. Rollbacks do not consume numbers.
The database enforces both full-identifier uniqueness and numeric uniqueness
within a range, preventing alternate padding or a later prefix change from
reusing an existing ordinal. Different ranges are independent.

Existing identifiers and their range provenance are retained when configuration
changes. Editing an instance without changing its serial does not allocate a
new number. Configuration editors detect stale versions, including a counter
advanced by another user's build, and ask the administrator to reload. Range
management is restricted to administrators; write forms use CSRF protection.

MariaDB snapshot conflicts (1020), lock timeouts and deadlocks produce a
review/retry message and roll back the whole operation. They do not turn a
reviewed identifier into a different number or bypass database isolation.
See MariaDB's [error 1020 documentation](https://mariadb.com/docs/server/reference/error-codes/mariadb-error-codes-1000-to-1099/e1020).

## Storage and rollout

Migration `Version20260911100000` adds a range table, two assignment tables with
unique build-type keys, and nullable range/ordinal columns on built instances.
It does not delete records, invent assignments or rewrite serial numbers.
Old in-progress build-session drafts are invalidated by workflow version 3;
restart those unfinished browser workflows after deployment.

The instance reference prevents deleting a used range through a database
cascade. The first interface intentionally provides creation and editing, not
destructive range deletion. Reusable template exports are unchanged and do not
transfer local numbering configuration.

## Verification — 2026-09-11

- Full test suite: 2,111 tests, 5,999 assertions, one existing skipped test,
  no failures. New coverage includes registration/editing, the full build
  wizard, administrator permissions, CSRF, stale editors, assignment conflicts,
  invalid/empty inputs, formatting, shared and separate sequences, rollback,
  manual entry and numeric uniqueness after format changes.
- PHPStan: no errors; changed Twig templates pass syntax validation.
- Actual form HTML and suggestion responses captured with authenticated
  BrowserKit requests. Chrome verified the source Stimulus controller at
  1920, 1280 and 375 pixels: aligned inputs, no page overflow, suggestions,
  confirmation reset and preservation of manually entered numbers. Browser
  fixture writes were rolled back; HTTP saving is covered by controller tests.
- Isolated MariaDB 12.3.2: migration/schema comparison and two independent PHP
  processes claiming the same number for different build types in a shared
  range. Exactly one instance committed; the counter advanced once; a later
  rolled-back claim did not consume a number. Test infrastructure was removed.
- Backup and deployment evidence is kept outside version control in
  `var/checkpoints/2026-09-11-serial-ranges/`.
- Build-details alignment follow-up: device labels, serial inputs and notes now
  align at the top of each row; confirmation remains below the serial inputs.
  Chrome checks of the actual wizard at 1920, 1280 and 375 pixels verified equal
  input/textarea top positions and no page overflow. The focused controller
  suite passes with 7 tests and 65 assertions. The template was deployed without
  restarting the container, preserving active session-based build drafts.
