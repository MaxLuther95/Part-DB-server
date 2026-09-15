# Workflow plausibility review after the 2.17 deployment

Status: review completed against the deployed custom 2.17 image. Six actionable
workflow findings are reproduced below. No application fix or deployment is
included in this review. The existing concurrent absolute-stock-update behavior
was explicitly accepted again by the user during this review and is excluded
from remediation.

Follow-up: F1/F3 were corrected and deployed at 20:46 CEST; see the
[stale build guard](stale-build-guard-2026-09-14.md). The findings below retain
the original reproduction evidence. F2/F4 were subsequently corrected and
deployed at 21:15 CEST; see the
[protocol concurrency guard](protocol-concurrency-guard-2026-09-14.md).
F5 and the requested explicit confirmation of missing required fields were
corrected and deployed at 21:42 CEST; see
[incomplete release confirmation](incomplete-release-confirmation-2026-09-14.md).
F6 was subsequently closed by business-rule clarification: an installed assembly
is considered finished and remains finished after removal. The existing
installation/removal behavior is therefore intentional; no prior-status
restoration is required. See [build status simplification](build-status-2026-09-14.md).

## Scope and evidence

The full suite ran against the deployed Trixie image and a new, isolated MariaDB
12.3.3 database: **2,482 tests, 7,643 assertions, no failures/errors**, one skip
and nine PHPUnit deprecations. Of these cases, 183 belong to production test
namespaces; the rest include native Part-DB, API and shared functionality.

Source inspection covered orders, configured positions, template changes,
serial identifiers, build finalization, stock/reservations, installed hierarchy,
protocol publication/edit/completion/invalidation, datasheet release, imports,
attachments, deletion guards and permission boundaries. Existing regression
tests and the earlier full workflow/restore reviews were used as supporting
evidence; every historical browser scenario was not manually replayed today.

Additional checks used the actual runtime image, real application services with
independent MariaDB connections, and two fresh Chrome sessions. The browser
confirmed stale draft overwrites and loss of notes after a rejected datasheet
release. Correcting the missing required note allowed an actual PDF download.
A stale form submitted after completion was correctly rejected. Nine central
overview pages returned HTTP 200 without JavaScript errors.

The reviewed application source remains unchanged: 1,494 source/template/asset/
configuration/migration files match the tested candidate manifest. No live
database, real login, production volume or company document was used. Synthetic
test artifacts are retained under Git-ignored `var/workflow-review-20260914/`.
The three temporary containers, their own anonymous volumes and the isolated
network were removed after acceptance. The live application remained healthy.
No Git staging, commit or push was performed.

## Findings requiring action

### F1 — High: an old build wizard can combine the wrong type and materials

Code: `src/Services/Production/ProductionBuildWorkflow.php:305` and `:331`;
`src/Entity/Production/BuildInstance.php:244`.

1. Open a build wizard for an order position using build type A, which requires
   two units of a component. Confirm its material selection.
2. Change that still-unbuilt order position to type B, with a different BOM.
3. Finish the already-open wizard in a later request.

The saved instance is assigned type B through `setProjectPosition()`, but the
material plan comes from type A stored in the wizard. The isolated service
reproduction saved the replacement type and withdrew two old-model components
although the replacement model's BOM was empty. No simultaneous request is
necessary. Evidence: `stale-build.php` and `stale-build.log`.

Recommendation: compare the reviewed position/type/configuration with current
state before any final mutation. Reject a stale draft with a useful route back
to configuration/material review. Never silently substitute the new type. This
check can be implemented separately from the larger order-snapshot architecture.

### F2 — High: overlapping requests can alter completed protocol answers

Code: `src/Controller/Production/ProtocolController.php:375`, `:403`, `:409`;
`src/Entity/Production/ProtocolAnswer.php:95`.

Two independent entity managers first load the same draft. Request A completes
it and commits. Request B, which already loaded its draft state, then saves an
answer and touches the run. The result remains `completed`, but its measurement
changes from `1.000` to `9.999`. Entity guards consult their in-memory run state;
there is no database-level concurrency check on this path. Evidence:
`concurrency.php` and `concurrency.log`.

This reproduction deliberately interleaves real application operations on two
database connections; it is not a simultaneous HTTP-worker load test. A separate
browser countercheck confirms that a POST starting only after completion is
correctly rejected and preserves `2.222`. The defect requires overlapping server
operations, not merely an old browser tab submitted after completion.

