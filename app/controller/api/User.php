<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\common\UserContext;
use app\service\UcenterSyncService;
use think\response\Json;

/**
 * 前台用户中心：注册 / 登录 / 登出 / 改密 / 改邮箱 / UCenter 登录
 */
class User extends BaseController
{
    /**
     * 本站注册（① 写 UCenter）
     * POST /api/v1/user/register
     */
    public function register(): Json
    {
        $data = [
            'username' => (string) $this->request->post('username', ''),
            'password' => (string) $this->request->post('password', ''),
            'email'    => (string) $this->request->post('email', ''),
            'mobile'   => (string) $this->request->post('mobile', ''),
        ];
        $result = UcenterSyncService::register($data);
        return success([
            'user'    => $result['user'],
            'uc_sync' => $result['uc_sync'],
        ], '注册成功');
    }

    /**
     * 本站登录（② 广播同步登录 + ⑤ UCenter 用户可登录）
     * POST /api/v1/user/login
     */
    public function login(): Json
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');
        $forceUc  = (bool) $this->request->post('uc', 0);

        $result = UcenterSyncService::login($username, $password, $forceUc);
        return success($result, '登录成功');
    }

    /**
     * 登出（返回同步登出广播代码）
     * POST /api/v1/user/logout
     */
    public function logout(): Json
    {
        $result = UcenterSyncService::logout();
        return success($result, '已退出登录');
    }

    /**
     * 修改密码（③ 同步 UCenter）
     * PUT /api/v1/user/password
     */
    public function changePassword(): Json
    {
        $oldPassword = (string) $this->request->put('old_password', '');
        $newPassword = (string) $this->request->put('new_password', '');
        UcenterSyncService::changePassword(UserContext::id(), $oldPassword, $newPassword);
        return success(null, '密码修改成功');
    }

    /**
     * 修改邮箱（④ 同步 UCenter）
     * PUT /api/v1/user/email
     */
    public function changeEmail(): Json
    {
        $oldPassword = (string) $this->request->put('password', '');
        $newEmail    = (string) $this->request->put('email', '');
        UcenterSyncService::changeEmail(UserContext::id(), $oldPassword, $newEmail);
        return success(null, '邮箱修改成功');
    }

    /**
     * 当前用户信息
     * GET /api/v1/user/me
     */
    public function me(): Json
    {
        return success(UcenterSyncService::publicUser(UserContext::user()));
    }

    /**
     * 重试 UCenter 补同步（uc_sync=2 时）
     * POST /api/v1/user/sync/retry
     */
    public function retrySync(): Json
    {
        $result = UcenterSyncService::retrySync(UserContext::id());
        return success($result);
    }
}