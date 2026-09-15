# Protocol refinements — verification, 2026-09-10

## Changes

- Removed multiline text, date and datetime template field types. The live
  database had no remaining fields of these types before migration. The run
  header date and optional instance-owned notes remain available.
- Decimal answers now use validated canonical decimal strings, accepting comma
  or dot input without float conversion. Future fractional zeros are retained;
  the approved one-time migration trims historical DECIMAL padding only.
- Templates can be assigned to several systems and native build projects, with
  at most one template per build type. Database constraints and form validation
  prevent conflicting assignments. New runs use the assigned current published
  revision; existing runs remain pinned and accessible after unassignment.
- Removed the device-page template dropdown. The create button sits beside
  template management and is absent when no eligible template is assigned.
- Added a direct assignment/settings link in the protocol revision editor.
- Removed cross-navigation between protocol and datasheet template pages;
  actual datasheet mappings to protocol measurements remain supported.
- Moved datasheet publication to the top-right toolbar with its own CSRF form
  and an unsaved-change guard. Document settings now receive focus when selected.

## Verification

- Full PHPUnit suite: 2,100 tests, 5,898 assertions, one existing skipped test;
  no failures. Final toolbar layout recheck: 3 tests, 134 assertions.
- PHPStan: no errors. Twig: 42 files valid. Both production translation files
  pass YAML validation. `git diff --check` passes.
- Headless Chrome checked the editor at 1920, 1280 and 375 pixels, including
  publication form association, dirty-state warning, document-settings focus
  and PDF preview. Protocol forms and device controls checked at 1280/375 pixels.
- Isolated MariaDB **12.3.2**, matching the running instance: verified dry-run
  safety, migration of all 20 existing decimal answers against a private
  baseline, unchanged run/field counts, changed-table mappings, exact decimal
  round trips beyond nine places, assignment enforcement and unique constraints.
  Synthetic runtime writes were rolled back; the isolated database was removed.
- Deployed image: `localhost/partdb-protocol-refinement:2026-09-10`,
  `9f3fc47e358f2553ad237caad54c1265ca29e88829cde889d072cc37d93e2661`.
  Podman required `up --no-deps --force-recreate partdb`; ordinary `up` reused
  the previous container. The actual running image ID was verified afterwards.
- Live mapping and schema validation both pass. Both live containers are
  healthy; login returns HTTP 200. The compiled new editor bundle is present.
  All 20 historical decimal answers remain, with no padded fractional zeros.

## Recovery and assignment

The pre-deployment SQL backup is ignored by Git and permission-restricted:
`var/checkpoints/2026-09-10-protocol-refinement/pre-deploy.sql`.
SHA-256: `eb21cd8867e974b1891e39bc732a2d0e2c2a4c799ff4f5ee60e237ea5eaa8bd9`.
The previous image remains tagged
`localhost/partdb-before-protocol-refinement:2026-09-10`.
Schema rollback requires the matching database backup, not just the old image.

No actual build-type assignments were invented: the live database had none.
Configure these via **Assignment & settings** before creating new runs. Existing
protocol runs, templates and published datasheet files were not deleted.
No commit or push was performed.
