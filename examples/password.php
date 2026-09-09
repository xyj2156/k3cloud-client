<?php

declare(strict_types=1);

/**
 * Example: classic username / password session login.
 *
 * Run:  php examples/password.php
 *
 * This is the mode most private / on-premise ("买断版") K/3 Cloud installs use
 * when no third-party AppID has been issued. The client performs the
 * AuthService.ValidateUser login once, transparently, on the first call, and
 * reuses the kdsessionid cookie afterwards.
 *
 * If your server uses self-signed HTTPS, keep the ->withoutTlsVerification()
 * call below — otherwise remove it.
 */

require __DIR__ . '/../vendor/autoload.php';

use K3Cloud\Config;
use K3Cloud\K3CloudClient;

$config = Config::password(
    serverUrl: 'https://192.168.1.100:8080/K3Cloud',
    acctId:    '62f3c9b0xxxxxxxx',
    userName:  'administrator',
    password:  'your-password',
    lcid:      2052,
    orgNum:    0,
)->withoutTlsVerification();

$api = new K3CloudClient($config);

// A view call — the login happens automatically before the request goes out.
$result = $api->view('BD_MATERIAL', ['Number' => '01.001', 'Id' => ''])
    ->throwIfError();

print_r($result->payload());

// Force a re-login (e.g. after switching organisation).
$api->relogin();
