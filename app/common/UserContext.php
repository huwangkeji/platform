<?php
declare (strict_types = 1);

namespace app\common;

/**
 * 当前请求前台用户上下文（由 AuthUser 中间件写入）
 * 仅在 AuthUser 保护的路由组内可安全读取
 */
class UserContext
{
    private static array $user = [];
    private static bool $loaded = false;

    public static function set(array $user): void
    {
        self::$user  = $user;
        self::$loaded = true;
    }

    public static function id(): int
    {
        return self::$loaded ? (int) (self::$user['id'] ?? 0) : 0;
    }

    public static function user(): array
    {
        return self::$user;
    }
}