<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * RBAC 权限服务：管理员 -> 角色 -> 权限
 */
class RbacService
{
    private const PERM_CACHE = 300; // 秒

    /**
     * 获取管理员全部权限 code（超级管理员返回 ['*']）
     */
    public static function permissions(int $adminId): array
    {
        $key  = 'rbac_perms_' . $adminId;
        $list = Cache::get($key);
        if (is_array($list)) {
            return $list;
        }

        $roleIds = Db::name('admin_role')->where('admin_id', $adminId)->column('role_id');
        $codes   = [];
        if ($roleIds) {
            $super = Db::name('roles')->where('code', 'super_admin')->value('id');
            if ($super && in_array((int) $super, array_map('intval', $roleIds), true)) {
                $codes = ['*'];
            } else {
                $valid = Db::name('roles')->whereIn('id', $roleIds)->where('status', 1)->column('id');
                if ($valid) {
                    $codes = array_values(array_unique(array_map('strval', Db::name('role_permission')
                        ->alias('rp')
                        ->join('permissions p', 'rp.permission_id = p.id')
                        ->whereIn('rp.role_id', $valid)
                        ->column('p.code'))));
                }
            }
        }
        Cache::set($key, $codes, self::PERM_CACHE);
        return $codes;
    }

    public static function has(int $adminId, string $code): bool
    {
        $codes = self::permissions($adminId);
        return in_array('*', $codes, true) || in_array($code, $codes, true);
    }

    public static function invalidate(int $adminId): void
    {
        Cache::delete('rbac_perms_' . $adminId);
    }

    public static function isSuper(int $adminId): bool
    {
        return in_array('*', self::permissions($adminId), true);
    }
}