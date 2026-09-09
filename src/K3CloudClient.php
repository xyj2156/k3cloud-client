<?php

declare(strict_types=1);

namespace K3Cloud;

use K3Cloud\Auth\AuthStrategy;
use K3Cloud\Auth\SessionAuth;
use K3Cloud\Auth\SignatureAuth;
use K3Cloud\Exception\ApiException;
use K3Cloud\Http\CurlTransport;
use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\Transport;
use K3Cloud\Support\Envelope;

/**
 * High-level client for the K/3 Cloud WebAPI.
 *
 * Create it with one of the named constructors and call an operation; auth and
 * (for password mode) login are handled for you. Both modes share the same
 * fluent options (->insecure(), ->withTimeouts(), ->secure()):
 *
 *   $api = K3CloudClient::appSignature($url, $acctId, $user, $appId, $appSecret);
 *   $api = K3CloudClient::password($url, $acctId, $user, $password)->insecure();
 *
 *   $res  = $api->save('BD_Currency', ['NeedUpDateFields' => [], 'Model' => [...]]);
 *   $rows = $api->query('BD_Currency', ['FCURRENCYID','FNUMBER'])->rows();
 *
 * By default operations return a {@see Result} (they do NOT throw on a business
 * error); chain ->throwIfError() when you want exceptions instead.
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
     * Build the transport for a given config. Overridable seam so a subclass that
     * injects a custom transport can keep it across fluent reconfiguration.
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
     |  Named constructors (lower the barrier to entry)
     * ------------------------------------------------------------------- */

    /**
     * Third-party application signing (app id + app secret). No session/cookies.
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
     * Classic username / password session login.
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
        return $config->authMode === Config::MODE_SESSION
            ? new SessionAuth($config, $transport)
            : new SignatureAuth($config);
    }

    public function config(): Config
    {
        return $this->config;
    }

    /* ---------------------------------------------------------------------
     |  Fluent options — identical for both auth modes.
     |  Each returns a new, same-class client built from an updated immutable
     |  Config. The transport is rebuilt so the new setting takes effect, and the
     |  existing auth is *rebased* onto it: a signature strategy is stateless, and
     |  a session strategy keeps its live kdsessionid (no re-login) as long as the
     |  credential identity is unchanged. So chaining is safe to do at any time.
     * ------------------------------------------------------------------- */

    /** Trust any TLS certificate (self-signed / on-premise installs). */
    public function insecure(): static
    {
        return $this->withConfig($this->config->withTlsVerification(false));
    }

    /** Enforce TLS certificate verification (the default). */
    public function secure(): static
    {
        return $this->withConfig($this->config->withTlsVerification(true));
    }

    /**
     * Set connect / request timeouts (seconds).
     */
    public function withTimeouts(int $connectTimeout, int $requestTimeout): static
    {
        return $this->withConfig($this->config->withTimeouts($connectTimeout, $requestTimeout));
    }

    /**
     * Rebuild a same-class client from a new config, carrying the current session
     * across by rebasing the existing auth onto the freshly built transport.
     */
    protected function withConfig(Config $config): static
    {
        $transport = $this->makeTransport($config);

        return new static($config, $transport, $this->auth->withDependencies($config, $transport));
    }

    /* ---------------------------------------------------------------------
     |  Generic request pipeline
     * ------------------------------------------------------------------- */

    /**
     * Call any DynamicFormService operation by its short name (e.g. "Save").
     *
     * @param list<mixed> $parameters
     */
    public function operation(string $name, array $parameters = []): Result
    {
        return $this->service(self::SERVICE_PREFIX . $name, $parameters);
    }

    /**
     * Call a service by its fully-qualified stub name.
     *
     * @param list<mixed> $parameters
     */
    public function service(string $service, array $parameters = []): Result
    {
        $url = $this->config->serviceUrl($service);
        $body = Envelope::encode($parameters);

        $request = $this->baseRequest('POST', $url, $body);

        $response = $this->transport->send($this->auth->decorate($request));

        // One transparent retry when the strategy says credentials went stale.
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
                // Disable the "Expect: 100-continue" delay on large POST bodies.
                'Expect'       => '',
                'User-Agent'   => 'k3cloud-client-php/1.0 (+https://github.com/xyj2156/k3cloud-client)',
            ],
        );
    }

    protected function toResult(string $body, int $status): Result
    {
        $trimmed = ltrim($body);

        // Gateway/auth failures sometimes come back as plain text.
        if ($trimmed === '') {
            return new Result($body, null, $status);
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return new Result($body, $decoded, $status);
        }

        // Non-JSON: surface it. "response_error:" was the classic signing failure.
        if (str_starts_with($trimmed, 'response_error:') || $status >= 400) {
            throw new ApiException(new Result($body, null, $status), mb_substr($body, 0, 500), $status);
        }

        return new Result($body, $body, $status);
    }

    /* ---------------------------------------------------------------------
     |  Business operations
     * ------------------------------------------------------------------- */

    /**
     * @param array<mixed>|string $model Save payload (typically ["NeedUpDateFields"=>[],"Model"=>[...]]).
     */
    public function save(string $formId, array|string $model): Result
    {
        return $this->operation('Save', [$formId, $model]);
    }

    public function batchSave(string $formId, array|string $data): Result
    {
        return $this->operation('BatchSave', [$formId, $data]);
    }

    public function draft(string $formId, array|string $model): Result
    {
        return $this->operation('Draft', [$formId, $model]);
    }

    public function view(string $formId, array|string $data): Result
    {
        return $this->operation('View', [$formId, $data]);
    }

    /**
     * @param array<mixed>|string $data e.g. ["Numbers"=>["..."], "Ids"=>"", "CreateOrgId"=>0]
     */
    public function submit(string $formId, array|string $data): Result
    {
        return $this->operation('Submit', [$formId, $data]);
    }

    public function audit(string $formId, array|string $data): Result
    {
        return $this->operation('Audit', [$formId, $data]);
    }

    public function unaudit(string $formId, array|string $data): Result
    {
        return $this->operation('UnAudit', [$formId, $data]);
    }

    public function delete(string $formId, array|string $data): Result
    {
        return $this->operation('Delete', [$formId, $data]);
    }

    public function allocate(string $formId, array|string $data): Result
    {
        return $this->operation('Allocate', [$formId, $data]);
    }

    public function cancelAllocate(string $formId, array|string $data): Result
    {
        return $this->operation('CancelAllocate', [$formId, $data]);
    }

    public function cancelAssign(string $formId, array|string $data): Result
    {
        return $this->operation('CancelAssign', [$formId, $data]);
    }

    public function push(string $formId, array|string $data): Result
    {
        return $this->operation('Push', [$formId, $data]);
    }

    public function groupSave(string $formId, array|string $data): Result
    {
        return $this->operation('GroupSave', [$formId, $data]);
    }

    public function disassembly(string $formId, array|string $data): Result
    {
        return $this->operation('Disassembly', [$formId, $data]);
    }

    public function flexSave(string $formId, array|string $data): Result
    {
        return $this->operation('FlexSave', [$formId, $data]);
    }

    public function getSysReportData(string $formId, array|string $data): Result
    {
        return $this->operation('GetSysReportData', [$formId, $data]);
    }

    /**
     * Execute an arbitrary operation code against a form.
     */
    public function executeOperation(string $formId, string $operation, array|string $data): Result
    {
        return $this->operation('ExcuteOperation', [$formId, $operation, $data]);
    }

    /**
     * Raw single-parameter bill query (JSON body). Accepts an array or string.
     */
    public function executeBillQuery(array|string $data): Result
    {
        return $this->operation('ExecuteBillQuery', [$data]);
    }

    /**
     * Newer bill query (V7.4+). Accepts an array or JSON string.
     */
    public function billQuery(array|string $data): Result
    {
        return $this->operation('BillQuery', [$data]);
    }

    /**
     * Ergonomic wrapper over ExecuteBillQuery.
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

    public function queryBusinessInfo(array|string $data): Result
    {
        return $this->operation('QueryBusinessInfo', [$data]);
    }

    public function queryGroupInfo(array|string $data): Result
    {
        return $this->operation('QueryGroupInfo', [$data]);
    }

    public function workflowAudit(array|string $data): Result
    {
        return $this->operation('WorkflowAudit', [$data]);
    }

    public function groupDelete(array|string $data): Result
    {
        return $this->operation('GroupDelete', [$data]);
    }

    public function switchOrg(array|string $data): Result
    {
        return $this->operation('SwitchOrg', [$data]);
    }

    public function sendMsg(array|string $data): Result
    {
        return $this->operation('SendMsg', [$data]);
    }

    public function attachmentUpload(array|string $data): Result
    {
        return $this->operation('AttachmentUpload', [$data]);
    }

    public function attachmentDownload(array|string $data): Result
    {
        return $this->operation('AttachmentDownLoad', [$data]);
    }

    /**
     * Force (password-mode) re-authentication before the next request.
     */
    public function relogin(): void
    {
        if ($this->auth instanceof SessionAuth) {
            $this->auth->invalidate();
        }
    }
}
