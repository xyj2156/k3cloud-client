<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Config;
use K3Cloud\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testSignatureModeRequiresWellFormedAppId(): void
    {
        $this->expectException(ConfigException::class);
        Config::appSignature('https://h/K3Cloud', 'acct', 'user', 'no-underscore-here', 'secret');
    }

    public function testRequiresAbsoluteHttpUrl(): void
    {
        $this->expectException(ConfigException::class);
        Config::password('h/K3Cloud', 'acct', 'user', 'pwd');
    }

    public function testDefaultsAreApplied(): void
    {
        $config = Config::password('https://h/K3Cloud', 'acct', 'user', 'pwd');
        self::assertSame(2052, $config->lcid);
        self::assertSame(0, $config->orgNum);
        self::assertSame(Config::MODE_SESSION, $config->authMode);
    }

    public function testServiceUrlNormalization(): void
    {
        $a = Config::password('https://h/K3Cloud/', 'acct', 'user', 'pwd');
        $b = Config::password('https://h/K3Cloud', 'acct', 'user', 'pwd');
        $expected = 'https://h/K3Cloud/Kingdee.BOS.x.common.kdsvc';
        self::assertSame($expected, $a->serviceUrl('Kingdee.BOS.x'));
        self::assertSame($expected, $b->serviceUrl('Kingdee.BOS.x'));
    }
}
