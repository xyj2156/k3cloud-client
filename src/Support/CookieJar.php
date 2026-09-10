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

    /**
     * 直接写入 / 覆盖一个 Cookie（名称保持大小写不敏感）。用于从持久化存储水合会话。
     */
    public function set(string $name, string $value): void
    {
        $name = trim($name);
        if ($name !== '') {
            $this->cookies[strtolower($name)] = $value;
        }
    }

    /**
     * 由 SessionStore 读回的 "名称 => 值" 映射水合一个 CookieJar。
     *
     * @param array<string,string> $cookies
     */
    public static function fromArray(array $cookies): self
    {
        $jar = new self();
        foreach ($cookies as $name => $value) {
            $jar->set((string) $name, (string) $value);
        }

        return $jar;
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
