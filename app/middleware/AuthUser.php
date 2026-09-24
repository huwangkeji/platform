<?php
declare (strict_types = 1);

namespace app\middleware;

use app\common\UserContext;
use app\exception\BizException;
use app\service\TokenService;
use Closure;
use think\facade\Db;
use think\Request;

/**
 * 前台用户认证中间件（Bearer Token，scope=user）
 */
class AuthUser
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
        if (($payload['scope'] ?? '') !== 'user') {
            throw BizException::unauth('Token 类型不适用于前台用户接口');
        }

        $user = Db::name('users')->where('id', (int) $payload['uid'])->find();
        if (!$user || (int) $user['status'] !== 1) {
            throw BizException::unauth('账号不存在或已被禁用');
        }

        UserContext::set($user);
        return $next($request);
    }
}