<?php

declare(strict_types=1);

/**
 * 示例：第三方应用签名（AppID + AppSecret）。
 *
 * 运行：  php examples/signature.php
 * 请先填入下面的凭据。适用于公有云网关（api.kingdee.com/galaxyapi），以及已为你下发
 * “第三方系统登录授权” AppID/AppSecret 的任何部署。
 */

require __DIR__ . '/../vendor/autoload.php';

use K3Cloud\K3CloudClient;

$api = K3CloudClient::appSignature(
    serverUrl: 'https://api.kingdee.com/galaxyapi/',
    acctId:    '62f3c9b0xxxxxxxx',
    userName:  'api_user',
    appId:     '204399_xxxxxxxxxxxxxxxx',   // clientId_密钥段
    appSecret: 'your-app-secret',
    lcid:      2052,
    orgNum:    0,
);

// 1) 查询币种 -> 返回 Result；.rows() 给出“行的列表”。
$rows = $api->query('BD_Currency', ['FCURRENCYID', 'FNUMBER', 'FNAME'], ['TopRowCount' => 10])
    ->throwIfError();
foreach ($rows->rows() as $row) {
    echo implode(' | ', $row), PHP_EOL;
}

// 2) 保存一个币种，并读回新的内码 / 编码。
$created = $api->save('BD_Currency', [
    'NeedUpDateFields' => [],
    'NeedReturnFields' => [],
    'IsDeleteEntry'    => 'true',
    'Model'            => [
        'FNAME'   => '测试币种',
        'FNUMBER' => 'TESTCUR',
        'FPRECISION' => 2,
    ],
])->throwIfError();

echo 'saved id=' . var_export($created->id(), true) . ' number=' . var_export($created->number(), true), PHP_EOL;
