# Show document sections only for assigned templates — 2026-09-14

Status: implemented, verified and deployed to the LAN instance at 21:56 CEST.

Device and assembly detail pages hide a protocol or datasheet section only
when neither an applicable published template nor existing records are present.
Existing runs and released documents remain visible after template unassignment.
The redundant missing-assignment messages are removed. The user explicitly
confirmed that existing records should remain visible.

Assignment lookup uses the respective read permission, so readers can still
see assigned document sections without creation permission. Existing creation,
read and download authorization checks remain in force. The change is limited
to the detail-page template and its controller. No schema or frontend build
changes are needed.

The user authorized deletion of conflicting test records if needed. No data
conflict occurred in the application data and no live record deletion was
needed for this display change. A duplicate synthetic test identifier was
removed from the isolated verification database before repeating the checks.

Verification:

- Existing protocol/datasheet assignment and document workflow tests:
  **12 tests / 240 assertions**, no failures or errors.
- Isolated HTTP checks: no assignments; both assigned with documents; read-only
  access without creation rights; removed protocol assignment with existing
  runs; both assignments removed with existing records. Visibility matched the
  requested rule in all five cases. The checks used synthetic data only.
- PHP syntax and diff whitespace checks pass. The relevant Twig branches were
  compiled and rendered in the HTTP checks.

Candidate: `localhost/partdb-hide-empty-documents:2026-09-14`, image ID
`d66cb39261b40b7c1b902d4bfbf7fbf06b55213402b0e86f8944a71fddf260d4`.
Private checks and source checkpoints remain in Git-ignored
`var/hide-empty-documents-20260914/`.

HTTP was paused at 21:56:22 CEST for a fresh verified full backup (1,757
archive entries). The reviewed plan replaced application and HTTPS containers,
retaining the database container, persistent volumes, networks, bindings and
proxy init setting. No migration ran. Fingerprints of all 75 database tables
and 1,305 persistent files matched exactly before reopening access.

At 21:56:51 CEST, application and HTTPS were healthy, localhost and LAN HTTPS
login returned 200 with successful certificate verification, and both deployed
source files matched the checked candidate. The protected checkpoint is
`var/checkpoints/2026-09-14-hide-empty-documents-20260914T195603Z/`.

The isolated test containers and their own resources were removed. Final
checks confirm healthy live services, current migrations and zero proxy zombie
processes. No live records were deleted and no Git commit or push occurred.
