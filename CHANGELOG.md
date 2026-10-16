# Changelog

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-10-16

### Changed

- The report mail names the main domain from the system settings in the subject and body, so
  several instances on the same server can be told apart. The server host name is still shown in
  the body and used as fallback when no domain is configured.
- With `expose_details: true` the status endpoint also returns the main domain (`domain`, `null`
  when not configured) next to the host name.

## [2.0.0] - 2026-10-15

First public release under the GNU General Public License v3.0 or later. Versions 1.x were only
used internally and are not published.

### Changed since 1.2.0

- The composer checks retry a failed composer run once and report an unreachable package repository
  or advisory database as a warning instead of an error.
- Composer error output in the check details no longer contains PHP deprecation notices, the usage
  synopsis or duplicate lines, and long error messages are no longer wrapped at 80 columns.

[2.1.0]: https://github.com/pringuin/opendxp-health-bundle/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/pringuin/opendxp-health-bundle/releases/tag/v2.0.0
