# Protocol template deletion and grouped export — 2026-09-15

The user confirmed that deletion refers to **templates**, not filled device
protocols. A template delete action was missing from the controller and UI.

## Template deletion

- The template detail and settings pages now offer the normal red delete action
  with a confirmation dialog. A cancelled dialog does not submit a deletion.
- Deletion requires the existing template `delete` permission plus the same
  administrator permission used for template editing. The route accepts POST
  only and validates a template-specific CSRF token.
- `ProtocolManager.deleteTemplate()` rejects templates referenced by any protocol
  run, including draft, completed and invalid runs. The response explains that
  the template can instead be deactivated in its settings. Existing run records,
  answers, revisions and assignments are preserved.
- Unused templates can be deleted together with all their revisions, sections,
  fields and template assignments. Native projects, system templates and physical
  devices are not deleted. This includes published revisions if no runs use them.
- Existing foreign keys also protect against a run created concurrently between
  the usage check and deletion. A failed flush rolls back and returns the same
  understandable refusal rather than an unhandled database error.

No automatic deletion of live templates or protocols was performed during the
implementation. There is no schema migration or soft-delete workaround.

## Export selection

The export list shows each template once. A single revision is directly selectable;
multiple revisions are initially collapsed below the template name and can be
expanded to select explicit versions. Groups use template identities, so templates
with identical names remain distinct. Selected groups stay open after validation
errors. Native HTML details/summary controls provide keyboard interaction without
adding another JavaScript controller or changing the existing export workflow.

The existing form validation, permissions, CSRF protection, 1–100 revision limit,
JSON format and direct revision-download links are unchanged. No revision is
selected implicitly, and template exports still exclude device runs, measurements,
orders, users and local template assignments.

## Validation

- Production suite: **275 tests, 2,169 assertions**, no failures/errors; one existing
  skip. New cases cover unused draft/published deletion, preserved used templates
  in every run status, POST/CSRF/permission restrictions, duplicate template names
  and retained selection after form errors.
- PHPStan level 5 and Twig checks pass.
- Candidate Chrome tests exercise collapsed/expanded groups, selecting two
  revisions, a real JSON download, confirmation cancellation, unused deletion from
  the settings page and used-template rejection with a visible toast. Downloaded
  JSON contains definitions only; synthetic private run markers are absent.
- A final Twig-only alignment adjustment removes the horizontal form-label offset
  from checkboxes; browser behavior is rechecked against the same template bytes.

All test data is synthetic. Test resources, screenshots, checkpoints and environment
files are kept under private Git-ignored paths. No staging, commit or push is part
of this work.

## Deployment

Deployed at 11:46 CEST as `localhost/partdb-protocol-management:2026-09-15`
(`ad35a45669a9d401d2eb9a71f32b862f7f184e528cd19062dad3fbf90c28c658`).
A full backup containing 1,760 archive entries was verified before deployment.
No migration or template deletion was run against live data. All existing values
across 78 tables and hashes of 1,311 persistent files match before and after;
migration and log history also remain unchanged. The database container identity
and start time, named volumes, ports and networks were preserved. The live hashes
of all seven changed source files match the tested candidate. HTTPS hostname,
current hotspot IP and localhost endpoints pass certificate verification and
return 200. The previous language-import image is retained as a fallback.

The automatic approval review initially misclassified the plan output as actual
container operations. Inspecting the script and adding before/after container-ID,
start-time and environment comparisons established that the plan only performs
inspection and `compose --dry-run --no-start`. The subsequent review accepted the
verified plan, and the separate deployment completed successfully.
