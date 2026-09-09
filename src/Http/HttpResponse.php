<?php

declare(strict_types=1);

namespace K3Cloud\Http;

/**
 * 由传输层实现返回的原始 HTTP 响应。
 */
final class HttpResponse
{
    /**
     * @param array<string,list<string>> $headers 小写头名 -> 值列表。
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;

        return $values === null ? null : ($values[0] ?? null);
    }

    /** @return list<string> */
    public function headerAll(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
