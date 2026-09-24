<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * CDN 节点路由服务（海外加速/多节点分发）
 */
class CdnService
{
    /**
     * 获取启用节点列表
     */
    public static function nodes(): array
    {
        return Db::name('cdn_nodes')->where('status', 1)->order('weight', 'desc')->order('id', 'asc')->select()->toArray();
    }

    /**
     * 为下载文件挑选 CDN 节点（按区域偏好 + 权重轮询）
     * @param string $region 请求区域（cn/hk/us/sg/eu/global）
     */
    public static function pickNode(string $region = 'global'): ?array
    {
        $nodes = self::nodes();
        if (!$nodes) {
            return null;
        }
        // 优先区域精确匹配，其次 global，最后任一节点
        $candidates = array_values(array_filter($nodes, fn ($n) => (string) $n['region'] === $region));
        if (!$candidates) {
            $candidates = array_values(array_filter($nodes, fn ($n) => (string) $n['region'] === 'global'));
        }
        if (!$candidates) {
            $candidates = $nodes;
        }
        // 加权随机（简化：按权重重复入池后取随机）
        $pool = [];
        foreach ($candidates as $n) {
            $w = max(1, (int) $n['weight']);
            for ($i = 0; $i < $w; $i++) {
                $pool[] = $n;
            }
        }
        return $pool[random_int(0, count($pool) - 1)];
    }

    /**
     * 生成 CDN 文件 URL（支持可选签名）
     * @param array $node cdn_nodes 行
     * @param string $path 相对 storage 的文件路径，如 apps/xxx/1.0.0-1/app.apk
     * @param int $expireSeconds 签名有效期
     */
    public static function signedUrl(array $node, string $path, int $expireSeconds = 600): string
    {
        $host = rtrim((string) $node['host'], '/');
        $url  = $host . '/' . ltrim($path, '/');
        $key  = (string) ($node['secret_key'] ?? '');
        if ($key !== '') {
            // 简单时间戳+HMAC 签名：?e=过期时间戳&sign=hex(hmac_sha256(key, path+e))
            $expire = time() + $expireSeconds;
            $sign   = hash_hmac('sha256', $path . '|' . $expire, $key);
            $url   .= (strpos($url, '?') === false ? '?' : '&') . 'e=' . $expire . '&sign=' . $sign;
        }
        return $url;
    }

    /**
     * 从 IP 粗略推断区域（无第三方库，基于保留/常见前缀的兜底，生产可接 ip2region）
     */
    public static function regionOfIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === '127.0.0.1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            return 'cn';
        }
        return 'global';
    }
}