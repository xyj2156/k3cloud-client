<?php

declare(strict_types=1);

namespace K3Cloud\Support;

use K3Cloud\Exception\K3CloudException;

/**
 * 构造每个 K/3 Cloud ".common.kdsvc" 端点所期望的 JSON 请求体。
 *
 * WebAPI 使用单一的定位式信封：
 *   {"parameters": [ p0, p1, ... ]}
 *
 * 关联数组/数组参数会作为嵌套 JSON 对象嵌入（不会被二次编码成字符串），这正是 K/3 Cloud 服务端接受的写法。
 */
final class Envelope
{
    /**
     * @param array<array-key,mixed> $parameters 按位序编码（关联/稀疏数组会被归一为定位数组）
     */
    public static function encode(array $parameters): string
    {
        $json = json_encode(
            ['parameters' => array_values($parameters)],
            JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new K3CloudException('Failed to encode request envelope: ' . json_last_error_msg());
        }

        return $json;
    }
}
