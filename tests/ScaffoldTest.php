<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 针对 `tools/build-metadata.php --scaffold` 的集成测试。
 *
 * 使用内联 fixture（不依赖任何本地导出），在临时目录里运行生成器，断言生成的类与 map。
 * 自行清理其临时目录。
 */
final class ScaffoldTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function runGenerator(bool $withCustom): string
    {
        $dir = sys_get_temp_dir() . '/k3scaffold_' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        $entities = [
            ['id' => 1, 'entity_code' => 'TST_Thing', 'entity_name' => '测试单', 'entity_description' => ''],
        ];
        $fields = [
            ['id' => 1, 'kingdee_entity_id' => 1, 'title' => '主键', 'kingdee_name' => 'FID', 'length' => '255', 'cate_name' => '基本信息', 'cate_code' => 'FBillHead'],
            ['id' => 2, 'kingdee_entity_id' => 1, 'title' => '单据编号', 'kingdee_name' => 'FBillNo', 'length' => '80', 'cate_name' => '基本信息', 'cate_code' => 'FBillHead'],
            ['id' => 3, 'kingdee_entity_id' => 1, 'title' => '扩展字段', 'kingdee_name' => 'F_ABC_ext', 'length' => '0', 'cate_name' => '基本信息', 'cate_code' => 'FBillHead'],
            ['id' => 4, 'kingdee_entity_id' => 1, 'title' => '数量', 'kingdee_name' => 'FQty', 'length' => '23,8', 'cate_name' => '明细', 'cate_code' => 'FDetail'],
        ];
        file_put_contents("$dir/entity.json", json_encode($entities, JSON_UNESCAPED_UNICODE));
        file_put_contents("$dir/field.json", json_encode($fields, JSON_UNESCAPED_UNICODE));

        $tool = realpath(__DIR__ . '/../tools/build-metadata.php');
        $cmd = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($tool),
            escapeshellarg("$dir/entity.json"),
            escapeshellarg("$dir/field.json"),
            escapeshellarg("$dir/out"),
            '--scaffold',
            $withCustom ? '--with-custom' : '',
            '--scaffold-out=' . escapeshellarg("$dir/scaffold"),
            '--namespace=Gen',
        ]);

        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        self::assertSame(0, $code, 'generator failed: ' . implode("\n", $out));

        return "$dir/scaffold";
    }

    public function testScaffoldEmitsUsableClassAndMap(): void
    {
        $scaffold = $this->runGenerator(withCustom: true);
        $file = "$scaffold/TstThing.php";

        self::assertFileExists($file);

        // 语法有效的 PHP
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $lint, $lintCode);
        self::assertSame(0, $lintCode, implode("\n", $lint));

        $src = (string) file_get_contents($file);
        self::assertStringContainsString('namespace Gen;', $src);
        self::assertStringContainsString('final class TstThing extends Entity', $src);
        self::assertStringContainsString("public const FORM_ID = 'TST_Thing';", $src);
        self::assertStringContainsString("public const FLD_FID = 'FID'", $src);
        self::assertStringContainsString("public const FLD_FBILLNO = 'FBillNo'", $src);
        // 二开常量之所以存在，只是因为 --with-custom
        self::assertStringContainsString("public const FLD_F_ABC_EXT = 'F_ABC_ext'", $src);
        // 非头部分段 FDetail 的分录 helper
        self::assertStringContainsString('function addFdetailLine(callable|array $row): static', $src);
        self::assertStringContainsString("\$this->line('FDetail', \$row)", $src);

        $map = require "$scaffold/map.php";
        self::assertSame('Gen\\TstThing', $map['TST_Thing'] ?? null);
    }

    public function testCustomFieldsOmittedWithoutFlag(): void
    {
        $scaffold = $this->runGenerator(withCustom: false);
        $src = (string) file_get_contents("$scaffold/TstThing.php");

        self::assertStringContainsString("public const FLD_FID = 'FID'", $src);
        self::assertStringNotContainsString('F_ABC_ext', $src, '二开 fields excluded without --with-custom');
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
