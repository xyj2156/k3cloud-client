<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

use Throwable;

/**
 * Root of every exception thrown by this library.
 *
 * Catch this to handle any failure the client can produce.
 */
class K3CloudException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
