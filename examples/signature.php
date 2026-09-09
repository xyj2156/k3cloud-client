<?php

declare(strict_types=1);

/**
 * Example: third-party application signing (app id + app secret).
 *
 * Run:  php examples/signature.php
 * Fill in the credentials below first. Good for the public-cloud gateway
 * (api.kingdee.com/galaxyapi) and any deployment that has issued you an
 * "第三方系统登录授权" AppID/AppSecret.
 */

require __DIR__ . '/../vendor/autoload.php';

use K3Cloud\K3CloudClient;

$api = K3CloudClient::appSignature(
    serverUrl: 'https://api.kingdee.com/galaxyapi/',
    acctId:    '62f3c9b0xxxxxxxx',
    userName:  'api_user',
    appId:     '204399_xxxxxxxxxxxxxxxx',   // clientId_secretSegment
    appSecret: 'your-app-secret',
    lcid:      2052,
    orgNum:    0,
);

// 1) Query currencies -> returns a Result; .rows() gives a list of lists.
$rows = $api->query('BD_Currency', ['FCURRENCYID', 'FNUMBER', 'FNAME'], ['TopRowCount' => 10])
    ->throwIfError();
foreach ($rows->rows() as $row) {
    echo implode(' | ', $row), PHP_EOL;
}

// 2) Save a currency and read back the new internal id / number.
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
