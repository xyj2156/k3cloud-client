<?php

declare(strict_types=1);

namespace K3Cloud;

use K3Cloud\Exception\ConfigException;

/**
 * Immutable connection + credential configuration for a {@see K3CloudClient}.
 *
 * Two authentication modes are supported:
 *  - MODE_SIGNATURE : third-party app id / app secret, per-request HMAC headers.
 *  - MODE_SESSION   : classic username / password, AuthService.ValidateUser login
 *                     followed by a kdsessionid cookie session.
 *
 * Use the static helpers {@see Config::appSignature()} and
 * {@see Config::password()} rather than the constructor for clarity.
 */
final class Config
{
    public const MODE_SIGNATURE = 'signature';
    public const MODE_SESSION = 'session';

    /**
     * Mask used to unmask the gateway secret from the private half of the app id.
     * This constant is part of the documented K/3 Cloud signing protocol.
     */
    public const GATEWAY_MASK = '0054f397c6234378b09ca7d3e5debce7';

    private function __construct(
        public readonly string $serverUrl,
        public readonly string $acctId,
        public readonly string $userName,
        public readonly string $authMode,
        public readonly ?string $password = null,
        public readonly ?string $appId = null,
        public readonly ?string $appSecret = null,
        public readonly int $lcid = 2052,
        public readonly int $orgNum = 0,
        public readonly int $connectTimeout = 15,
        public readonly int $requestTimeout = 120,
        public readonly bool $verifyTls = true,
    ) {
        $this->validate();
    }

    /**
     * Third-party application (app id + app secret) signing mode.
     */
    public static function appSignature(
        string $serverUrl,
        string $acctId,
        string $userName,
        string $appId,
        string $appSecret,
        int $lcid = 2052,
        int $orgNum = 0,
    ): self {
        return new self(
            serverUrl: $serverUrl,
            acctId: $acctId,
            userName: $userName,
            authMode: self::MODE_SIGNATURE,
            appId: $appId,
            appSecret: $appSecret,
            lcid: $lcid,
            orgNum: $orgNum,
        );
    }

    /**
     * Username / password session mode.
     */
    public static function password(
        string $serverUrl,
        string $acctId,
        string $userName,
        string $password,
        int $lcid = 2052,
        int $orgNum = 0,
    ): self {
        return new self(
            serverUrl: $serverUrl,
            acctId: $acctId,
            userName: $userName,
            authMode: self::MODE_SESSION,
            password: $password,
            lcid: $lcid,
            orgNum: $orgNum,
        );
    }

    public function withTimeouts(int $connectTimeout, int $requestTimeout): self
    {
        return $this->copy(connectTimeout: $connectTimeout, requestTimeout: $requestTimeout);
    }

    /**
     * Turn TLS certificate verification on (default) or off. Only disable it for
     * trusted private / on-premise installs that use self-signed certificates.
     */
    public function withTlsVerification(bool $verify): self
    {
        return $this->copy(verifyTls: $verify);
    }

    public function withoutTlsVerification(): self
    {
        return $this->withTlsVerification(false);
    }

    /**
     * Build an updated immutable copy, reusing the current values for anything unset.
     */
    private function copy(?int $connectTimeout = null, ?int $requestTimeout = null, ?bool $verifyTls = null): self
    {
        return new self(
            $this->serverUrl, $this->acctId, $this->userName, $this->authMode,
            $this->password, $this->appId, $this->appSecret, $this->lcid, $this->orgNum,
            $connectTimeout ?? $this->connectTimeout,
            $requestTimeout ?? $this->requestTimeout,
            $verifyTls ?? $this->verifyTls,
        );
    }

    /**
     * Absolute URL for a WebAPI service, e.g.
     *  save() -> ".../Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Save.common.kdsvc"
     */
    public function serviceUrl(string $service): string
    {
        return rtrim($this->serverUrl, '/') . '/' . ltrim($service, '/') . '.common.kdsvc';
    }

    private function validate(): void
    {
        if (trim($this->serverUrl) === '') {
            throw new ConfigException('serverUrl is required.');
        }
        if (!preg_match('#^https?://#i', $this->serverUrl)) {
            throw new ConfigException('serverUrl must be an absolute http(s) URL, e.g. "https://10.0.0.5:8080/K3Cloud".');
        }
        if (trim($this->acctId) === '') {
            throw new ConfigException('acctId (account/dataset id) is required.');
        }
        if (trim($this->userName) === '') {
            throw new ConfigException('userName is required.');
        }

        if ($this->authMode === self::MODE_SIGNATURE) {
            if (trim((string) $this->appId) === '' || trim((string) $this->appSecret) === '') {
                throw new ConfigException('Signature mode requires both appId and appSecret.');
            }
            $parts = explode('_', $this->appId, 2);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new ConfigException('appId must be "clientId_secretToken" (a single underscore separating the two parts).');
            }
        } elseif ($this->authMode === self::MODE_SESSION) {
            if ($this->password === null || $this->password === '') {
                throw new ConfigException('Session (password) mode requires a password.');
            }
        } else {
            throw new ConfigException('Unknown auth mode: ' . $this->authMode);
        }
    }
}
