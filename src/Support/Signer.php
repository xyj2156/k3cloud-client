<?php

declare(strict_types=1);

namespace K3Cloud\Support;

use K3Cloud\Exception\ConfigException;

/**
 * Primitive cryptographic helpers required to sign a K/3 Cloud gateway request.
 *
 * These are small, independently written wrappers around hash_hmac()/base64 so
 * the higher-level {@see \K3Cloud\Auth\SignatureAuth} reads clearly.
 */
final class Signer
{
    /**
     * HMAC-SHA256 of $data with $key, expressed as base64 of the *lowercase hex*
     * digest (i.e. base64( hex( hmac(...) ) )). This is the encoding the K/3
     * Cloud gateway expects for both the X-Kd and X-Api signature headers.
     */
    public static function hmacSha256Base64(string $data, string $key): string
    {
        $hex = hash_hmac('sha256', $data, $key, false);

        return base64_encode($hex);
    }

    /**
     * Derive the gateway secret from the private segment of an application id:
     * base64-decode it, XOR byte-for-byte against $mask, then base64-encode.
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
     * Percent-encode a request path so it can be fed into the string-to-sign.
     * "/" is encoded to "%2F", which matches the gateway's canonical form.
     */
    public static function encodePath(string $path): string
    {
        return rawurlencode($path);
    }
}
