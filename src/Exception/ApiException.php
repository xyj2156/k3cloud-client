<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

use K3Cloud\Result;

/**
 * 当 WebAPI 在 HTTP 层成功响应、但操作本身返回了业务/校验错误时抛出（如 IsSuccess = false）。
 *
 * 携带完整的 {@see Result}，便于调用方查看字段级错误。
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
