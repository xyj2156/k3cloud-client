<?php

declare(strict_types=1);

namespace K3Cloud\Support;

/**
 * 极简、大小写不敏感的 Cookie 存储，用于在登录请求与后续 API 调用之间保留 K/3 Cloud 会话 id。
 */
final class CookieJar
{
    /** @var array<string,string> 名称 => 值（名称以小写存储） */
    private array $cookies = [];

    public function storeFromSetCookie(string $setCookieHeader): void
    {
        // 单个 Set-Cookie 值形如："name=value; Path=/; HttpOnly"。
        $pair = trim(explode(';', $setCookieHeader, 2)[0]);
        if ($pair === '' || !str_contains($pair, '=')) {
            return;
        }
        [$name, $value] = explode('=', $pair, 2);
        $name = trim($name);
        if ($name !== '') {
            $this->cookies[strtolower($name)] = trim($value);
        }
    }

    public function get(string $name): ?string
    {
        return $this->cookies[strtolower($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->cookies[strtolower($name)]);
    }

    public function clear(): void
    {
        $this->cookies = [];
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->cookies;
    }
}
