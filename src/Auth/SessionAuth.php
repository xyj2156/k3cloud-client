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
 * Classic username / password authentication.
 *
 * Performs a one-time login against the public
 * "AuthService.ValidateUser" endpoint, captures the resulting "kdsessionid"
 * (and any accompanying) cookies, and replays them on every subsequent request.
 * Login is lazy: it happens on the first decorated request and is transparent to
 * callers. When a response indicates the session expired, the state is dropped so
 * the retry re-authenticates.
 *
 * NOTE on the login payload: parameter order follows the widely used
 * [acctId, userName, password, lcid] convention. A few server builds expect a
 * different argument list; if yours rejects the login, override
 * {@see self::loginParameters()} (subclass) — no other code needs to change.
 */
class SessionAuth implements AuthStrategy
{
    private const VALIDATE_USER_SERVICE = 'Kingdee.BOS.WebApi.ServicesStub.AuthService.ValidateUser';

    private CookieJar $cookies;

    private bool $loggedIn = false;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
    ) {
        $this->cookies = new CookieJar();
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
        if (!$this->loggedIn) {
            return false;
        }

        $invalid = in_array($response->status, [401, 403], true);

        if (!$invalid) {
            foreach (self::EXPIRY_MARKERS as $marker) {
                if ($marker !== '' && str_contains($response->body, $marker)) {
                    $invalid = true;
                    break;
                }
            }
        }

        if ($invalid) {
            $this->invalidate();
        }

        return $invalid;
    }

    /**
     * Force the next request to log in again (e.g. after switching org).
     */
    public function invalidate(): void
    {
        $this->loggedIn = false;
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

        // LoginResultType === 1 means success on K/3 Cloud.
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
     * Substrings that, when present in a response body, indicate the session is
     * no longer valid. Extend cautiously to avoid false-positive retries.
     */
    private const EXPIRY_MARKERS = [
        '会话超时',
        '会话已失效',
        '会话已过期',
        '登录已失效',
        '未登录',
        'kdsessionid',
    ];
}
