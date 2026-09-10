<?php

declare(strict_types=1);

namespace K3Cloud;

use K3Cloud\Exception\ApiException;

/**
 * 对 K/3 Cloud WebAPI 响应做解码、便于使用的视图。
 *
 * 服务端会因操作不同而返回多种形态：
 *  - 写/操作类：{"Result": {"ResponseStatus": {"IsSuccess": bool, ...},
 *    "Id": .., "Number": "..", "Message": ..}}
 *  - 单据查询：行的 JSON 数组（列表的列表）。
 *  - 普通数据：JSON 数组/对象。
 *
 * Result 提炼出公共部分，免去调用方手工翻找嵌套结构。
 */
final class Result
{
    /** @var array<mixed>|string|int|float|bool|null */
    private mixed $payload;

    /**
     * @param array<mixed>|string|int|float|bool|null $payload
     */
    public function __construct(
        public readonly string $raw,
        mixed $payload,
        public readonly int $status = 200,
    ) {
        $this->payload = $payload;
    }

    /** 解码后的响应体（数组 / 标量）；非 JSON 时为 null。 */
    public function payload(): mixed
    {
        return $this->payload;
    }

    /** payload() 的别名。 */
    public function array(): mixed
    {
        return $this->payload;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /** 列表形态响应（单据查询）时为 true。 */
    public function isList(): bool
    {
        return is_array($this->payload) && array_is_list($this->payload);
    }

    /**
     * 查询响应的行；其它情况返回 []。
     *
     * @return list<mixed>
     */
    public function rows(): array
    {
        return is_array($this->payload) && array_is_list($this->payload)
            ? $this->payload
            : [];
    }

    /**
     * @return array<mixed>|null
     */
    public function responseStatus(): ?array
    {
        if (!is_array($this->payload)) {
            return null;
        }
        $result = $this->payload['Result'] ?? $this->payload;
        if (is_array($result) && isset($result['ResponseStatus']) && is_array($result['ResponseStatus'])) {
            return $result['ResponseStatus'];
        }

        return null;
    }

    /**
     * 业务是否成功：存在 ResponseStatus.IsSuccess 时以其为准；否则若 HTTP 调用本身成功
     * 且未返回错误信封则为 true。
     */
    public function isSuccess(): bool
    {
        $status = $this->responseStatus();
        if ($status !== null) {
            // IsSuccess 可能是布尔，也可能是 0/1。
            return (bool) ($status['IsSuccess'] ?? false);
        }

        if (is_array($this->payload) && array_key_exists('IsSuccess', $this->payload)) {
            return (bool) $this->payload['IsSuccess'];
        }

        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * 尽力提取的单条错误信息；成功时为 null。
     */
    public function errorMessage(): ?string
    {
        $status = $this->responseStatus();
        if ($status !== null) {
            $errors = $status['Errors'] ?? [];
            if (is_array($errors) && $errors !== []) {
                $first = reset($errors);
                if (is_array($first)) {
                    return (string) ($first['Message'] ?? $first['Description'] ?? json_encode($first, JSON_UNESCAPED_UNICODE));
                }

                return (string) $first;
            }
            if (!empty($status['MsgCode']) && (int) $status['MsgCode'] !== 0) {
                return 'Operation failed (MsgCode ' . $status['MsgCode'] . ').';
            }
        }

        if (is_array($this->payload)) {
            foreach (['Message', 'Result', 'description', 'Message'] as $key) {
                if (isset($this->payload[$key]) && is_string($this->payload[$key]) && $this->payload[$key] !== '') {
                    return $this->payload[$key];
                }
            }
        }

        return $this->isSuccess() ? null : 'Unknown API error.';
    }

    /**
     * 新记录内码（Save/Draft），若存在。
     */
    public function id(): int|string|null
    {
        if (!is_array($this->payload)) {
            return null;
        }
        $result = $this->payload['Result'] ?? null;

        return is_array($result) ? ($result['Id'] ?? $result['Number'] ?? null) : null;
    }

    /**
     * 新记录编码（Save/Draft），若存在。
     */
    public function number(): ?string
    {
        if (!is_array($this->payload)) {
            return null;
        }
        $result = $this->payload['Result'] ?? null;

        return is_array($result) && isset($result['Number']) ? (string) $result['Number'] : null;
    }

    /**
     * 若操作未报告成功，则抛出 {@see ApiException}。
     */
    public function throwIfError(): self
    {
        if (!$this->isSuccess()) {
            throw new ApiException($this, $this->errorMessage() ?? 'API error.');
        }

        return $this;
    }
}
