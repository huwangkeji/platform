<?php
declare (strict_types = 1);

namespace app\middleware;

use app\common\AdminContext;
use app\exception\BizException;
use app\service\RbacService;
use app\service\TokenService;
use Closure;
use think\facade\Db;
use think\Request;

/**
 * 后台管理员认证中间件（Bearer Token）
 */
class AuthAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $auth  = (string) $request->header('authorization', '');
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            $token = trim($m[1]);
        }
        if ($token === '') {
            throw BizException::unauth();
        }

        $payload = TokenService::verify($token);
        if (!$payload || empty($payload['uid'])) {
            throw BizException::unauth('登录状态已失效，请重新登录');
        }
        // 仅接受 scope=admin 的后台管理员令牌，防止 user 作用域令牌（uid 与管理员工号冲突时）越权访问后台接口
        if (($payload['scope'] ?? '') !== 'admin') {
            throw BizException::unauth('令牌作用域不正确，请使用管理员账号重新登录');
        }

        $admin = Db::name('admin_users')->where('id', (int) $payload['uid'])->find();
        if (!$admin || (int) $admin['status'] !== 1) {
            throw BizException::unauth('账号不存在或已被禁用');
        }

        AdminContext::set($admin);
        return $next($request);
    }
}