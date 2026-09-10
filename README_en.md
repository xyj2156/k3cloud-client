[简体中文](README.md) | **English**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/xyj2156/k3cloud-client.svg)](https://packagist.org/packages/xyj2156/k3cloud-client)
[![Total Downloads](https://img.shields.io/packagist/dt/xyj2156/k3cloud-client.svg)](https://packagist.org/packages/xyj2156/k3cloud-client)
[![PHP from Packagist](https://img.shields.io/packagist/php-v/xyj2156/k3cloud-client.svg)](https://packagist.org/packages/xyj2156/k3cloud-client)
[![License](https://img.shields.io/packagist/l/xyj2156/k3cloud-client.svg)](LICENSE)
[![Tests](https://github.com/xyj2156/k3cloud-client/actions/workflows/tests.yml/badge.svg)](https://github.com/xyj2156/k3cloud-client/actions/workflows/tests.yml)

# k3cloud-client — an unofficial PHP client for the Kingdee Cloud (K/3 Cloud) WebAPI

A small, dependency-light, **independently written** client for the K/3 Cloud
WebAPI. It focuses on getting you productive fast and supports the **two real
authentication modes** you will meet in the wild:

- **Third-party app signing** — AppID + AppSecret, per-request `X-Kd-*` / `X-Api-*`
  HMAC headers, no server session. Typical for the public-cloud gateway.
- **Username / password session** — classic `AuthService.ValidateUser` login and
  a `kdsessionid` cookie, handled lazily and transparently; the session is
  persisted to disk by default so short-lived processes don't pay a re-login.
  Typical for private / on-premise installs where no AppID was issued.

> **Unofficial.** This is a clean-room implementation written against the
> documented K/3 Cloud HTTP interface. It is not affiliated with, endorsed by, or
> derived from any official Kingdee SDK, and contains no code from any official
> SDK package. “Kingdee” and “K/3 Cloud” are trademarks of their owner and are
> used here only to describe the interface this client talks to.

---

## Install

```bash
composer require xyj2156/k3cloud-client
```

Requires PHP 8.1+ and `ext-curl`.

## Quick start

### Option A — third-party app signing

```php
use K3Cloud\K3CloudClient;

$api = K3CloudClient::appSignature(
    serverUrl: 'https://api.kingdee.com/galaxyapi/',
    acctId:    '62f3c9b0xxxxxxxx',
    userName:  'api_user',
    appId:     '204399_xxxxxxxxxxxxxxxx',
    appSecret: 'your-app-secret',
);

$rows = $api->query('BD_Currency', ['FCURRENCYID', 'FNUMBER', 'FNAME'])
    ->throwIfError();

foreach ($rows->rows() as [$id, $number, $name]) {
    echo "$number — $name\n";
}
```

### Option B — username / password session

```php
use K3Cloud\K3CloudClient;

$api = K3CloudClient::password(
    serverUrl: 'https://192.168.1.100:8080/K3Cloud',
    acctId:    '62f3c9b0xxxxxxxx',
    userName:  'administrator',
    password:  'your-password',
)->insecure();   // only for self-signed / on-premise certs

$created = $api->save('BD_Currency', [
    'NeedUpDateFields' => [],
    'IsDeleteEntry'    => 'true',
    'Model'            => ['FNAME' => '测试币种', 'FNUMBER' => 'TESTCUR', 'FPRECISION' => 2],
])->throwIfError();

echo $created->id(), ' / ', $created->number(), "\n";
```

Both modes are created the same way — a named constructor returning a client.
Options chain identically regardless of auth mode:

```php
$api = K3CloudClient::password($url, $acct, $user, $pwd)
    ->insecure()               // trust self-signed certs
    ->withTimeouts(10, 60);    // connect / request, seconds
```

Fluent options can be chained at any time: each returns a new same-class client,
the transport is rebuilt so the setting takes effect, and the current auth is
rebased onto it. A username/password session therefore survives a reconfigure
(no second login) as long as the credentials are unchanged — login still happens
at most once, lazily, on the first request, and that "once" now spans processes:
the `kdsessionid` is persisted to disk by default (see below). Changing the
credentials (or calling `->relogin()`) is what forces a fresh authentication.

### Advanced: building a `Config` directly

If you need to compose or persist configuration before creating the client, use
`K3Cloud\Config` explicitly and inject it — functionally equivalent to the
named constructors:

```php
use K3Cloud\Config;
use K3Cloud\K3CloudClient;

$api = new K3CloudClient(
    Config::appSignature($url, $acct, $user, $appId, $appSecret)->withTlsVerification(true)
);
```

### Session persistence (on by default)

PHP requests are one process each: if `kdsessionid` lived only in memory, every
new process (cron run, queue worker, every HTTP request) would first pay a
`ValidateUser` round trip. So in password mode the session cookie is **written
to disk after login** and replayed by later processes:

- **Location**: `k3cloud-sessions/session-<credential-fingerprint>.json` inside
  the system temp dir (`sys_get_temp_dir()`; typically `%TEMP%\k3cloud-sessions`
  on Windows). Each server + account + user + password + lcid combination gets
  its own file, so changed credentials naturally start a fresh entry.
- **Self-healing**: there is no probe login. The next process replays the
  persisted session directly; if the server answers "session lost, please login
  again", the client transparently re-authenticates, replays the request and
  refreshes the stored entry. An expired session therefore costs exactly one
  extra round trip — never a wrong identity, never a stall.
- **Dead entries are deleted**: `->relogin()` or a detected session loss also
  drops the on-disk entry, so a dead cookie is never handed to yet another process.

> ⚠️ **Security note**: `kdsessionid` is effectively a short-lived login
> credential — whoever holds it can call the WebAPI as that user until your
> server-side session expires. Files are written `0600`, directories `0700`, but
> a shared host's `sys_get_temp_dir()` may not be trustworthy. On multi-user
> machines pick a dedicated directory or turn persistence off.

Three switches (meaningful in password mode only; harmless no-ops for signing):

```php
$api = K3CloudClient::password($url, $acct, $user, $pwd)
    ->withSessionStorePath('/var/cache/myapp/k3cloud')   // different directory (file store)
    // ->withoutSessionPersistence()                      // off: memory-only, login per process again
    // ->withSessionStore($store)                         // custom: Redis / database / your own cache
```

A custom store only implements the three `K3Cloud\Auth\SessionStore` methods
(`load` / `save` / `forget`, keyed by the credential fingerprint). Sharing one
store across web nodes amortises the login to once per cluster.

That is the whole onboarding story: create the client, call an operation. Login
and signing are automatic — how the session is stored, where, or whether at all
is yours to decide.

## What you get back

Every operation returns a `K3Cloud\Result`:

| Method            | Meaning |
|-------------------|---------|
| `->payload()`     | decoded body (array / scalar) |
| `->rows()`        | list of rows for query responses |
| `->isSuccess()`   | business success flag |
| `->errorMessage()`| first error message, or `null` |
| `->id()` / `->number()` | new record id / number from a Save |
| `->throwIfError()`| throws `ApiException` unless successful |

Operations **do not throw** on a business error by default — chain
`->throwIfError()` when you prefer exceptions. Transport / auth failures do
throw (`TransportException`, `AuthException`).

## Operations

`save`, `batchSave`, `draft`, `view`, `submit`, `audit`, `unaudit`, `delete`,
`allocate`, `cancelAllocate`, `cancelAssign`, `push`, `groupSave`, `disassembly`,
`flexSave`, `getSysReportData`, `executeOperation`, `executeBillQuery`,
`billQuery`, `query`, `queryBusinessInfo`, `queryGroupInfo`, `workflowAudit`,
`groupDelete`, `switchOrg`, `sendMsg`, `attachmentUpload`, `attachmentDownload`.

Anything not listed is still reachable through the generic entry points:

```php
$api->operation('AttachmentDownLoad', [ $payload ]);      // short name
$api->service('Kingdee.BOS.WebApi.ServicesStub.Some.Service', [ ...$params ]);
```

### The `query()` helper

```php
$result = $api->query('SAL_SaleOrder', ['FSBILLNO', 'FTOTALAMOUNT'], [
    'FilterString' => "FDate >= '2024-01-01'",
    'OrderString'  => 'FDate DESC',
    'TopRowCount'  => 100,
]);
```

## Building a bill (fluent)

Instead of hand-assembling the nested `Model`, build it. The base `Entity` is
schema-free — any entity and any 二开 / extension field work without SDK support —
and you can subclass it per entity for typed helpers and IDE completion.

```php
use K3Cloud\K3CloudClient;

// Generic (no class needed) — returns the base Entity:
$api->bill('SAL_SaleOrder')
    ->ref('FBillTypeID', 'XSDD01_SYS', 'FNUMBER')   // base-data reference
    ->ref('FCustId', '16.195.06.0001')
    ->custom('F_PEYC_Decimal', '1710')              // 二开 field, no schema
    ->line('FSaleOrderEntry', fn($row) => $row
        ->ref('FMaterialId', '02.04.03.089')
        ->set('FQty', '1.000'))
    ->package(['FSaleOrderFinance' => ['FSettleCurrId' => ['FNumber' => 'PRE001']]])
    ->save()                                        // -> Result
    ->throwIfError();
```

Or with your own subclass (typed completion, `: static` keeps the concrete type):

```php
use K3Cloud\Entity;

final class SaleOrder extends Entity
{
    public const FORM_ID = 'SAL_SaleOrder';
    public function customer(string $n): static { return $this->ref('FCustId', $n); }
}

$order = $api->entity(SaleOrder::class);   // typed: $order is SaleOrder
// or class-static: $order = SaleOrder::for($api);
$order->customer('16.195.06.0001')->save();

// register so the string entry auto-resolves too (optional convenience):
Entity::register(SaleOrder::FORM_ID, SaleOrder::class);
$api->bill('SAL_SaleOrder');               // → SaleOrder
```

Mutators: `set/ref/custom` overwrite a field; `line()` is the only append path;
`package()`/terminal `$patch` deep-merge the Model (list values replace);
`control()`/`mergePayload()` handle top-level flags; `save()/draft()/call()` are
the terminals returning `Result`. Design rationale lives in
[`docs/design-bill-builder.md`](docs/design-bill-builder.md).

## Configuration notes

- **`serverUrl`** — the WebAPI root, e.g. `https://host:port/K3Cloud` (private)
  or `https://api.kingdee.com/galaxyapi/` (public). A trailing slash is optional.
- **TLS** — verification is **on by default**. Call `->insecure()` on the client
  (or `Config::withoutTlsVerification()`) for self-signed / private installs.
- **Timeouts** — `->withTimeouts($connect, $request)` on the client, or
  `Config::withTimeouts(...)` when building a `Config` directly.
- **Session on disk** — on by default in password mode (`FileSessionStore`,
  system temp dir); see "Session persistence" above for location, custom stores
  and how to turn it off.

### Login payload compatibility

Password mode calls `AuthService.ValidateUser` with the common
`[acctId, userName, password, lcid]` order. A minority of builds expect a
different argument list. If login is rejected, subclass `SessionAuth` and
override `loginParameters()` — the rest of the client is unchanged.

## Development

```bash
composer install
composer test        # runs PHPUnit
```

Examples live in `examples/` and can be run after filling in credentials.

To (re)build the cleaned entity/field reference used by the metadata layer, drop
a fresh K/3 Cloud export somewhere git-ignored (e.g. `.docs/raw/`) and run:

```bash
php tools/build-metadata.php [.docs/raw/entity.json] [.docs/raw/field.json] [.docs]
```

Add `--scaffold` to also emit a starting per-entity PHP class (FORM_ID + field-name
constants + entry-line helpers) into a git-ignored `.docs/scaffold/` — a template to
copy into your own project and edit. `--with-custom` folds 二开 fields in as
constants too; `--namespace=...` sets the generated namespace. None of it is a
whitelist — unknown fields still work via `->custom()/->package()`.

```bash
php tools/build-metadata.php ... --scaffold --with-custom --namespace=App\\K3Cloud\\Entities
```

Only the cleaned artifacts (`kingdee_field.standard.json`,
`kingdee_field.custom.json`, the report) are tracked; the raw export never is.

## Roadmap

- **Fluent Bill builder** — *shipped* (`Entity`/`Line`, `->bill()/->entity()`,
  typed subclasses, named terminals, `->call()`, merge semantics, identifier-op
  `@param` completion). Design in
  [`docs/design-bill-builder.md`](docs/design-bill-builder.md).
- **Metadata scaffold** — *shipped*: `build-metadata.php --scaffold` emits a
  starting per-entity class + field-name constants + entry helpers (optionally
  `--with-custom` to include 二开 fields); output is a git-ignored dev template.

## License

MIT — see [LICENSE](LICENSE).
