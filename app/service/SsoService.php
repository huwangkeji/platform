<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\BizException;
use think\facade\Db;
use think\facade\Request;

/**
 * SSO 单点登录服务：OAuth2 授权码模式统一入口
 * 支持企业微信/钉钉/通用 OAuth2 提供商；SAML/AD 预留适配
 */
class SsoService
{
    public const PROVIDERS = ['wechat_work', 'dingtalk', 'oauth2', 'saml', 'ad'];

    /**
     * 获取启用的提供商
     */
    public static function enabledProviders(): array
    {
        return Db::name('sso_providers')->where('enabled', 1)->select()->toArray();
    }

    /**
     * 构建授权跳转 URL（OAuth2 授权码模式）
     */
    public static function authorizeUrl(array $provider, string $state): string
    {
        $authorize = (string) ($provider['authorize_url'] ?? '');
        if ($authorize === '') {
            throw BizException::param('提供商未配置授权地址');
        }
        $redirect = (string) ($provider['redirect_url'] ?? '');
        if ($redirect === '') {
            $redirect = rtrim((string) Request::domain(), '/') . '/api/v1/sso/callback';
        }
        $clientId = (string) ($provider['client_id'] ?? '');
        $scopes   = (string) ($provider['scopes'] ?? 'snsapi_base');
        $sep      = strpos($authorize, '?') === false ? '?' : '&';
        return $authorize . $sep . http_build_query([
            'appid'         => $clientId,   // 企业微信
            'client_id'     => $clientId,   // 通用 OAuth2
            'redirect_uri'  => $redirect,
            'response_type' => 'code',
            'scope'         => $scopes,
            'state'         => $state,
        ]);
    }

    /**
     * 回调：用 code 换取用户信息并返回统一用户数据（username/email/mobile/uid）
     * @return array{provider:string, username:string, email:string, mobile:string, uid:string}
     */
    public static function exchange(array $provider, string $code): array
    {
        $tokenUrl = (string) ($provider['token_url'] ?? '');
        $infoUrl  = (string) ($provider['userinfo_url'] ?? '');
        $clientId = (string) ($provider['client_id'] ?? '');
        $secret   = (string) ($provider['client_secret'] ?? '');
        if ($tokenUrl === '' || $infoUrl === '') {
            // 未配置 token/userinfo 时，无法继续（演示模式：返回占位说明）
            throw BizException::failed('该 SSO 提供商未配置令牌/用户信息接口');
        }

        $tokenResp = self::httpPost($tokenUrl, [
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'code'          => $code,
        ]);
        $accessToken = (string) ($tokenResp['access_token'] ?? '');
        if ($accessToken === '') {
            throw BizException::failed('SSO 换取令牌失败：' . json_encode($tokenResp, JSON_UNESCAPED_UNICODE));
        }

        $userRaw = self::httpGet($infoUrl, ['access_token' => $accessToken]);
        $mapping = json_decode((string) ($provider['mapping'] ?? ''), true) ?: [
            'username' => ['username', 'name', 'nickname'],
            'email'    => ['email', 'mail'],
            'mobile'   => ['mobile', 'phone'],
            'uid'      => ['userid', 'user_id', 'openid', 'id'],
        ];

        return [
            'provider' => (string) $provider['provider'],
            'username' => self::pick($userRaw, $mapping['username'] ?? []),
            'email'    => self::pick($userRaw, $mapping['email'] ?? []),
            'mobile'   => self::pick($userRaw, $mapping['mobile'] ?? []),
            'uid'      => self::pick($userRaw, $mapping['uid'] ?? []),
        ];
    }

    protected static function pick(array $data, array $keys): string
    {
        foreach ($keys as $k) {
            $v = $data[$k] ?? null;
            if ($v !== null && $v !== '') {
                return (string) $v;
            }
        }
        return '';
    }

    protected static function httpPost(string $url, array $params): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = (string) curl_exec($ch);
        curl_close($ch);
        return json_decode($resp, true) ?: [];
    }

    protected static function httpGet(string $url, array $params): array
    {
        $sep = strpos($url, '?') === false ? '?' : '&';
        $ch  = curl_init($url . $sep . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = (string) curl_exec($ch);
        curl_close($ch);
        return json_decode($resp, true) ?: [];
    }
}