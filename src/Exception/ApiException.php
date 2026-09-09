<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

use K3Cloud\Result;

/**
 * Raised when the WebAPI responded successfully at the HTTP layer but reported a
 * business/validation error for the operation itself (e.g. IsSuccess = false).
 *
 * Carries the full {@see Result} so callers can inspect field-level errors.
 */
final class ApiException extends K3CloudException
{
    private Result $result;

    public function __construct(Result $result, string $message = '', int $code = 0)
    {
        $this->result = $result;
        parent::__construct(
            $message !== '' ? $message : ($result->errorMessage() ?? 'The K/3 Cloud API returned an error.'),
            $code
        );
    }

    public function result(): Result
    {
        return $this->result;
    }
}
