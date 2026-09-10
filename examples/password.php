<?php

declare(strict_types=1);

/**
 * 示例：经典用户名 / 密码会话登录。
 *
 * 运行：  php examples/password.php
 *
 * 这是多数私有云 / 本地部署（“买断版”）K/3 Cloud 在未下发第三方 AppID 时采用的方式。
 * 客户端会在第一个调用时透明地完成一次 AuthService.ValidateUser 登录，之后复用
 * kdsessionid Cookie——并且该会话默认落盘（系统临时目录 k3cloud-sessions/），
 * 下一个进程（另一次脚本运行、cron、队列 worker）直接重放、不再登录；
 * 会话过期时自动重登，对调用方透明。
 *
 * 换目录用 ->withSessionStorePath('/path')，关闭落盘用 ->withoutSessionPersistence()。
 * 注意：kdsessionid 等同短期登录凭据，多用户机器上请放好你的目录权限。
 *
 * 若你的服务器使用自签 HTTPS，请保留下面的 ->insecure() 调用——否则删掉它（默认开启校验）。
 */

require __DIR__ . '/../vendor/autoload.php';

use K3Cloud\K3CloudClient;

$api = K3CloudClient::password(
    serverUrl: 'https://192.168.1.100:8080/K3Cloud',
    acctId:    '62f3c9b0xxxxxxxx',
    userName:  'administrator',
    password:  'your-password',
    lcid:      2052,
    orgNum:    0,
)->insecure();

// 一次 view 调用——登录会在请求发出前自动完成。
$result = $api->view('BD_MATERIAL', ['Number' => '01.001', 'Id' => ''])
    ->throwIfError();

print_r($result->payload());

// 强制重新登录（例如切换组织之后）。
$api->relogin();
