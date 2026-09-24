<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\SsoService;
use think\facade\Db;
use think\response\Json;

/**
 * SSO 提供商配置管理
 */
class SsoProviders extends BaseController
{
    /**
     * 提供商列表
     */
    public function index(): Json
    {
        $list = Db::name('sso_providers')->order('id', 'asc')->select()->toArray();
        foreach ($list as &$p) {
            // 脱敏：密钥不完整返回
            $p['client_secret'] = $p['client_secret'] ? substr((string) $p['client_secret'], 0, 4) . '****' : '';
        }
        unset($p);
        return success(['list' => $list]);
    }

    /**
     * 创建/更新提供商
     * POST /api/v1/admin/sso-providers
     * body: provider, name, client_id, client_secret, authorize_url, token_url, userinfo_url, scopes, mapping, redirect_url, enabled
     */
    public function create(): Json
    {
        $provider = strtolower((string) $this->request->post('provider', ''));
        $name     = trim((string) $this->request->post('name', ''));
        if ($provider === '' || $name === '') {
            return fail(10003, 'provider/name 不能为空');
        }
        if (!in_array($provider, SsoService::PROVIDERS, true)) {
            return fail(10003, '不支持的提供商类型');
        }
        $now = datetime_now();
        $data = [
            'provider'       => $provider,
            'name'           => $name,
            'client_id'      => (string) $this->request->post('client_id', ''),
            'client_secret'  => (string) $this->request->post('client_secret', ''),
            'authorize_url'  => (string) $this->request->post('authorize_url', ''),
            'token_url'      => (string) $this->request->post('token_url', ''),
            'userinfo_url'   => (string) $this->request->post('userinfo_url', ''),
            'scopes'         => (string) $this->request->post('scopes', ''),
            'mapping'        => (string) $this->request->post('mapping', ''),
            'config'         => (string) $this->request->post('config', ''),
            'redirect_url'   => (string) $this->request->post('redirect_url', ''),
            'enabled'        => (int) $this->request->post('enabled', 0) === 1 ? 1 : 0,
            'updated_at'     => $now,
        ];
        $exists = Db::name('sso_providers')->where('provider', $provider)->find();
        if ($exists) {
            // 密钥留空表示不修改
            if ($data['client_secret'] === '' || substr($data['client_secret'], -4) === '****') {
                unset($data['client_secret']);
            }
            Db::name('sso_providers')->where('id', (int) $exists['id'])->update($data);
            $id = (int) $exists['id'];
            $msg = '配置已更新';
        } else {
            $data['created_at'] = $now;
            $id = (int) Db::name('sso_providers')->insertGetId($data);
            $msg = '配置已创建';
        }
        $this->opLog('sso', 'save', $id, '保存SSO提供商 ' . $provider . '（' . $name . '） enabled=' . $data['enabled']);
        return success(['id' => $id], $msg);
    }

    /**
     * 启停提供商
     */
    public function toggle(): Json
    {
        $id = (int) $this->request->param('id');
        $p  = Db::name('sso_providers')->where('id', $id)->find();
        if (!$p) {
            throw BizException::notFound('提供商不存在');
        }
        $new = (int) $p['enabled'] === 1 ? 0 : 1;
        Db::name('sso_providers')->where('id', $id)->update(['enabled' => $new, 'updated_at' => datetime_now()]);
        $this->opLog('sso', 'toggle', $id, 'SSO提供商 ' . $p['name'] . ' → ' . ($new ? '启用' : '停用'));
        return success(['enabled' => $new], '已更新');
    }

    /**
     * 删除提供商
     */
    public function delete(): Json
    {
        $id = (int) $this->request->param('id');
        $p  = Db::name('sso_providers')->where('id', $id)->find();
        if (!$p) {
            throw BizException::notFound('提供商不存在');
        }
        Db::name('sso_providers')->where('id', $id)->delete();
        $this->opLog('sso', 'delete', $id, '删除SSO提供商 ' . $p['name']);
        return success(null, '删除成功');
    }
}