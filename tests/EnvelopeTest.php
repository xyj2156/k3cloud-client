<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Support\Envelope;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    public function testWrapsParametersPositionallyAndKeepsUnicodeReadable(): void
    {
        $json = Envelope::encode(['BD_Currency', ['Model' => ['FNAME' => '人民币']]]);
        $decoded = json_decode($json, true);

        self::assertArrayHasKey('parameters', $decoded);
        self::assertSame('BD_Currency', $decoded['parameters'][0]);
        // model 作为嵌套对象嵌入，而非被二次编码成字符串。
        self::assertIsArray($decoded['parameters'][1]);
        self::assertSame('人民币', $decoded['parameters'][1]['Model']['FNAME']);
        // JSON_UNESCAPED_UNICODE：输出原始 UTF-8，而非 \uXXXX 转义。
        self::assertStringContainsString('人民币', $json);
    }

    public function testScalarParametersArePreservedInOrder(): void
    {
        $decoded = json_decode(Envelope::encode(['62f3', 'user', 'pwd', 2052]), true);

        self::assertSame(['62f3', 'user', 'pwd', 2052], $decoded['parameters']);
    }

    public function testAssociativeKeysAreReindexed(): void
    {
        $decoded = json_decode(Envelope::encode(['a' => 'x', 'b' => 'y']), true);

        self::assertSame(['x', 'y'], $decoded['parameters']);
    }
}
