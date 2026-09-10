<?php

declare(strict_types=1);

namespace K3Cloud;

use K3Cloud\Auth\AuthStrategy;
use K3Cloud\Auth\FileSessionStore;
use K3Cloud\Auth\NullSessionStore;
use K3Cloud\Auth\SessionAuth;
use K3Cloud\Auth\SessionStore;
use K3Cloud\Auth\SignatureAuth;
use K3Cloud\Exception\ApiException;
use K3Cloud\Http\CurlTransport;
use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\Transport;
use K3Cloud\Support\Envelope;

/**
 * K/3 Cloud WebAPI 的高层客户端。
 *
 * 用任一具名构造器创建后即可调用操作；鉴权与（密码模式下的）登录都自动完成。两种模式共用
 * 同一套链式选项（->insecure()、->withTimeouts()、->secure()）：
 *
 *   $api = K3CloudClient::appSignature($url, $acctId, $user, $appId, $appSecret);
 *   $api = K3CloudClient::password($url, $acctId, $user, $password)->insecure();
 *
 *   $res  = $api->save('BD_Currency', ['NeedUpDateFields' => [], 'Model' => [...]]);
 *   $rows = $api->query('BD_Currency', ['FCURRENCYID','FNUMBER'])->rows();
 *
 * 默认情况下操作返回 {@see Result}（业务错误不抛异常）；想要异常就在链尾加 ->throwIfError()。
 *
 * 密码模式的会话（kdsessionid）默认落盘到系统临时目录，短生命周期进程可跨次运行复用登录，
 * 失效时自动重登；位置可改（->withSessionStorePath()）也可关（->withoutSessionPersistence()）。
 */
class K3CloudClient
{
    private const SERVICE_PREFIX = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.';

    protected Config $config;

    protected Transport $transport;

    protected AuthStrategy $auth;

    final public function __construct(Config $config, ?Transport $transport = null, ?AuthStrategy $auth = null)
    {
        $this->config = $config;
        $this->transport = $transport ?? $this->makeTransport($config);
        $this->auth = $auth ?? $this->defaultAuth($config, $this->transport);
    }

    /**
     * 为给定配置构建传输层。可覆盖的接缝：注入了自定义传输层的子类，可在链式重配置时保留它。
     */
    protected function makeTransport(Config $config): Transport
    {
        return new CurlTransport(
            $config->connectTimeout,
            $config->requestTimeout,
            $config->verifyTls,
        );
    }

    /* ---------------------------------------------------------------------
     |  具名构造器（降低上手门槛）
     * ------------------------------------------------------------------- */

    /**
     * 第三方应用签名（AppID + AppSecret）。不使用会话/Cookie。
     */
    public static function appSignature(
        string $serverUrl,
        string $acctId,
        string $userName,
        string $appId,
        string $appSecret,
        int $lcid = 2052,
        int $orgNum = 0,
    ): static {
        return new static(Config::appSignature($serverUrl, $acctId, $userName, $appId, $appSecret, $lcid, $orgNum));
    }

    /**
     * 经典用户名 / 密码会话登录。
     */
    public static function password(
        string $serverUrl,
        string $acctId,
        string $userName,
        string $password,
        int $lcid = 2052,
        int $orgNum = 0,
    ): static {
        return new static(Config::password($serverUrl, $acctId, $userName, $password, $lcid, $orgNum));
    }

    protected function defaultAuth(Config $config, Transport $transport): AuthStrategy
    {
        // 密码模式默认开启会话落盘：kdsessionid 写入 FileSessionStore（见 withSessionStore），
        // 短生命周期进程由此免去每进程一次的 ValidateUser 登录。
        if ($config->authMode !== Config::MODE_SESSION) {
            return new SignatureAuth($config);
        }

        return new SessionAuth($config, $transport, new FileSessionStore());
    }

    public function config(): Config
    {
        return $this->config;
    }

