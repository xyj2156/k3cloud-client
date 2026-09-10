<?php

declare(strict_types=1);

namespace K3Cloud\Auth;

/**
 * 会话 Cookie 的跨进程存储（不落盘则仅存活于当前 PHP 进程内存）。
 *
 * 键是由"含凭据配置字段"（服务器 / 账套 / 用户 / 密码 / lcid）派生出的不可逆指纹，
 * 因此换一套凭据天然落到另一个键上，旧会话不会被误复用。
 *
 * 实现必须是幂等且容错的：load() 对缺失 / 损坏 / 过期的条目一律返回 null（宁可变慢
 * 重新登录，不可返回坏数据）；save()/forget() 失败不得抛出中断业务请求的异常。
 */
interface SessionStore
{
    /**
     * 读取某键对应的会话。
     *
     * @return array{cookies: array<string,string>, saved_at: int}|null 无有效条目时返回 null
     */
    public function load(string $key): ?array;

    /**
     * 写入 / 覆盖某键对应的会话（登录成功后调用）。尽力而为：存储不可写时静默降级为
     * "仅内存会话"，不影响本次调用。
     *
     * @param array<string,string> $cookies
     */
    public function save(string $key, array $cookies): void;

    /**
     * 丢弃某键对应的会话（会话被判定失效或手动 relogin 时调用）。
     */
    public function forget(string $key): void;
}
