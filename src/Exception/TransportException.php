<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

/**
 * 底层传输失败时抛出：DNS 错误、TLS 问题、连接超时或 cURL 层错误。不携带 HTTP 语义。
 */
final class TransportException extends K3CloudException
{
}