    /* ---------------------------------------------------------------------
     |  实体 / 单据构建器
     * ------------------------------------------------------------------- */

    /**
     * 以实体编码开始构建表单载荷（schema-free）。
     *
     * 若该 formId 有开发者注册的 Entity 子类（见 {@see Entity::register()}）则返回它，
     * 否则返回通用基类 {@see Entity}。未知 id 从不报错——基类接受任意字段。
     *
     * @param array<string,mixed> $initial 完整数据载荷，或裸 Model 字典
     */
    public function bill(string $formId, array $initial = []): Entity
    {
        $class = Entity::resolve($formId);

        return $class !== null
            ? $class::for($this, $initial)
            : new Entity($this, $formId, $initial);
    }

    /**
     * 以带类型的实体类开始构建，使返回值就是具体子类（PHPStan / Intelephense 从 ::class 实参推断 T）。
     *
     * @template T of Entity
     *
     * @param class-string<T>     $class
     * @param array<string,mixed> $initial
     *
     * @return T
     */
    public function entity(string $class, array $initial = []): Entity
    {
        return $class::for($this, $initial);
    }

    /* ---------------------------------------------------------------------
     |  链式选项——两种认证方式完全一致。
     |  每个都返回一个由更新后的不可变 Config 构造的同类新客户端。传输层会重建以使新设置生效，
     |  并把现有鉴权*重绑定*到其上：签名策略无状态，会话策略在凭据身份未变时保留其活动
     |  kdsessionid（不重新登录）。因此随时链式调用都安全。
     * ------------------------------------------------------------------- */

    /** 信任任意 TLS 证书（自签 / 本地部署）。 */
    public function insecure(): static
    {
        return $this->withConfig($this->config->withTlsVerification(false));
    }

    /** 强制 TLS 证书校验（默认）。 */
    public function secure(): static
    {
        return $this->withConfig($this->config->withTlsVerification(true));
    }

    /**
     * 设置连接 / 请求超时（秒）。
     */
    public function withTimeouts(int $connectTimeout, int $requestTimeout): static
    {
        return $this->withConfig($this->config->withTimeouts($connectTimeout, $requestTimeout));
    }

    /**
     * 更换密码模式下的会话持久化存储（默认：{@see FileSessionStore}，写到系统临时目录）。
     * 传入任何 {@see SessionStore} 实现（Redis、数据库、自建缓存……）即可替换落盘方式；
     * 活动会话会带到新存储上。签名模式无会话概念，调用为无害的空操作。
     */
    public function withSessionStore(SessionStore $store): static
    {
        $auth = $this->auth instanceof SessionAuth
            ? $this->auth->withStore($store)
            : $this->auth;

        return new static($this->config, $this->transport, $auth);
    }

    /**
     * 快捷方式：会话文件改存到指定目录（仍为默认的文件存储）。目录需对运行用户可写。
     */
    public function withSessionStorePath(string $dir): static
    {
        return $this->withSessionStore(new FileSessionStore($dir));
    }

    /**
     * 关闭会话落盘：kdsessionid 只保留在当前进程内存中，每个新进程都会重新登录一次
     * （即引入持久化之前的行为）。
     */
    public function withoutSessionPersistence(): static
    {
        return $this->withSessionStore(new NullSessionStore());
    }

    /**
     * 由新配置重建同类客户端：重建传输层，并把现有鉴权重绑定到新传输层，从而带过当前会话。
     */
    protected function withConfig(Config $config): static
    {
        $transport = $this->makeTransport($config);

        return new static($config, $transport, $this->auth->withDependencies($config, $transport));
    }

    /* ---------------------------------------------------------------------
     |  通用请求管线
     * ------------------------------------------------------------------- */

    /**
     * 以短名（如 "Save"）调用任意 DynamicFormService 操作。
     *
     * @param list<mixed> $parameters
     */
    public function operation(string $name, array $parameters = []): Result
    {
        return $this->service(self::SERVICE_PREFIX . $name, $parameters);
    }

