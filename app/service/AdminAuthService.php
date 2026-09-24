<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\BizException;
use think\facade\Cache;
use think\facade\Db;

/**
 * 后台管理员认证：登录 / 失败锁定 / Token 签发
 */
class AdminAuthService
{
    private const MAX_FAIL      = 5;
    private const LOCK_SECONDS  = 900; // 15 分钟

    public static function login(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw BizException::param('请输入账号和密码');
        }
        self::checkLock($username);
        $failKey = 'admin_login_fail_' . md5($username);

        $admin = Db::name('admin_users')->where('username', $username)->find();
        if (!$admin || !password_verify($password, $admin['password'])) {
            self::recordFail($failKey);
            throw BizException::failed('账号或密码错误');
        }
        if ((int) $admin['status'] !== 1) {
            throw BizException::forbidden('账号已被禁用');
        }

        Cache::delete($failKey);
        $now = datetime_now();
        Db::name('admin_users')->where('id', $admin['id'])->update([
            'last_login_ip' => (string) request()->ip(),
            'last_login_at' => $now,
            'updated_at'    => $now,
        ]);

        $token = TokenService::issue((int) $admin['id']);
        OperationLogService::write((int) $admin['id'], 'auth', 'login', (int) $admin['id'], '管理员登录：' . $admin['username']);

        return [
            'token'      => $token['token'],
            'expires_in' => $token['expires_in'],
            'admin'      => [
                'id'       => (int) $admin['id'],
                'username' => (string) $admin['username'],
                'nickname' => (string) ($admin['nickname'] ?? ''),
            ],
        ];
    }

    public static function logout(int $adminId): void
    {
        $admin = Db::name('admin_users')->where('id', $adminId)->find();
        OperationLogService::write($adminId, 'auth', 'logout', $adminId, '管理员退出登录：' . ($admin['username'] ?? ''));
    }

    private static function checkLock(string $username): void
    {
        $key  = 'admin_login_fail_' . md5($username);
        $info = Cache::get($key);
        if (is_array($info) && ($info['count'] ?? 0) >= self::MAX_FAIL) {
            $remain = self::LOCK_SECONDS - (time() - (int) ($info['first_at'] ?? time()));
            if ($remain > 0) {
                throw new BizException('登录失败次数过多，请 ' . (int) ceil($remain / 60) . ' 分钟后再试', 423, 423);
            }
            Cache::delete($key);
        }
    }

    private static function recordFail(string $key): void
    {
        $info = Cache::get($key);
        if (!is_array($info)) {
            $info = ['count' => 0, 'first_at' => time()];
        }
        $info['count']++;
        Cache::set($key, $info, self::LOCK_SECONDS);
    }
}