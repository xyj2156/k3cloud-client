<?php

declare(strict_types=1);

namespace K3Cloud\Auth;

use K3Cloud\Config;
use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\HttpResponse;
use K3Cloud\Http\Transport;
use K3Cloud\Support\Signer;

/**
 * 第三方应用签名。
 *
 * 每个请求都携带一组新计算的 HMAC 签名头；不使用服务端会话或 Cookie。附加两组相互独立的签名：
 *
 *  - X-Kd-*    ：基于 appId + app-data 的 K/3 Cloud "kd" 签名。
 *  - X-Api-*   ：基于 method + path + timestamp + nonce 的 API 网关签名。
 *
 * 时间戳与 nonce 取同一值（自 epoch 起的秒数）；网关密钥由应用 ID 的私有段还原得到。
 */
final class SignatureAuth implements AuthStrategy
{
    public function __construct(private readonly Config $config)
    {
    }

    public function withDependencies(Config $config, Transport $transport): AuthStrategy
    {
        // 无状态：签名按请求由（新的）配置重新计算。
        return new self($config);
    }

    public function decorate(HttpRequest $request): HttpRequest
    {
        $appId = (string) $this->config->appId;
        $appSecret = (string) $this->config->appSecret;

        // 明文 app data，X-Kd 签名与头共用。
        $appData = $this->buildAppData();

        [$clientId, $secretSegment] = explode('_', $appId, 2);
        $timestamp = (string) time();
        $encodedPath = $this->encodeRequestPath($request->url);

        $request = $request
            // --- X-Kd 组（私有云 / kdsvc 端点签名）---
            ->withHeader('X-Kd-Appkey', $appId)
            ->withHeader('X-Kd-Appdata', base64_encode($appData))
            ->withHeader('X-Kd-Signature', Signer::hmacSha256Base64($appId . $appData, $appSecret))
            // --- X-Api 组（公有云网关签名）---
            ->withHeader('X-Api-Auth-Version', '2.0')
            ->withHeader('X-Api-SignHeaders', 'X-Api-TimeStamp,X-Api-Nonce')
            ->withHeader('X-Api-ClientID', $clientId)
            ->withHeader('X-Api-TimeStamp', $timestamp)
            ->withHeader('X-Api-Nonce', $timestamp)
            ->withHeader('X-Api-Signature', Signer::hmacSha256Base64(
                $this->stringToSign($encodedPath, $timestamp),
                Signer::deriveGatewaySecret($secretSegment, Config::GATEWAY_MASK)
            ));

        return $request;
    }

    public function shouldRetry(HttpRequest $request, HttpResponse $response): bool
    {
        return false; // 无状态签名；没有需要刷新的东西
    }

    private function buildAppData(): string
    {
        return implode(',', [
            $this->config->acctId,
            $this->config->userName,
            $this->config->lcid,
            $this->config->orgNum,
        ]);
    }

    /**
     * 网关待签名字符串：METHOD \n encodedPath \n \n signHeaders... \n
     * kdsvc 端点的查询串为空，故中间有一行空行。
     */
    private function stringToSign(string $encodedPath, string $timestamp): string
    {
        return "POST\n"
            . $encodedPath . "\n"
            . "\n"
            . 'x-api-nonce:' . $timestamp . "\n"
            . 'x-api-timestamp:' . $timestamp . "\n";
    }

    /**
     * 把绝对 URL 规约为其百分号编码后的路径部分（以 "/" 开头）。
     */
    private function encodeRequestPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }

        return Signer::encodePath($path);
    }
}