    /**
     * 以服务全限定 stub 名调用。
     *
     * @param list<mixed> $parameters
     */
    public function service(string $service, array $parameters = []): Result
    {
        $url = $this->config->serviceUrl($service);
        $body = Envelope::encode($parameters);

        $request = $this->baseRequest('POST', $url, $body);

        $response = $this->transport->send($this->auth->decorate($request));

        // 当策略判定凭据已失效时，做一次透明重试。
        if ($this->auth->shouldRetry($request, $response)) {
            $response = $this->transport->send($this->auth->decorate($request));
        }

        return $this->toResult($response->body, $response->status);
    }

    protected function baseRequest(string $method, string $url, string $body): HttpRequest
    {
        return new HttpRequest(
            method: $method,
            url: $url,
            body: $body,
            headers: [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept'       => 'application/json',
                'Connection'   => 'keep-alive',
                // 关闭大 POST 体上的 "Expect: 100-continue" 延迟。
                'Expect'       => '',
                'User-Agent'   => 'k3cloud-client-php/1.0 (+https://github.com/xyj2156/k3cloud-client)',
            ],
        );
    }

    protected function toResult(string $body, int $status): Result
    {
        $trimmed = ltrim($body);

        // 网关/鉴权失败有时以纯文本返回。
        if ($trimmed === '') {
            return new Result($body, null, $status);
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return new Result($body, $decoded, $status);
        }

        // 非 JSON：直接抛出。"response_error:" 是经典的签名失败前缀。
        if (str_starts_with($trimmed, 'response_error:') || $status >= 400) {
            throw new ApiException(new Result($body, null, $status), mb_substr($body, 0, 500), $status);
        }

        return new Result($body, $body, $status);
    }

    /* ---------------------------------------------------------------------
     |  业务操作
     * ------------------------------------------------------------------- */

