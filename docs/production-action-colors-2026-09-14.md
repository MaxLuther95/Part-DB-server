# Production action colors follow native Part-DB

The user requested consistent colors throughout the project and chose the
native Part-DB convention. This supersedes the earlier request for blue
production creation buttons documented in the filter and assignment reports.

Reference: the locally retained official Part-DB 2.17.0 sources use green for
creating parts and new administration entities, blue for editing existing
entities, and red for deletion. Production now follows that distinction:

| Action | Color |
| --- | --- |
| Create a project, order, device, template, revision, field, or other entry | Green |
| Add a note, attachment, or designer element | Green / outlined green |
| Navigate, inspect, filter, preview, edit, or save an existing entity | Blue / outlined blue |
| Confirm completion, release, installation, or a material operation | Green |
| Delete or invalidate | Red |
| Cancel or return | Neutral |

The build overview link is blue. Starting a new device build and registering an
existing device are green creation actions. Intermediate wizard navigation is
blue, while the final action that creates the build and withdraws material
remains green. Shared production entity forms distinguish creation from editing
using the entity ID, matching native administration forms.

Changes are limited to 28 production templates and the existing color assertions
in two tests. Native Part-DB source files are unchanged by this task. No global
CSS override, dependency, compiled asset, permission, route, or database change
is introduced. Status badges and test-result colors retain their meaning.

## Verification

- Existing navigation/filter/assignment tests pass: 23 tests, 247 assertions.
- All 231 Twig templates pass syntax validation.
- Actual candidate image in Chrome: 29 distinct pages in both light and dark
  mode (58 page/theme combinations). This covers production navigation,
  creation forms, customer/order/template details, native parts and user pages.
- Creation links and new production forms are green; editing links and the
  build-overview link are blue. Protocol completion and datasheet release
  remain green. No JavaScript errors were observed.
- Light/dark build-page screenshots were inspected visually.
- Native files temporarily considered during the audit were verified byte for
  byte against their pre-task versions; the final image copies only production
  templates. The frozen 28-file image context matches the checked workspace.

## Deployment

Deployed at 22:47 CEST on 2026-09-14 to the existing LAN instance:
`localhost/partdb-action-colors:2026-09-14`, image
`405e68f9ee204f6a0fad7bb44b0b5b2a793e4f4b8d3bb1acab74d9f0ec9537c0`.
A fresh full backup was verified before replacing the app and HTTPS containers.
The database container, persistent volumes, networks, bindings, and proxy init
setting were retained. No migration ran. All 75 database tables and 1,305
persistent files matched the pre-deployment fingerprint exactly.

HTTPS and certificate verification passed on localhost and the LAN address.
All 28 deployed template hashes match the reviewed candidate. The private
checkpoint pointer is `var/action-colors-20260914/deployment-checkpoint.txt`.
No source changes, company data, backups, or runtime files were staged or pushed.

Private synthetic test artifacts and screenshots are under the Git-ignored
`var/action-colors-20260914/` directory.
