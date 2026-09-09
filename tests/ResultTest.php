<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Exception\ApiException;
use K3Cloud\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testReadsSuccessfulSaveEnvelope(): void
    {
        $payload = json_decode('{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]},"Id":100001,"Number":"CUR001"}}', true);
        $res = new Result('raw', $payload, 200);

        self::assertTrue($res->isSuccess());
        self::assertSame(100001, $res->id());
        self::assertSame('CUR001', $res->number());
        self::assertNull($res->errorMessage());
    }

    public function testReadsFailedEnvelopeAndErrorMessage(): void
    {
        $payload = json_decode('{"Result":{"ResponseStatus":{"IsSuccess":false,"Errors":[{"Message":"币种编码重复"}]}}}', true);
        $res = new Result('raw', $payload, 200);

        self::assertFalse($res->isSuccess());
        self::assertSame('币种编码重复', $res->errorMessage());

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('币种编码重复');
        $res->throwIfError();
    }

    public function testTreatsListResponsesAsQueryRows(): void
    {
        $payload = json_decode('[["CUR001","人民币"],["CUR002","美元"]]', true);
        $res = new Result('raw', $payload, 200);

        self::assertTrue($res->isList());
        self::assertTrue($res->isSuccess());
        self::assertCount(2, $res->rows());
        self::assertSame('人民币', $res->rows()[0][1]);
    }
}
