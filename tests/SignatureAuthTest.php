<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Auth\SignatureAuth;
use K3Cloud\Config;
use K3Cloud\Http\HttpRequest;
use PHPUnit\Framework\TestCase;

final class SignatureAuthTest extends TestCase
{
    private function config(): Config
    {
        return Config::appSignature(
            serverUrl: 'http://10.0.0.5:8080/K3Cloud',
            acctId: '62f3c9b0acct',
            userName: 'tester',
            appId: '204399_' . base64_encode('secretrand'),
            appSecret: 'topsecret',
            lcid: 2052,
            orgNum: 107,
        );
    }

    public function testDecoratesWithBothSignatureFamilies(): void
    {
        $auth = new SignatureAuth($this->config());
        $url = $this->config()->serviceUrl('Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Save');
        $signed = $auth->decorate(new HttpRequest('POST', $url, '{}'));

        $h = $signed->headers();
        foreach (['X-Kd-Appkey', 'X-Kd-Appdata', 'X-Kd-Signature', 'X-Api-ClientID', 'X-Api-TimeStamp', 'X-Api-Nonce', 'X-Api-Signature'] as $required) {
            self::assertArrayHasKey($required, $h, "missing header $required");
        }
        self::assertSame('204399', $h['X-Api-ClientID']);
    }

    public function testXdKdSignatureEqualsDocumentedTransform(): void
    {
        $config = $this->config();
        $auth = new SignatureAuth($config);
        $url = $config->serviceUrl('Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Save');
        $signed = $auth->decorate(new HttpRequest('POST', $url, '{}'));
        $h = $signed->headers();

        $appData = $config->acctId . ',' . $config->userName . ',' . $config->lcid . ',' . $config->orgNum;
        self::assertSame(base64_encode($appData), $h['X-Kd-Appdata']);
        self::assertSame(
            base64_encode(hash_hmac('sha256', $config->appId . $appData, $config->appSecret, false)),
            $h['X-Kd-Signature']
        );
    }

    public function testSignatureUsesNonceAndTimestampEqualAndCanonicalPath(): void
    {
        $config = $this->config();
        $auth = new SignatureAuth($config);
        $service = 'Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Save';
        $url = $config->serviceUrl($service);
        $signed = $auth->decorate(new HttpRequest('POST', $url, '{}'));
        $h = $signed->headers();

        self::assertSame($h['X-Api-TimeStamp'], $h['X-Api-Nonce']);

        $secretSegment = explode('_', (string) $config->appId, 2)[1];
        $gateway = base64_encode(base64_decode($secretSegment, true) ^ substr(Config::GATEWAY_MASK, 0, strlen(base64_decode($secretSegment, true))));
        $path = rawurlencode('/K3Cloud/' . $service . '.common.kdsvc');
        $toSign = "POST\n" . $path . "\n\nx-api-nonce:" . $h['X-Api-Nonce'] . "\nx-api-timestamp:" . $h['X-Api-TimeStamp'] . "\n";

        self::assertSame(
            base64_encode(hash_hmac('sha256', $toSign, $gateway, false)),
            $h['X-Api-Signature']
        );
    }

    public function testNeverSignalsRetry(): void
    {
        $auth = new SignatureAuth($this->config());
        $req = new HttpRequest('POST', 'http://x/K3Cloud/y.common.kdsvc', '{}');
        self::assertFalse($auth->shouldRetry($req, new \K3Cloud\Http\HttpResponse(401, '')));
    }
}
