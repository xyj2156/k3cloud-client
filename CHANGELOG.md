# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial independently written client for the K/3 Cloud WebAPI.
- Two authentication strategies: app-signature (X-Kd / X-Api HMAC) and username/password session (`AuthService.ValidateUser` + `kdsessionid` cookie).
- Convenience wrapper for `ExecuteBillQuery` and typed `Result` object.
- Cross-process session persistence in password mode (on by default): the `kdsessionid`
  is written to a `FileSessionStore` under the system temp dir after login and replayed by
  later processes; expired sessions self-heal via the existing re-login/replay path and dead
  entries are deleted. Redirect with `->withSessionStorePath()`, replace with
  `->withSessionStore()` (any `SessionStore` implementation), disable with
  `->withoutSessionPersistence()`.
