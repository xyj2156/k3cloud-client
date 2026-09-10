<?php

declare(strict_types=1);

namespace K3Cloud\Auth;

use K3Cloud\Config;
use K3Cloud\Exception\AuthException;
use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\HttpResponse;
use K3Cloud\Http\Transport;
use K3Cloud\Support\CookieJar;
use K3Cloud\Support\Envelope;

/**
 * 经典用户名 / 密码鉴权。
 *
 * 针对公开的 "AuthService.ValidateUser" 端点做一次登录，捕获其返回的 "kdsessionid"
 * （及随附）Cookie，并在其后每个请求上重放。登录是惰性的：发生在第一个被装饰的请求上，
 * 对调用方透明。当某响应表明会话已过期时，丢弃该状态，使重试会重新鉴权。
 *
 * 会话可经 {@see SessionStore} 跨进程持久化（K3CloudClient 默认使用 FileSessionStore）：
 * 构造时按当前凭据指纹水合已落盘的会话；之后的"会话丢失 → 失效 → 重登 → 重放"链路
 * 原样负责自愈，失效条目会在重登时被覆盖 / 删除。直接 new 本类且未传 store 时不持久化，
 * 行为与会话仅存内存的实现一致。
 *
 * 关于登录载荷：参数顺序遵循常用的 [acctId, userName, password, lcid]。少数服务端版本
 * 期望不同的参数列表；若你的服务端拒绝登录，请覆盖 {@see self::loginParameters()}（子类）
 * ——其余代码无需改动。
 */
class SessionAuth implements AuthStrategy
{
    private const VALIDATE_USER_SERVICE = 'Kingdee.BOS.WebApi.ServicesStub.AuthService.ValidateUser';

    private CookieJar $cookies;

    private readonly SessionStore $store;

    private bool $loggedIn = false;

    /**
     * 当前会话所依据的"含凭据配置"的哈希，使重绑定能区分"重配置"（保留会话）与
     * "换登录"（丢弃会话）。未登录时为 null。
     */
    private ?string $establishedFor = null;

