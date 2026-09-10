# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-09-10

### Fixed
- cURL 传输层：空 URL 现在立即抛 `TransportException`（此前只会以底层失败暴露）；空 method 传 `null` 而非空串。
- `Config` 签名校验对 `appId` 显式转串，消除可空性歧义（前置校验已保证其非空）。

### Changed
- `Entity::__construct` 与 `SessionAuth::__construct` 标为 `final`：扩展点保持为 FORM_ID / 覆盖 `loginParameters()`，不再支持自定义构造签名（本包 1.0.0 当日刚发布，无下游影响）。
- 全部操作方法与构建器参数补上显式 `array<mixed>` / `array<string,mixed>` 注解；`Result::rows()` 改为可直接验证的写法。均为 IDE/静态分析收益，运行期行为不变。

### Added
- CI（GitHub Actions）：PHP 8.1–8.5 矩阵，composer validate + 离线单测（依赖真实凭据的用例须标 `@group online`，CI 排除）+ PHPStan level 8 质量门。
- `.gitattributes` export-ignore：tests/tools/docs/配置不再进入 dist 分发包。
- 中英 README 首屏徽章；`composer analyse` 与 `composer test:ci` 脚本；PHPStan dev 依赖与 `phpstan.neon`。

## [1.0.0] - 2026-09-10

### Added
- Initial independently written client for the K/3 Cloud WebAPI.
- Two authentication strategies: app-signature (X-Kd / X-Api HMAC) and username/password session (`AuthService.ValidateUser` + `kdsessionid` cookie).
- Convenience wrapper for `ExecuteBillQuery` and typed `Result` object.
- Schema-free fluent bill builder (`Entity`/`Line`, `->bill()/->entity()`, typed
  subclasses, named terminals, merge semantics) plus `build-metadata.php` entity/field
  reference cleaning and `--scaffold` class generator.
- Cross-process session persistence in password mode (on by default): the `kdsessionid`
  is written to a `FileSessionStore` under the system temp dir after login and replayed by
  later processes; expired sessions self-heal via the existing re-login/replay path and dead
  entries are deleted. Redirect with `->withSessionStorePath()`, replace with
  `->withSessionStore()` (any `SessionStore` implementation), disable with
  `->withoutSessionPersistence()`.
