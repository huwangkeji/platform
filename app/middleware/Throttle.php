<?php
declare (strict_types = 1);

namespace app\middleware;

use app\exception\BizException;
use Closure;
use think\facade\Cache;
use think\Request;

/**
 * 基于 IP + UA 的滑动窗口限流中间件（公开接口防刷）
 *
 * 用法：->middleware(\app\middleware\Throttle::class, 'feedback:20:60')
 * 参数格式：{name}:{limit}:{seconds}，缺省为 30 次 / 60 秒
 * 超出限制返回 429，防止设备注册/反馈/崩溃上报等公开接口被刷库与灌盘。
 */
class Throttle
{
    public function handle(Request $request, Closure $next, string ...$args)
    {
        $limit = 30;
        $ttl   = 60;
        $name  = 'api';

        $raw = (string) ($args[0] ?? '');
        if ($raw !== '') {
            [$n, $l, $t] = array_pad(explode(':', $raw, 3), 3, '');
            $n = preg_replace('/[^a-z0-9_]/i', '', $n);
            if ($n !== '') {
                $name = $n;
            }
            if ($l !== '' && (int) $l > 0) {
                $limit = (int) $l;
            }
            if ($t !== '' && (int) $t > 0) {
                $ttl = (int) $t;
            }
        }

        $ip  = (string) $request->ip();
        $ua  = (string) $request->header('user-agent', '');
        $key = 'throttle_' . $name . '_' . md5($ip . '|' . $ua);

        $count = (int) Cache::get($key, 0);
        if ($count >= $limit) {
            throw new BizException('请求过于频繁，请稍后再试', 429, 429);
        }
        Cache::set($key, $count + 1, $ttl);

        return $next($request);
    }
}