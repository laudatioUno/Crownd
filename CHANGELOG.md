# Changelog

All notable changes to Crownd. Versions follow [semantic versioning](https://semver.org/).
The section of a version is used as the release notes on GitHub.
For a new release: raise `App::VERSION` in `src/App.php`, add a section `## [x.y.z] – YYYY-MM` here and merge the pull request.

## [1.1.0] – 2026-10

### Added
- Crop tool for player photos: after choosing a photo, move and zoom it inside a round frame (drag, slider, mouse wheel, two fingers or keyboard) and see a live preview on the ballot, the MVP card and in the small lists.
- The original photo is kept, so the cut-out can be changed later («Ausschnitt ändern») without uploading the photo again. Large phone photos are scaled down in the browser before the upload.
- Version number in the footer of the voting page and the admin area.

### Changed
- Ballot: the player photos are more than twice as large (about 150 px on a phone instead of 72 px), with a ring in the team color and the shirt number as a badge on the photo; the chosen player is marked in gold.
- Without a chosen cut-out, portrait photos are cut closer to the top, where the face usually is.
- Player photos are stored as JPEG (smaller, faster on mobile networks). Photos uploaded with 1.0.0 stay as they are; to change their cut-out, upload them again.

## [1.0.0] – 2026-10

### Added
- First version: teams with logo and color, players with optional photo, squad templates, matches with line-up, a mobile ballot with one MVP per team, one vote per device, live results with MVP card, projector view, results shared per match, QR code poster, web installer and admin accounts.
- All database tables start with `crownd_`, so Crownd can share a database with other applications.
- Every pull request builds a test ZIP; merging a new version into `main` publishes the GitHub release with the ZIP automatically.
