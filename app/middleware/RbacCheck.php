<?php
declare (strict_types = 1);

namespace app\middleware;

use app\common\AdminContext;
use app\exception\BizException;
use app\service\RbacService;
use Closure;
use think\Request;

/**
 * RBAC 权限校验中间件
 * 用法：->middleware(\app\middleware\RbacCheck::class, 'app.edit')
 * 具备任一列出的权限 code 即放行
 */
class RbacCheck
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $adminId = AdminContext::id();
        foreach ($permissions as $perm) {
            if (RbacService::has($adminId, $perm)) {
                return $next($request);
            }
        }
        throw BizException::forbidden();
    }
}