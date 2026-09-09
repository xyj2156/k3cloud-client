<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Exception\ConfigException;
use K3Cloud\Support\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function testHmacProducesBase64OfLowercaseHex(): void
    {
        $data = 'some-payload';
        $key = 'secret';

        // The gateway expects base64( hex(hmac_sha256) ), NOT base64 of raw bytes.
        $expected = base64_encode(hash_hmac('sha256', $data, $key, false));

        self::assertSame($expected, Signer::hmacSha256Base64($data, $key));
    }

    public function testGatewaySecretIsUnmaskedAndReencoded(): void
    {
        $mask = '0054f397c6234378b09ca7d3e5debce7';
        $raw = 'abcdefgh';                       // pretend decoded secret bytes
        $encoded = base64_encode($raw);

        $actual = Signer::deriveGatewaySecret($encoded, $mask);

        // base64( raw XOR mask[0..len] )
        self::assertSame(base64_encode($raw ^ substr($mask, 0, strlen($raw))), $actual);
    }

    public function testRejectsNonBase64Segment(): void
    {
        $this->expectException(ConfigException::class);
        Signer::deriveGatewaySecret('!!!!not-base64!!!!', '0054f397c6234378b09ca7d3e5debce7');
    }

    public function testPathEncodingMatchesCanonicalForm(): void
    {
        $path = '/K3Cloud/Kingdee.BOS.WebApi.ServicesStub.DynamicFormService.Save.common.kdsvc';
        $encoded = Signer::encodePath($path);

        self::assertStringStartsWith('%2F', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        // Dots and letters are left untouched by rawurlencode.
        self::assertStringContainsString('Save.common.kdsvc', $encoded);
    }
}
