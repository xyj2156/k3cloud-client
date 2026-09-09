<?php

declare(strict_types=1);

namespace K3Cloud\Support;

/**
 * Minimal, case-insensitive cookie store used to persist the K/3 Cloud
 * session id between the login request and subsequent API calls.
 */
final class CookieJar
{
    /** @var array<string,string> name => value (names stored lower-cased) */
    private array $cookies = [];

    public function storeFromSetCookie(string $setCookieHeader): void
    {
        // A single Set-Cookie value: "name=value; Path=/; HttpOnly".
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
