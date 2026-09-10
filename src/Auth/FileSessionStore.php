<?php

declare(strict_types=1);

namespace K3Cloud\Auth;

/**
 * 以文件形式持久化会话 Cookie，使短生命周期进程（CLI 脚本、cron、队列 worker、
 * per-request 的 Web 进程）不必每次都执行一次 ValidateUser 登录。
 *
 * 布局：目录内每个身份一个文件 "session-<key>.json"，内容为
 * {"version":1,"saved_at":<时间戳>,"cookies":{...}}。
 *
 * 安全注意：kdsessionid 等同一个短期有效的登录凭据。文件以 0600 权限写入，目录以
 * 0700 创建；请将该目录放在仅应用用户可读的位置（多用户服务器上不要用共享 /tmp 也可以
 * 自定义目录）。过期判断有意不在这里做：服务端会话失效由 SessionAuth 的
 * "会话丢失 → 重登 → 重放"链路自愈，读到坏文件最多等于没缓存。
 *
 * 写盘采用"临时文件 + 改名"的原子替换，多个进程并发读写不会观察到半截文件。
 * 所有 IO 错误都被吞掉并降级为"不落盘"——存储只是优化，从不该让业务请求失败。
 */
final class FileSessionStore implements SessionStore
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? self::defaultDir(), '\\/');
    }

    /**
     * 未显式指定目录时的默认落盘位置：系统临时目录下的 k3cloud-sessions。
     */
    public static function defaultDir(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k3cloud-sessions';
    }

    public function directory(): string
    {
        return $this->dir;
    }

    public function load(string $key): ?array
    {
        $file = $this->path($key);
        if ($file === null || !is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1) {
            return null;
        }

        $cookies = $data['cookies'] ?? null;
        if (!is_array($cookies) || $cookies === []) {
            return null;
        }
        foreach ($cookies as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                return null;
            }
        }

        return ['cookies' => $cookies, 'saved_at' => (int) ($data['saved_at'] ?? 0)];
    }

    public function save(string $key, array $cookies): void
    {
        $file = $this->path($key);
        if ($file === null || $cookies === [] || !$this->ensureDir()) {
            return;
        }

        $json = json_encode(
            ['version' => 1, 'saved_at' => time(), 'cookies' => $cookies],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            return;
        }

        // 先写同目录临时文件（0600），再原子改名覆盖，避免读到半截内容。
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @chmod($tmp, 0600);

        // Windows 的 rename 不覆盖已存在文件，先删旧件（本目录只存放本存储自己的文件）。
        if (is_file($file)) {
            @unlink($file);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            // 最后兜底：直接覆写（非原子，但好于丢失整次落盘）。
            if (@file_put_contents($file, $json) !== false) {
                @chmod($file, 0600);
            }
        }
    }

    public function forget(string $key): void
    {
        $file = $this->path($key);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * 键本身是凭据指纹（十六进制散列）；仍过滤一次字符集，杜绝任何路径注入。
     */
    private function path(string $key): ?string
    {
        $sanitised = preg_replace('/[^0-9a-f]/', '', $key);
        if ($sanitised === null || $sanitised === '') {
            return null;
        }

        return $this->dir . DIRECTORY_SEPARATOR . 'session-' . $sanitised . '.json';
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }

        @mkdir($this->dir, 0700, true);
        clearstatcache(true, $this->dir);

        return is_dir($this->dir);
    }
}
