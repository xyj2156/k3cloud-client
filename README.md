# k3cloud-client — an unofficial PHP client for the Kingdee Cloud (K/3 Cloud) WebAPI

A small, dependency-light, **independently written** client for the K/3 Cloud
WebAPI. It focuses on getting you productive fast and supports the **two real
authentication modes** you will meet in the wild:

- **Third-party app signing** — AppID + AppSecret, per-request `X-Kd-*` / `X-Api-*`
  HMAC headers, no server session. Typical for the public-cloud gateway.
- **Username / password session** — classic `AuthService.ValidateUser` login and
  a `kdsessionid` cookie, handled lazily and transparently. Typical for private /
  on-premise installs where no AppID was issued.

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
use K3Cloud\Config;
use K3Cloud\K3CloudClient;

$api = new K3CloudClient(
    Config::password('https://192.168.1.100:8080/K3Cloud', '62f3...','administrator','pwd')
        ->withoutTlsVerification()   // only for self-signed certs
);

$created = $api->save('BD_Currency', [
    'NeedUpDateFields' => [],
    'IsDeleteEntry'    => 'true',
    'Model'            => ['FNAME' => '测试币种', 'FNUMBER' => 'TESTCUR', 'FPRECISION' => 2],
])->throwIfError();

echo $created->id(), ' / ', $created->number(), "\n";
```

That is the whole onboarding story: create the client, call an operation. Login
and signing are automatic.

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

## Configuration notes

- **`serverUrl`** — the WebAPI root, e.g. `https://host:port/K3Cloud` (private)
  or `https://api.kingdee.com/galaxyapi/` (public). A trailing slash is optional.
- **TLS** — verification is **on by default**. Call
  `->withoutTlsVerification()` for self-signed / private installs.
- **Timeouts** — `Config::withTimeouts($connect, $request)`.

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

## License

MIT — see [LICENSE](LICENSE).
