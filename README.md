**简体中文** | [English](README_en.md)

# k3cloud-client —— 面向金蝶云（K/3 Cloud）WebAPI 的非官方 PHP 客户端

一个轻量、无第三方依赖、**独立编写**的 K/3 Cloud WebAPI 客户端。它以"快速上手"为核心，
支持你在真实环境里会遇到的**两种认证方式**：

- **第三方应用签名** —— AppID + AppSecret，按请求计算 `X-Kd-*` / `X-Api-*` HMAC 头，
  不使用服务端会话。常见于公有云网关。
- **用户名 / 密码会话** —— 经典的 `AuthService.ValidateUser` 登录 + `kdsessionid` Cookie，
  惰性、透明地自动完成。常见于未下发 AppID 的私有云 / 本地部署。

> **非官方。** 这是针对公开的 K/3 Cloud HTTP 接口做的 clean-room 独立实现，与任何金蝶
> 官方 SDK 无隶属、认可或衍生关系，且不含任何官方 SDK 代码。“金蝶 / Kingdee”“K/3 Cloud”
> 为其权利人所有，这里仅用于描述本客户端所对接的接口。

---

## 安装

```bash
composer require xyj2156/k3cloud-client
```

需要 PHP 8.1+ 与 `ext-curl`。

## 快速上手

### 方式 A —— 第三方应用签名

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

### 方式 B —— 用户名 / 密码会话

```php
use K3Cloud\K3CloudClient;

$api = K3CloudClient::password(
    serverUrl: 'https://192.168.1.100:8080/K3Cloud',
    acctId:    '62f3c9b0xxxxxxxx',
    userName:  'administrator',
    password:  'your-password',
)->insecure();   // 仅自签证书 / 本地部署需要

$created = $api->save('BD_Currency', [
    'NeedUpDateFields' => [],
    'IsDeleteEntry'    => 'true',
    'Model'            => ['FNAME' => '测试币种', 'FNUMBER' => 'TESTCUR', 'FPRECISION' => 2],
])->throwIfError();

echo $created->id(), ' / ', $created->number(), "\n";
```

两种方式的创建完全一致 —— 都是一个"返回客户端"的具名构造器。选项链式写法也与认证方式无关：

```php
$api = K3CloudClient::password($url, $acct, $user, $pwd)
    ->insecure()               // 信任自签证书
    ->withTimeouts(10, 60);    // 连接 / 请求超时，单位秒
```

链式选项随时可调用：每个都返回同类新客户端，传输层重建以使设置生效，并把当前鉴权"搬迁"过去。
因此在凭据不变的前提下，用户名/密码会话可跨一次重配置存活（不会二次登录）——登录仍然**至多一次、
惰性、发生在第一个请求**。只有更换凭据（或调用 `->relogin()`）才会触发一次新的认证。

### 进阶：直接构造 `Config`

如果你需要在创建客户端之前先组装 / 持久化配置，可显式使用 `K3Cloud\Config` 再注入 ——
与具名构造器等价：

```php
use K3Cloud\Config;
use K3Cloud\K3CloudClient;

$api = new K3CloudClient(
    Config::appSignature($url, $acct, $user, $appId, $appSecret)->withTlsVerification(true)
);
```

上手就这么简单：创建客户端、调用操作。登录与签名都自动完成。

## 返回值

每个操作都返回一个 `K3Cloud\Result`：

| 方法                | 含义 |
|---------------------|------|
| `->payload()`       | 解码后的响应体（数组 / 标量） |
| `->rows()`          | 查询响应的行列表 |
| `->isSuccess()`     | 业务是否成功 |
| `->errorMessage()`  | 首条错误信息，或 `null` |
| `->id()` / `->number()` | Save 返回的新单内码 / 编码 |
| `->throwIfError()`  | 不成功则抛 `ApiException` |

默认情况下，业务错误**不抛异常**——想要异常就在链尾加 `->throwIfError()`。传输 / 鉴权失败则会抛
（`TransportException`、`AuthException`）。

## 操作一览

`save`、`batchSave`、`draft`、`view`、`submit`、`audit`、`unaudit`、`delete`、
`allocate`、`cancelAllocate`、`cancelAssign`、`push`、`groupSave`、`disassembly`、
`flexSave`、`getSysReportData`、`executeOperation`、`executeBillQuery`、
`billQuery`、`query`、`queryBusinessInfo`、`queryGroupInfo`、`workflowAudit`、
`groupDelete`、`switchOrg`、`sendMsg`、`attachmentUpload`、`attachmentDownload`。

未列出的仍可通过通用入口调用：

```php
$api->operation('AttachmentDownLoad', [ $payload ]);      // 短名
$api->service('Kingdee.BOS.WebApi.ServicesStub.Some.Service', [ ...$params ]);
```

### `query()` 便捷方法

