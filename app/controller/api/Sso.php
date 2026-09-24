<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\SsoService;
use think\facade\Db;
use think\facade\Session;
use think\Response;
use think\response\Redirect;
use think\response\Json;

/**
 * SSO 单点登录：授权码模式入口/回调
 */
class Sso extends BaseController
{
    /**
     * 登录入口：选择提供商跳转授权页
     * GET /api/v1/sso/login?provider=wechat_work
     */
    public function login(): Redirect|Json
    {
        $provider = strtolower((string) $this->request->get('provider', ''));
        if ($provider === '') {
            // 未指定提供商：返回启用列表供前端选择
            $providers = SsoService::enabledProviders();
            return success(array_map(fn ($p) => [
                'provider' => $p['provider'],
                'name'     => $p['name'],
                'login_url'=> '/api/v1/sso/login?provider=' . $p['provider'],
            ], $providers));
        }
        $row = Db::name('sso_providers')->where('provider', $provider)->where('enabled', 1)->find();
        if (!$row) {
            return fail(10004, 'SSO 提供商未启用或不存在');
        }
        $state = bin2hex(random_bytes(16));
        Session::set('sso_state', $state);
        Session::set('sso_provider', $provider);
        $url = SsoService::authorizeUrl($row, $state);
        return redirect($url)->code(302);
    }

    /**
     * 授权回调
     * GET /api/v1/sso/callback?code=xxx&state=xxx
     */
    public function callback(): Redirect|Json
    {
        $code  = (string) $this->request->get('code', '');
        $state = (string) $this->request->get('state', '');
        if ($code === '' || $state === '') {
            return fail(10003, '缺少 code/state 参数');
        }
        $savedState = (string) Session::get('sso_state', '');
        if ($savedState !== '' && $savedState !== $state) {
            return fail(10001, 'state 校验失败，存在 CSRF 风险');
        }
        $provider = (string) (Session::get('sso_provider') ?: $this->request->get('provider', ''));
        $row = Db::name('sso_providers')->where('provider', $provider)->find();
        if (!$row) {
            return fail(10004, 'SSO 提供商不存在');
        }

        try {
            $user = SsoService::exchange($row, $code);
        } catch (\Throwable $e) {
            return fail(10006, 'SSO 登录失败：' . $e->getMessage());
        }

        // 绑定/创建本地用户
        $uid = $this->bindLocalUser($user);
        Session::set('user_id', $uid);
        Session::set('user_username', $user['username'] !== '' ? $user['username'] : $user['uid']);
        Session::delete('sso_state');
        Session::delete('sso_provider');

        // 回调后跳转前台（前端可通过 return_url 指定）
        $returnUrl = (string) $this->request->get('return_url', '/');
        return redirect($returnUrl)->code(302);
    }

    /**
     * 根据 SSO 用户信息绑定本地 users 表（邮箱/UID 幂等）
     */
    protected function bindLocalUser(array $user): int
    {
        $now  = datetime_now();
        $row  = null;
        if ($user['email'] !== '') {
            $row = Db::name('users')->where('email', $user['email'])->find();
        }
        if (!$row && $user['uid'] !== '') {
            $row = Db::name('users')->where('username', $user['username'] ?: $user['uid'])->find();
        }
        if ($row) {
            Db::name('users')->where('id', (int) $row['id'])->update([
                'last_login_at' => $now,
                'updated_at'    => $now,
            ]);
            return (int) $row['id'];
        }
        return (int) Db::name('users')->insertGetId([
            'username'      => $user['username'] !== '' ? $user['username'] : $user['uid'],
            'email'         => $user['email'] !== '' ? $user['email'] : null,
            'mobile'        => $user['mobile'] !== '' ? $user['mobile'] : null,
            'status'        => 1,
            'register_at'   => $now,
            'last_login_at' => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }
}