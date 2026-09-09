<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

use Throwable;

/**
 * 本库所有异常的基类。
 *
 * 捕获它即可处理客户端可能产生的任何失败。
 */
class K3CloudException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
