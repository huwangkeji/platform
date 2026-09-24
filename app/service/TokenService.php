<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Config;

/**
 * 无状态 Token 服务（HS256 签名，密钥来自 config app.app_key）
 */
class TokenService
{
    /**
     * 签发 Token
     * @param int $uid 用户/管理员 ID
     * @param int $ttl 有效期（秒），0 使用配置默认值
     * @param string $scope admin=后台管理员 user=前台用户
     */
    public static function issue(int $uid, int $ttl = 0, string $scope = 'admin'): array
    {
        $ttl = $ttl > 0 ? $ttl : (int) Config::get('app.jwt_ttl', 7200);
        $key = (string) Config::get('app.app_key', '');
        $now = time();

        $payload = ['uid' => $uid, 'scope' => $scope, 'iat' => $now, 'exp' => $now + $ttl];
        $header  = self::b64Encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_UNESCAPED_UNICODE));
        $body    = self::b64Encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $sig     = self::b64Encode(hash_hmac('sha256', $header . '.' . $body, $key, true));

        return ['token' => $header . '.' . $body . '.' . $sig, 'expires_in' => $ttl];
    }

    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $sig] = $parts;
        $key    = (string) Config::get('app.app_key', '');
        $expect = self::b64Encode(hash_hmac('sha256', $header . '.' . $body, $key, true));
        if (!hash_equals($expect, $sig)) {
            return null;
        }
        $payload = json_decode(self::b64Decode($body), true);
        if (!is_array($payload) || empty($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64Encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64Decode(string $b64): string
    {
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($b64, true) ?: '';
    }
}