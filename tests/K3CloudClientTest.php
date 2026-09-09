<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Config;
use K3Cloud\K3CloudClient;
use PHPUnit\Framework\TestCase;

final class K3CloudClientTest extends TestCase
{
    public function testPasswordFactoryReturnsClientAndDefaultsToSecure(): void
    {
        $api = K3CloudClient::password('https://h/K3Cloud', 'acct', 'user', 'pwd');

        self::assertInstanceOf(K3CloudClient::class, $api);
        self::assertTrue($api->config()->verifyTls);
    }

    public function testInsecureReturnsNewClientWithoutMutatingOriginal(): void
    {
        $secure = K3CloudClient::password('https://h/K3Cloud', 'acct', 'user', 'pwd');
        $insecure = $secure->insecure();

        self::assertNotSame($secure, $insecure, 'fluent option must return a fresh instance');
        self::assertTrue($secure->config()->verifyTls, 'original untouched');
        self::assertFalse($insecure->config()->verifyTls);
    }

    public function testTimeoutsAreApplied(): void
    {
        $api = K3CloudClient::appSignature('https://h/K3Cloud', 'acct', 'user', '204399_' . base64_encode('abcd'), 'sec')
            ->withTimeouts(5, 30);

        self::assertSame(5, $api->config()->connectTimeout);
        self::assertSame(30, $api->config()->requestTimeout);
    }

    public function testSecureAfterInsecureFlipsBack(): void
    {
        $api = K3CloudClient::password('https://h/K3Cloud', 'acct', 'user', 'pwd')
            ->insecure()
            ->secure();

        self::assertTrue($api->config()->verifyTls);
    }

    public function testFluentChainPreservesSubclassType(): void
    {
        $api = TestableClient::password('https://h/K3Cloud', 'acct', 'user', 'pwd')->insecure();
        self::assertInstanceOf(TestableClient::class, $api, 'named static() + new static() keep the concrete class');
    }
}

/** 用于证明链式调用中后期静态绑定（late static binding）生效的具体子类。 */
final class TestableClient extends K3CloudClient
{
}
