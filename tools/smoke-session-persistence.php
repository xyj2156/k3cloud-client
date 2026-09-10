<?php

declare(strict_types=1);

/**
 * 会话落盘的实机冒烟脚本（真实服务器，非单测）。
 *
 * 用法：设好凭据环境变量后【连续运行两次】——
 *   php tools/smoke-session-persistence.php
 *   php tools/smoke-session-persistence.php
 *
 * 断言逻辑（黑盒）：比较两次运行之间会话文件的 saved_at 时间戳。
 *   第 1 次：登录 → 落盘 → saved_at = 本次时间；
 *   第 2 次：若免登录复用成功，saved_at 不变（文件未被重写），且仍查到数据行；
 *   若服务端已把会话判死，会看到"重登→自愈"，saved_at 变新 —— 功能依旧正确，
 *   只是多付一次往返。想验证自愈链路，可拿浏览器登录同账套后在服务端把该会话踢下线再跑。
 *
 * 凭据一律通过环境变量注入（不落文件，防误提交）：
 *   K3C_URL / K3C_ACCT / K3C_USER / K3C_PWD
 */

require __DIR__ . '/../vendor/autoload.php';

use K3Cloud\Auth\FileSessionStore;
use K3Cloud\K3CloudClient;

$script = $argv[0] ?? 'tools/smoke-session-persistence.php';
$need = static function (string $k) use ($script): string {
    $v = getenv($k);
    if ($v === false || $v === '') {
        fwrite(STDERR, "缺少环境变量 {$k}，用法：K3C_URL=... K3C_ACCT=... K3C_USER=... K3C_PWD=... php {$script}\n");
        exit(1);
    }

    return $v;
};

$api = K3CloudClient::password(
    serverUrl: $need('K3C_URL'),
    acctId:    $need('K3C_ACCT'),
    userName:  $need('K3C_USER'),
    password:  $need('K3C_PWD'),
)->insecure();   // 自签 / 本地环境；证书公网可信则删掉本行

$storeDir = FileSessionStore::defaultDir();

function sessionFingerprint(string $dir): string
{
    $parts = [];
    foreach ((array) glob($dir . '/session-*.json') as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        $parts[] = basename($file) . '@' . ($data['saved_at'] ?? '?');
    }

    return $parts === [] ? '(无会话文件)' : implode(', ', $parts);
}

echo '默认落盘目录: ', $storeDir, "\n";
echo '运行前指纹:   ', sessionFingerprint($storeDir), "\n";

$result = $api->query('BD_Currency', ['FCURRENCYID', 'FNUMBER', 'FNAME'])->throwIfError();

echo '查询到币种行数: ', count($result->rows()), "\n";
echo '运行后指纹:   ', sessionFingerprint($storeDir), "\n";
echo '两次运行之间 saved_at 不变 = 免登录复用生效；变新 = 本次发生了（重）登录。', "\n";
