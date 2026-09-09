<?php

declare(strict_types=1);

namespace K3Cloud\Http;

/**
 * 执行单个 HTTP 请求并返回原始响应。
 *
 * 实现不得因非 2xx 状态而抛异常——请通过 {@see HttpResponse::status()} 暴露给调用方决定。
 * 只有真正的传输失败（DNS、TLS、超时、cURL 错误）才应抛出 TransportException。
 */
interface Transport
{
    public function send(HttpRequest $request): HttpResponse;
}