    /**
     * 构造函数签名对子类锁死（final）——扩展点是覆盖 loginParameters()，不是改构造参数。
     */
    final public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        ?SessionStore $store = null,
    ) {
        $this->cookies = new CookieJar();
        $this->store = $store ?? new NullSessionStore();
        $this->restore();
    }

    /**
     * 从存储中按当前凭据指纹水合会话。读到坏条目（缺失 / 损坏 / 形状不符）时保持
     * 未登录——那只会导致下一次请求照常登录一次。
     */
    private function restore(): void
    {
        $data = $this->store->load(self::identityKey($this->config));
        if ($data === null) {
            return;
        }

        $this->cookies = CookieJar::fromArray($data['cookies']);
        $this->loggedIn = true;
        $this->establishedFor = self::identityKey($this->config);
    }

    /**
     * 返回一个换用给定持久化存储的策略副本；当前活动会话（若身份未变）原样带过去。
     */
    public function withStore(SessionStore $store): self
    {
        $next = new static($this->config, $this->transport, $store);
        $next->adoptSession($this);

        return $next;
    }

    public function withDependencies(Config $config, Transport $transport): AuthStrategy
    {
        // 存储随策略走；新身份若有落盘会话，构造时的 restore() 已水合。
        $next = new static($config, $transport, $this->store);
        $next->adoptSession($this);

        return $next;
    }

    /**
     * 仅当产生旧会话的身份与自身配置一致时，把旧实例的内存会话带过来（覆盖之）。
     */
    private function adoptSession(self $previous): void
    {
        if ($previous->loggedIn && $previous->establishedFor === self::identityKey($this->config)) {
            $this->loggedIn = true;
            $this->establishedFor = $previous->establishedFor;
            $this->cookies = clone $previous->cookies;
        }
    }

    public function decorate(HttpRequest $request): HttpRequest
    {
        if (!$this->loggedIn) {
            $this->login();
        }

        foreach ($this->cookies->all() as $name => $value) {
            $request = $request->withCookie($name, $value);
        }

        return $request;
    }

    public function shouldRetry(HttpRequest $request, HttpResponse $response): bool
    {
        if (!$this->loggedIn || !$this->sessionExpired($response)) {
            return false;
        }

        $this->invalidate();

        return true;
    }

    /**
     * 判断某响应是否意味着"会话已丢失"，从而值得做一次"重登录 + 重放"。
     *
     * 我们匹配抽取出的错误*消息文本*，而非整个响应体（一个成功响应可能在用户数据里合法地
     * 包含这些词）。例如会话丢失的 Save 会返回 HTTP 200 + IsSuccess=false，且
     * Errors[].Message = "会话信息已丢失，请重新登录"。
     */
    private function sessionExpired(HttpResponse $response): bool
    {
        if ($response->status === 401 || $response->status === 403) {
            return true;
        }

        foreach (self::errorMessages($response->body) as $message) {
            foreach (self::EXPIRY_MESSAGE_PATTERNS as $pattern) {
                if (preg_match($pattern, $message) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 从 K/3 Cloud 响应体中收集人类可读的错误文本。
     *
     * @return list<string> 对格式良好的成功 JSON 响应返回空数组
     */
    private static function errorMessages(string $body): array
    {
        $json = json_decode($body, true);

        // 非 JSON（如纯文本网关错误）：回退到原始响应体。
        if (!is_array($json)) {
            return [$body];
        }

        $messages = [];

        $status = $json['Result']['ResponseStatus'] ?? null;
        if (is_array($status)) {
            foreach ($status['Errors'] ?? [] as $error) {
                if (is_array($error) && isset($error['Message'])) {
                    $messages[] = (string) $error['Message'];
                }
            }
            foreach (['Message', 'Result'] as $key) {
                if (is_string($status[$key] ?? null)) {
                    $messages[] = $status[$key];
                }
            }
        }

        // 有些版本把错误以纯字符串放在 Result，或放在顶层。
        foreach (['Message', 'description', 'Result'] as $key) {
            if (is_string($json[$key] ?? null)) {
                $messages[] = $json[$key];
            }
        }

        return $messages;
    }

    /**
     * 强制下一个请求重新登录（例如切换组织之后）。同时丢弃落盘条目，
     * 避免把已失效的会话 id 再交给下一个进程。
     */
    public function invalidate(): void
    {
        if ($this->establishedFor !== null) {
            $this->store->forget($this->establishedFor);
        }

        $this->loggedIn = false;
        $this->establishedFor = null;
        $this->cookies->clear();
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    private function login(): void
    {
        $url = $this->config->serviceUrl(self::VALIDATE_USER_SERVICE);
        $request = new HttpRequest(
            method: 'POST',
            url: $url,
            body: Envelope::encode($this->loginParameters()),
            headers: [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Accept'       => 'application/json',
            ],
        );

        $response = $this->transport->send($request);
        $payload = json_decode($response->body, true);

        if (!is_array($payload)) {
            throw new AuthException(sprintf(
                'Login returned a non-JSON response (HTTP %d): %s',
                $response->status,
                mb_substr($response->body, 0, 300)
            ));
        }

        // LoginResultType === 1 在 K/3 Cloud 表示登录成功。
        $resultType = $payload['LoginResultType'] ?? null;
        if ($resultType !== null && (int) $resultType !== 1) {
            throw new AuthException(
                (string) ($payload['Message'] ?? $payload['Result'] ?? 'Login failed (LoginResultType=' . $resultType . ')')
            );
        }

        if (!$response->isSuccessful() && $resultType === null) {
            throw new AuthException('Login endpoint returned HTTP ' . $response->status . ' without a session.');
        }

        foreach ($response->headerAll('set-cookie') as $setCookie) {
            $this->cookies->storeFromSetCookie($setCookie);
        }

        if (!$this->cookies->has('kdsessionid') && empty($payload['KDSVCSessionId'])) {
            throw new AuthException('Login succeeded but no kdsessionid cookie was returned.');
        }

        $this->loggedIn = true;
        $this->establishedFor = self::identityKey($this->config);

        // 新会话立即落盘：下一个进程（cron / 队列 / 再起的 Web 请求）可直接重放，
        // 免去一次 ValidateUser 往返。落盘失败仅退化为"本进程内复用"，不影响本次调用。
        $this->store->save($this->establishedFor, $this->cookies->all());
    }

    /**
     * 会话所归属的"含凭据配置字段"的指纹。TLS/超时变化被有意排除（不影响"是谁登录"）；
     * 身份变化则不排除。
     */
    private static function identityKey(Config $c): string
    {
        return sha1($c->serverUrl . '|' . $c->acctId . '|' . $c->userName . '|' . $c->password . '|' . $c->lcid);
    }

    /**
     * @return list<mixed>
     */
    protected function loginParameters(): array
    {
        return [
            $this->config->acctId,
            $this->config->userName,
            $this->config->password,
            $this->config->lcid,
        ];
    }

    /**
     * 与抽取出的错误消息文本匹配的 PCRE（UTF-8）模式，用于判定会话丢失/过期。覆盖 K/3 Cloud
     * 实际返回的措辞，如"会话信息已丢失，请重新登录"。有意限定在消息范围内，普通数据不会误触发。
     */
    private const EXPIRY_MESSAGE_PATTERNS = [
        '/会话信息已丢失/u',
        '/会话.{0,6}(失效|过期|超时|丢失|异常|不存在)/u',
        '/(请)?重新登录/u',
        '/未登录/u',
        '/登录.{0,6}(失效|过期|异常|超时)/u',
        '/session.{0,12}(expired|invalid|lost)/i',
    ];
}
