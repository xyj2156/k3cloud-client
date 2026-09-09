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

    /**
     * Hash of the credential-bearing config the current session was established
     * for, so a rebase can tell "reconfigure" (keep session) from "different
     * login" (drop session). Null until/unless logged in.
     */
    private ?string $establishedFor = null;

    public function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
    ) {
        $this->cookies = new CookieJar();
    }

    public function withDependencies(Config $config, Transport $transport): AuthStrategy
    {
        $next = new static($config, $transport);

        // Preserve the live session only when the identity that produced it is
        // unchanged; otherwise the next request must authenticate afresh.
        if ($this->loggedIn && $this->establishedFor === self::identityKey($config)) {
            $next->loggedIn = true;
            $next->establishedFor = $this->establishedFor;
            $next->cookies = clone $this->cookies;
        }

        return $next;
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
     * Decide whether a response means "the session is gone" and therefore a
     * single re-login + replay is worthwhile.
     *
     * We match on the extracted error *message text* rather than the whole body
     * (a successful response may legitimately contain these words inside user
     * data). A session-lost save, for example, comes back as HTTP 200 with
     * IsSuccess=false and Errors[].Message = "会话信息已丢失，请重新登录".
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
     * Collect human-readable error text from a K/3 Cloud response body.
     *
     * @return list<string> empty for a well-formed successful JSON body
     */
    private static function errorMessages(string $body): array
    {
        $json = json_decode($body, true);

        // Non-JSON (e.g. a plain-text gateway error): fall back to the raw body.
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

        // Some builds return the error as a bare string in Result, or at top level.
        foreach (['Message', 'description', 'Result'] as $key) {
            if (is_string($json[$key] ?? null)) {
                $messages[] = $json[$key];
            }
        }

        return $messages;
    }

    /**
     * Force the next request to log in again (e.g. after switching org).
     */
    public function invalidate(): void
    {
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
        $this->establishedFor = self::identityKey($this->config);
    }

    /**
     * Fingerprint of the credential-bearing config fields that a session belongs
     * to. TLS/timeout changes are intentionally excluded (they don't affect who
     * is logged in); identity changes are not.
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
     * PCRE patterns (UTF-8) matched against the extracted error message text to
     * detect a lost/expired session. Covers the phrasings K/3 Cloud actually
     * returns, e.g. "会话信息已丢失，请重新登录". Intentionally message-scoped so
     * ordinary data never trips it.
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
