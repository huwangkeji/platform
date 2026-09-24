<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\AdminAuthService;
use think\response\Json;

/**
 * 管理端认证（公开登录）
 */
class Admin extends BaseController
{
    /**
     * 管理员登录
     */
    public function login(): Json
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');

        if ($username === '' || $password === '') {
            return fail(10001, '请输入用户名和密码');
        }

        $data = AdminAuthService::login($username, $password);
        return success($data, '登录成功');
    }

    /**
     * 退出登录
     */
    public function logout(): Json
    {
        AdminAuthService::logout($this->adminId());
        return success(null, '已退出登录');
    }
}