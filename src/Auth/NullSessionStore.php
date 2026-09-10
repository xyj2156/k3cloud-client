<?php

declare(strict_types=1);

namespace K3Cloud\Auth;

/**
 * 空实现：会话只保留在进程内存中（1.0 的行为）。用于显式关闭落盘。
 */
final class NullSessionStore implements SessionStore
{
    public function load(string $key): ?array
    {
        return null;
    }

    public function save(string $key, array $cookies): void
    {
    }

    public function forget(string $key): void
    {
    }
}