```php
$result = $api->query('SAL_SaleOrder', ['FSBILLNO', 'FTOTALAMOUNT'], [
    'FilterString' => "FDate >= '2024-01-01'",
    'OrderString'  => 'FDate DESC',
    'TopRowCount'  => 100,
]);
```

## 流式构建单据

不用手写嵌套的 `Model`，用构建器。基类 `Entity` 是 schema-free 的——任意实体、任意二开 /
扩展字段无需 SDK 支持即可使用；你也可以为某个实体写子类，获得带类型的 helper 与 IDE 补全。

```php
use K3Cloud\K3CloudClient;

// 通用（无需类）——返回基类 Entity：
$api->bill('SAL_SaleOrder')
    ->ref('FBillTypeID', 'XSDD01_SYS', 'FNUMBER')   // 基础资料引用
    ->ref('FCustId', '16.195.06.0001')
    ->custom('F_PEYC_Decimal', '1710')              // 二开字段，无需 schema
    ->line('FSaleOrderEntry', fn($row) => $row
        ->ref('FMaterialId', '02.04.03.089')
        ->set('FQty', '1.000'))
    ->package(['FSaleOrderFinance' => ['FSettleCurrId' => ['FNumber' => 'PRE001']]])
    ->save()                                        // -> Result
    ->throwIfError();
```

或用你自己的子类（类型补全，`: static` 让具体类型贯穿链式调用）：

```php
use K3Cloud\Entity;

final class SaleOrder extends Entity
{
    public const FORM_ID = 'SAL_SaleOrder';
    public function customer(string $n): static { return $this->ref('FCustId', $n); }
}

$order = $api->entity(SaleOrder::class);   // 带类型：$order 就是 SaleOrder
// 或类静态工厂：$order = SaleOrder::for($api);
$order->customer('16.195.06.0001')->save();

// 可选便利：注册后，字符串入口也能自动解析到子类：
Entity::register(SaleOrder::FORM_ID, SaleOrder::class);
$api->bill('SAL_SaleOrder');               // → SaleOrder
```

mutator 语义：`set/ref/custom` 覆盖某个字段；`line()` 是唯一的追加入口；
`package()` / 终端 `$patch` 对 Model 递归深合并（列表值整体替换）；`control()` /
`mergePayload()` 处理顶层控制位；`save()/draft()/call()` 是返回 `Result` 的终端。
设计依据见 [`docs/design-bill-builder.md`](docs/design-bill-builder.md)。

## 配置说明

- **`serverUrl`** —— WebAPI 根地址，如 `https://host:port/K3Cloud`（私有云）或
  `https://api.kingdee.com/galaxyapi/`（公有云）。结尾斜杠可有可无。
- **TLS** —— 默认**开启**校验。自签 / 本地部署请在客户端上调用 `->insecure()`
  （或 `Config::withoutTlsVerification()`）。
- **超时** —— 客户端 `->withTimeouts($connect, $request)`，或直接构造 `Config` 时用
  `Config::withTimeouts(...)`。

### 登录参数兼容性

密码模式以常见的 `[acctId, userName, password, lcid]` 顺序调用 `AuthService.ValidateUser`。
少数版本期望不同的参数列表。若你的服务端拒绝登录，请继承 `SessionAuth` 并覆盖 `loginParameters()`
——其余代码无需改动。

## 开发

```bash
composer install
composer test        # 运行 PHPUnit
```

示例在 `examples/`，填入凭据后即可运行。

要（重新）构建元数据层使用的清洗后实体/字段引用，把一份新的 K/3 Cloud 导出放到被 git 忽略的位置
（如 `.docs/raw/`）后运行：

```bash
php tools/build-metadata.php [.docs/raw/entity.json] [.docs/raw/field.json] [.docs]
```

加 `--scaffold` 会额外把"每个实体的起始 PHP 类"（FORM_ID + 字段名常量 + 分录 helper）生成到被
git 忽略的 `.docs/scaffold/`——供你拷进自己工程再编辑。`--with-custom` 会把二开字段也作为常量并进去；
`--namespace=...` 指定生成的命名空间。这些都不是白名单——未知字段仍可走 `->custom()/->package()`。

```bash
php tools/build-metadata.php ... --scaffold --with-custom --namespace=App\\K3Cloud\\Entities
```

只有清洗后的产物（`kingdee_field.standard.json`、`kingdee_field.custom.json`、报告）会被跟踪；
原始导出永不入库。

## 路线图

- **流式单据构建器** —— *已交付*（`Entity`/`Line`、`->bill()/->entity()`、带类型子类、具名终端、
  `->call()`、合并语义、标识族操作的 `@param` 补全）。设计见
  [`docs/design-bill-builder.md`](docs/design-bill-builder.md)。
- **元数据脚手架** —— *已交付*：`build-metadata.php --scaffold` 生成每个实体的起始类 + 字段名常量 +
  分录 helper（可选 `--with-custom` 并入二开字段）；产物为被 git 忽略的开发模板。

## 许可

MIT —— 见 [LICENSE](LICENSE)。
