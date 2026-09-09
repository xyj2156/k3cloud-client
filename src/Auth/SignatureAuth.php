<?php

declare(strict_types=1);

namespace K3Cloud\Auth;

use K3Cloud\Config;
use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\HttpResponse;
use K3Cloud\Support\Signer;

/**
 * Third-party application signing.
 *
 * Every request carries a fresh set of HMAC-signed headers; no server session or
 * cookie is used. Two independent signature families are attached:
 *
 *  - X-Kd-*    : the K/3 Cloud "kd" signature over appId + app-data.
 *  - X-Api-*   : the API-gateway signature over method + path + timestamp + nonce.
 *
 * The timestamp and nonce are the same value (seconds since the epoch), and the
 * gateway secret is unmasked from the private half of the application id.
 */
final class SignatureAuth implements AuthStrategy
{
    public function __construct(private readonly Config $config)
    {
    }

    public function decorate(HttpRequest $request): HttpRequest
    {
        $appId = (string) $this->config->appId;
        $appSecret = (string) $this->config->appSecret;

        // Plain-text app data shared by the X-Kd signature and header.
        $appData = $this->buildAppData();

        [$clientId, $secretSegment] = explode('_', $appId, 2);
        $timestamp = (string) time();
        $encodedPath = $this->encodeRequestPath($request->url);

        $request = $request
            // --- X-Kd family (private / kdsvc endpoint signing) ---
            ->withHeader('X-Kd-Appkey', $appId)
            ->withHeader('X-Kd-Appdata', base64_encode($appData))
            ->withHeader('X-Kd-Signature', Signer::hmacSha256Base64($appId . $appData, $appSecret))
            // --- X-Api family (public gateway signing) ---
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
        return false; // stateless signing; nothing to refresh
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
     * String-to-sign for the gateway: METHOD \n encodedPath \n \n signHeaders... \n
     * Query string is empty for kdsvc endpoints, hence the blank line.
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
     * Reduce an absolute URL to its percent-encoded path component (leading "/").
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
