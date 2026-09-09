<?php

declare(strict_types=1);

namespace K3Cloud\Auth;

use K3Cloud\Config;
use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\HttpResponse;
use K3Cloud\Http\Transport;

/**
 * 为请求附加所需的鉴权材料（头和/或 Cookie），并对"需要刷新凭据/会话"的响应作出反应。
 */
interface AuthStrategy
{
    /**
     * 返回一个重新绑定到新 Config 与 Transport 的策略：当凭据身份未变时，沿用可复用的会话状态。
     *
     * 用于客户端链式选项（TLS / 超时）重建传输层时：无状态策略直接重建；会话策略保留其活动
     * 会话，使中途重配置不会触发重新登录。
     */
    public function withDependencies(Config $config, Transport $transport): self;

    /**
     * 返回附加了鉴权头 / Cookie 的 $request 副本。
     * 实现可在此惰性发起一次登录往返。
     */
    public function decorate(HttpRequest $request): HttpRequest;

    /**
     * 当上一个响应表明会话已失效、且值得在重新鉴权后做一次透明重试时返回 true。
     */
    public function shouldRetry(HttpRequest $request, HttpResponse $response): bool;
}
