<?php
declare (strict_types = 1);

namespace app\common;

use app\service\RbacService;

/**
 * 当前请求管理员上下文（由 AuthAdmin 中间件写入）
 * 所有读取方均位于 AuthAdmin 保护的 admin 路由组内，保证已被正确设置
 */
class AdminContext
{
    private static array $admin = [];
    private static bool $loaded = false;

    public static function set(array $admin): void
    {
        // admin_users 行中不含 roles 字段（角色通过 admin_role 关联表维护），
        // 这里仅保留安全子集，禁止把完整行透传给业务逻辑
        unset($admin['password']);
        self::$admin  = $admin;
        self::$loaded = true;
    }

    public static function id(): int
    {
        return self::$loaded ? (int) (self::$admin['id'] ?? 0) : 0;
    }

    public static function admin(): array
    {
        return self::$admin;
    }

    /**
     * 是否超级管理员（基于 admin_role 关联表实时查询，与 RbacService 口径一致）
     */
    public static function isSuper(): bool
    {
        if (!self::$loaded) {
            return false;
        }
        return RbacService::isSuper(self::id());
    }
}