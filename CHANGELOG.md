# Changelog

All notable changes to Crownd. Versions follow [semantic versioning](https://semver.org/).
The section of a version is used as the release notes on GitHub.
For a new release: raise `App::VERSION` in `src/App.php`, add a section `## [x.y.z] – YYYY-MM` here and merge the pull request.

## [1.0.0] – 2026-10

### Added
- First version: teams with logo and color, players with optional photo, squad templates, matches with line-up, a mobile ballot with one MVP per team, one vote per device, live results with MVP card, projector view, results shared per match, QR code poster, web installer and admin accounts.
- All database tables start with `crownd_`, so Crownd can share a database with other applications.
- Every pull request builds a test ZIP; merging a new version into `main` publishes the GitHub release with the ZIP automatically.
