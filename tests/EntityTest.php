<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Config;
use K3Cloud\Entity;
use K3Cloud\K3CloudClient;
use PHPUnit\Framework\TestCase;

/**
 * 一个由开发者编写的实体子类：正是它带来跨 IDE 的补全。
 * 只声明开发者真正用到的字段/方法；未知的字段仍通过继承来的 set()/custom()/package() 生效。
 */
final class SaleOrder extends Entity
{
    public const FORM_ID = 'SAL_SaleOrder';

    public function customer(string $number): static
    {
        return $this->ref('FCustId', $number);
    }
}

final class EntityTest extends TestCase
{
    private function client(FakeTransport $fake): K3CloudClient
    {
        $config = Config::appSignature(
            'https://h:8080/K3Cloud', 'acct', 'user',
            '204399_' . base64_encode('abcd'), 'sec',
        );

        return new K3CloudClient($config, $fake);
    }

    public function testBaseMethodsPreserveConcreteSubclassType(): void
    {
        $api = $this->client(new FakeTransport());
        $order = SaleOrder::for($api)->customer('16.195.06.0001');

        self::assertInstanceOf(SaleOrder::class, $order);
        self::assertSame('SAL_SaleOrder', $order->formId());
        self::assertSame(
            ['Model' => ['FCustId' => ['FNumber' => '16.195.06.0001']]],
            $order->toArray()
        );
    }

    public function testCustomErkFieldPassesThroughWithoutSchema(): void
    {
        $api = $this->client(new FakeTransport());
        $payload = SaleOrder::for($api)->custom('F_PEYC_Decimal', '1710')->toArray();

        self::assertSame(['Model' => ['F_PEYC_Decimal' => '1710']], $payload);
    }

    public function testReferenceCasingOverride(): void
    {
        $api = $this->client(new FakeTransport());
        $payload = SaleOrder::for($api)->ref('FBillTypeID', 'XSDD01_SYS', 'FNUMBER')->toArray();

        self::assertSame('XSDD01_SYS', $payload['Model']['FBillTypeID']['FNUMBER']);
    }

    public function testLineIsTheOnlyAppendPathAndAppendsRows(): void
    {
        $api = $this->client(new FakeTransport());
        $payload = SaleOrder::for($api)
            ->line('FSaleOrderEntry', fn($r) => $r->ref('FMaterialId', 'M1')->set('FQty', '1.000'))
            ->line('FSaleOrderEntry', fn($r) => $r->ref('FMaterialId', 'M2')->custom('F_PEYC_Decimal', '1280'))
            ->toArray();

        $lines = $payload['Model']['FSaleOrderEntry'];
        self::assertCount(2, $lines);
        self::assertSame('M2', $lines[1]['FMaterialId']['FNumber']);
        self::assertSame('1280', $lines[1]['F_PEYC_Decimal']);
    }

    public function testPackageDeepMergesAssocButReplacesLists(): void
    {
        $api = $this->client(new FakeTransport());
        $entity = SaleOrder::for($api)->package([
            'FSaleOrderFinance' => ['FSettleCurrId' => ['FNumber' => 'PRE001']],
            'FSaleOrderEntry'   => [['FQty' => '1']],
        ]);

        // 关联深合并会新增一个兄弟键
        $entity->package(['FSaleOrderFinance' => ['FExchangeRate' => '1']]);
        $model = $entity->toArray()['Model'];
        self::assertSame(['FNumber' => 'PRE001'], $model['FSaleOrderFinance']['FSettleCurrId']);
        self::assertSame('1', $model['FSaleOrderFinance']['FExchangeRate']);

        // 列表值整体替换，而非追加
        $entity->package(['FSaleOrderEntry' => [['FQty' => '9']]]);
        self::assertCount(1, $entity->toArray()['Model']['FSaleOrderEntry']);
        self::assertSame('9', $entity->toArray()['Model']['FSaleOrderEntry'][0]['FQty']);
    }

    public function testSeedAcceptsBothBareModelAndFullPayload(): void
    {
        $api = $this->client(new FakeTransport());

        $bare = (new Entity($api, 'X', ['FCustId' => 'C']))->toArray();
        self::assertSame(['Model' => ['FCustId' => 'C']], $bare);

        $full = (new Entity($api, 'X', ['NeedUpDateFields' => [], 'Model' => ['FQty' => '1']]))->toArray();
        self::assertSame([], $full['NeedUpDateFields']);
        self::assertSame('1', $full['Model']['FQty']);
    }

    public function testSavePostsEnvelopeAndReturnsResult(): void
    {
        $fake = new FakeTransport();
        $fake->queueResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]},"Id":398701,"Number":"XSDD0121006984"}}');
        $api = $this->client($fake);

        $result = SaleOrder::for($api)->customer('16.195.06.0001')->save();

        $req = $fake->lastRequest();
        self::assertStringContainsString('.Save.common.kdsvc', $req->url);
        self::assertArrayHasKey('X-Kd-Signature', $req->headers());
        $sent = json_decode($req->body, true);
        self::assertSame('SAL_SaleOrder', $sent['parameters'][0]);
        self::assertSame('16.195.06.0001', $sent['parameters'][1]['Model']['FCustId']['FNumber']);

        self::assertTrue($result->isSuccess());
        self::assertSame(398701, $result->id());
        self::assertSame('XSDD0121006984', $result->number());
    }

    public function testTerminalPatchMergesIntoModel(): void
    {
        $fake = new FakeTransport();
        $fake->queueResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]},"Id":1,"Number":"N"}}');
        $api = $this->client($fake);

        SaleOrder::for($api)->customer('C1')->save(['FNote' => '补充']);

        $sent = json_decode($fake->lastRequest()->body, true);
        self::assertSame('补充', $sent['parameters'][1]['Model']['FNote']);
        self::assertSame('C1', $sent['parameters'][1]['Model']['FCustId']['FNumber']);
    }

    public function testGenericCallRoutesToArbitraryOp(): void
    {
        $fake = new FakeTransport();
        $fake->queueResponse(200, '{"Result":{"ResponseStatus":{"IsSuccess":true,"Errors":[]}}}');
        $api = $this->client($fake);

        SaleOrder::for($api)->set('Foo', 'bar')->call('Submit');

        self::assertStringContainsString('.Submit.common.kdsvc', $fake->lastRequest()->url);
    }

    public function testClientEntryPoints(): void
    {
        $api = $this->client(new FakeTransport());

        self::assertInstanceOf(SaleOrder::class, $api->entity(SaleOrder::class));
        self::assertInstanceOf(Entity::class, $api->bill('SOME_Unknown_Entity'));
        self::assertSame('SOME_Unknown_Entity', $api->bill('SOME_Unknown_Entity')->formId());

        Entity::register('SAL_SaleOrder', SaleOrder::class);
        self::assertInstanceOf(SaleOrder::class, $api->bill('SAL_SaleOrder'));
    }

    public function testControlFlagSetterLandsAtPayloadTopLevel(): void
    {
        $api = $this->client(new FakeTransport());
        $payload = SaleOrder::for($api)->control('NeedUpDateFields', ['FSettleCurrId'])->set('FQty', '1')->toArray();

        self::assertSame(['FSettleCurrId'], $payload['NeedUpDateFields']);
        self::assertSame('1', $payload['Model']['FQty']);
    }
}