Recommendation: serialize edit/complete/invalidate operations for one run and
revalidate current state before applying answers. Include answer persistence in
the same guarded operation; a lock acquired after form mutation is insufficient.

### F3 — Medium: cancellation does not invalidate an already-open build wizard

Code: `src/Controller/Production/ProductionController.php:523`;
`src/Services/Production/ProductionBuildWorkflow.php:299`.

Starting a position build requires an order in production. Finalization does not
repeat that check. The isolated service test opens a draft, cancels the order,
then successfully creates a build still attached to that cancelled order.
Evidence: the second case in `stale-build.log`.

Recommendation: recheck order status at finalization and refuse cancelled,
delivered or otherwise ineligible orders before stock or serial mutations.
Use the same eligibility rule for the start and final commit.

### F4 — Medium: stale protocol forms silently overwrite newer draft work

Code: `src/Form/Production/ProtocolRunType.php:25`;
`src/Controller/Production/ProtocolController.php:388`.

Two Chrome sessions load the same draft. A saves `1.111` and an important note.
B later submits its older form with `2.222` and another note. Both submissions
succeed; A's work disappears without a conflict notice. This requires no overlap
between server requests. Evidence: `browser-results.json`, `stale-protocol.png`.

Recommendation: carry an edit version from form display through submission.
Reject a stale version while preserving the submitted values for comparison.
Coordinate this with F2; a server transaction alone does not detect an old form.

### F5 — Medium: rejected datasheet release discards entered notes

Code: `src/Controller/Production/DatasheetTemplateController.php:315` and `:253`;
`templates/production/datasheet/prepare.html.twig:23`.

Enter an optional customer note and leave a required note empty. The real browser
release is correctly refused, but the redirect rebuilds the preparation form
from template defaults and loses the entered optional text. The screenshot shows
the error toast and emptied fields. Re-entering the notes and supplying the
required value successfully releases and downloads a PDF.

Evidence: `browser-results.json`, `datasheet-validation-reset.png`, and the
synthetic downloaded PDF. Recommendation: return a validation response with the
submitted notes and run choices retained, analogous to protocol completion.
Also clarify the note help text: it currently says the text applies only to the
preview, although it is also stored in the released customer document.

### F6 — Closed by user clarification: removal keeps assemblies finished

Code: `src/Services/Production/BuildConfigurationCompatibility.php:93` and `:164`.

An `in_progress` or `paused` assembly can be assigned to an installed position.
Its status becomes `installed`. Unassigning it then sets `completed`, losing its
previous processing state. The service reproduction covers all three starting
states: `in_progress`, `paused`, and `completed`; all end as `completed`.
Evidence: `status.php` and `status.log`.

The user explicitly confirmed that an installed assembly is considered finished
and that removal must set `completed`. The earlier recommendation to restore a
historical processing status is superseded. No installation/removal change is
required, and no historical-status field is introduced. The separate request
to remove `paused` from devices/assemblies is documented in
[build status simplification](build-status-2026-09-14.md).

## Accepted limitation — no stock-concurrency remediation

The user reaffirmed that simultaneous absolute stock updates may remain as they
are, consistent with current Part-DB behavior and the small team. This supersedes
any suggestion during this review to make that behavior a new blocker.

For traceability, both the native stock helper and the actual build finalizer
were reproduced with two contexts reading ten units, then each consuming seven.
Both operations committed and the stored amount was three. Artifacts:
`concurrency.log` and `build-concurrency.log`. No stock algorithm, transaction
rule or reservation behavior was changed, and no remediation item is added.
This accepted limit does not decide F1/F3, which involve sequential stale wizard
state, nor the separate protocol-integrity rule in F2.

## Existing decisions and practical limits

- Order-owned configuration snapshots and mutable datasheet title/version
  semantics remain the already-recorded architecture work. They are not new
  findings or permission to redesign the model in this review.
- Central production history integration remains a separate roadmap task.
- Current import, permission, serial-conflict and backup regression tests pass;
  earlier synthetic browser/restore evidence is linked in the roadmap. A fresh
  NAS restore and public exposure acceptance were not performed here.
- Test-harness corrections (root fixture confirmation, constructor arguments,
  and using the actual toast/redirect behavior) are not application findings.

Recommended implementation order: stale build validation (F1/F3), protocol
concurrency and stale-form protection together (F2/F4), recovery of datasheet
inputs (F5), then the agreed installation/removal status rule (F6).
