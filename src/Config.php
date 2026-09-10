<?php

declare(strict_types=1);

namespace K3Cloud;

use K3Cloud\Exception\ConfigException;

/**
 * {@see K3CloudClient} 所用的不可变连接 + 凭据配置。
 *
 * 支持两种认证方式：
 *  - MODE_SIGNATURE ：第三方 AppID / AppSecret，按请求计算 HMAC 头。
 *  - MODE_SESSION   ：经典用户名 / 密码，先 AuthService.ValidateUser 登录，
 *                     再使用 kdsessionid Cookie 会话。
 *
 * 为清晰起见，请使用静态方法 {@see Config::appSignature()} 与
 * {@see Config::password()}，而非直接构造。
 */
final class Config
{
    public const MODE_SIGNATURE = 'signature';
    public const MODE_SESSION = 'session';

    /**
     * 用于从 AppID 私有段还原网关密钥的掩码。
     * 该常量是公开的 K/3 Cloud 签名协议的一部分。
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
     * 第三方应用（AppID + AppSecret）签名模式。
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
     * 用户名 / 密码会话模式。
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
     * 开启（默认）或关闭 TLS 证书校验。仅在受信任、使用自签证书的私有云 / 本地部署才关闭。
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
     * 构造一个更新后的不可变副本；未传入的字段沿用当前值。
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
     * 某个 WebAPI 服务的绝对 URL，例如：
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
            $parts = explode('_', (string) $this->appId, 2);
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