    /**
     * @param array<mixed>|string $model Save 载荷（典型为 ["NeedUpDateFields"=>[],"Model"=>[...]]）。
     */
    public function save(string $formId, array|string $model): Result
    {
        return $this->operation('Save', [$formId, $model]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function batchSave(string $formId, array|string $data): Result
    {
        return $this->operation('BatchSave', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $model 业务数据载荷，原样进入信封 parameters
     */
    public function draft(string $formId, array|string $model): Result
    {
        return $this->operation('Draft', [$formId, $model]);
    }

    /**
     * 读取单条记录。$data 常用键：Number / Id / EntryIds / InterationFlags。
     *
     * @param array{Number?:string,Id?:string,EntryIds?:string,InterationFlags?:string}|string $data
     */
    public function view(string $formId, array|string $data): Result
    {
        return $this->operation('View', [$formId, $data]);
    }

    /**
     * 提交（Submit）。标识族载荷——不是 Model。列出常用键以便编辑器补全；未知键仍原样透传。
     *
     * @param array{Numbers?:list<string>,Ids?:string,CreateOrgId?:int,InterationFlags?:string,IgnoreInterationFlag?:bool|string,NetworkControl?:bool|string,UseBatControlTransform?:bool|string}|string $data
     */
    public function submit(string $formId, array|string $data): Result
    {
        return $this->operation('Submit', [$formId, $data]);
    }

    /**
     * 审核（Audit）。
     *
     * @param array{Numbers?:list<string>,Ids?:string,CreateOrgId?:int,InterationFlags?:string,IgnoreInterationFlag?:bool|string,NetworkControl?:bool|string,UseBatControlTransform?:bool|string}|string $data
     */
    public function audit(string $formId, array|string $data): Result
    {
        return $this->operation('Audit', [$formId, $data]);
    }

    /**
     * 反审核（UnAudit）。
     *
     * @param array{Numbers?:list<string>,Ids?:string,CreateOrgId?:int,InterationFlags?:string,IgnoreInterationFlag?:bool|string,NetworkControl?:bool|string}|string $data
     */
    public function unaudit(string $formId, array|string $data): Result
    {
        return $this->operation('UnAudit', [$formId, $data]);
    }

    /**
     * 删除（Delete）。
     *
     * @param array{Numbers?:list<string>,Ids?:string,CreateOrgId?:int,InterationFlags?:string,IgnoreInterationFlag?:bool|string}|string $data
     */
    public function delete(string $formId, array|string $data): Result
    {
        return $this->operation('Delete', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function allocate(string $formId, array|string $data): Result
    {
        return $this->operation('Allocate', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function cancelAllocate(string $formId, array|string $data): Result
    {
        return $this->operation('CancelAllocate', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function cancelAssign(string $formId, array|string $data): Result
    {
        return $this->operation('CancelAssign', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function push(string $formId, array|string $data): Result
    {
        return $this->operation('Push', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function groupSave(string $formId, array|string $data): Result
    {
        return $this->operation('GroupSave', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function disassembly(string $formId, array|string $data): Result
    {
        return $this->operation('Disassembly', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function flexSave(string $formId, array|string $data): Result
    {
        return $this->operation('FlexSave', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function getSysReportData(string $formId, array|string $data): Result
    {
        return $this->operation('GetSysReportData', [$formId, $data]);
    }

    /**
     * 对某个单据执行任意操作编码。
     *
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function executeOperation(string $formId, string $operation, array|string $data): Result
    {
        return $this->operation('ExcuteOperation', [$formId, $operation, $data]);
    }

    /**
     * 原始的单参数单据查询（JSON 体）。接受数组或字符串。
     *
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function executeBillQuery(array|string $data): Result
    {
        return $this->operation('ExecuteBillQuery', [$data]);
    }

    /**
     * 较新的单据查询（V7.4+）。接受数组或 JSON 字符串。
     *
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function billQuery(array|string $data): Result
    {
        return $this->operation('BillQuery', [$data]);
    }

    /**
     * ExecuteBillQuery 的便捷封装。
     *
     *   $api->query('SAL_SaleOrder', ['FSBILLNO','FTOTALAMOUNT'], [
     *       'FilterString' => "FDate >= '2024-01-01'",
     *       'OrderString'  => 'FDate DESC',
     *       'TopRowCount'  => 100,
     *   ]);
     *
     * @param list<string>                $fieldKeys
     * @param array<string,mixed>         $options
     */
    public function query(string $formId, array $fieldKeys, array $options = []): Result
    {
        $data = array_merge([
            'FormId'      => $formId,
            'FieldKeys'   => implode(',', $fieldKeys),
            'FilterString'=> '',
            'OrderString' => '',
            'TopRowCount' => 0,
            'StartRow'    => 0,
            'Limit'       => 2000,
        ], $options);

        return $this->executeBillQuery($data);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function queryBusinessInfo(array|string $data): Result
    {
        return $this->operation('QueryBusinessInfo', [$data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function queryGroupInfo(array|string $data): Result
    {
        return $this->operation('QueryGroupInfo', [$data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function workflowAudit(array|string $data): Result
    {
        return $this->operation('WorkflowAudit', [$data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function groupDelete(array|string $data): Result
    {
        return $this->operation('GroupDelete', [$data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function switchOrg(array|string $data): Result
    {
        return $this->operation('SwitchOrg', [$data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function sendMsg(array|string $data): Result
    {
        return $this->operation('SendMsg', [$data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function attachmentUpload(array|string $data): Result
    {
        return $this->operation('AttachmentUpload', [$data]);
    }

    /**
     * @param array<mixed>|string $data 业务数据载荷，原样进入信封 parameters
     */
    public function attachmentDownload(array|string $data): Result
    {
        return $this->operation('AttachmentDownLoad', [$data]);
    }

    /**
     * 强制（密码模式下）在下一个请求前重新鉴权。
     */
    public function relogin(): void
    {
        if ($this->auth instanceof SessionAuth) {
            $this->auth->invalidate();
        }
    }
}
