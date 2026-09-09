<?php

declare(strict_types=1);

namespace K3Cloud\Support;

use K3Cloud\Exception\ConfigException;

/**
 * 对 K/3 Cloud 网关请求签名所需的底层密码学辅助。
 *
 * 这些是围绕 hash_hmac()/base64 的精简、独立实现，让上层 {@see \K3Cloud\Auth\SignatureAuth} 更易读。
 */
final class Signer
{
    /**
     * 以 $key 对 $data 做 HMAC-SHA256，结果取“小写十六进制”摘要的 base64
     * （即 base64( hex( hmac(...) ) )）。这是 K/3 Cloud 网关对 X-Kd 与 X-Api 两类签名头期望的编码。
     */
    public static function hmacSha256Base64(string $data, string $key): string
    {
        $hex = hash_hmac('sha256', $data, $key, false);

        return base64_encode($hex);
    }

    /**
     * 从应用 ID 的私有段派生网关密钥：先 base64 解码，逐字节与 $mask 异或，再 base64 编码。
     */
    public static function deriveGatewaySecret(string $encodedSegment, string $mask): string
    {
        $raw = base64_decode($encodedSegment, true);
        if ($raw === false) {
            throw new ConfigException('The application id secret segment is not valid base64.');
        }
        if (strlen($raw) > strlen($mask)) {
            throw new ConfigException('Decoded secret segment is longer than the XOR mask.');
        }

        return base64_encode($raw ^ substr($mask, 0, strlen($raw)));
    }

    /**
     * 对请求路径做百分号编码以纳入待签名字符串。“/” 会被编码为 “%2F”，与网关的规范化形式一致。
     */
    public static function encodePath(string $path): string
    {
        return rawurlencode($path);
    }
}
