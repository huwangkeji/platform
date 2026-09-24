<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\response\Json;

/**
 * 应用信息（APP 端公开）
 */
class App extends BaseController
{
    /**
     * 启用应用列表（官方首页）
     * GET /api/v1/app/list
     */
    public function list(): Json
    {
        $apps = Db::name('apps')
            ->where('status', 1)
            ->order('sort_order', 'desc')->order('id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        $list = array_map(function ($app) {
            $live = Db::name('app_versions')
                ->where('app_id', (int) $app['id'])
                ->whereIn('status', ['published', 'gray'])
                ->order('version_code', 'desc')
                ->find();
            return [
                'id'             => (int) $app['id'],
                'name'           => (string) $app['name'],
                'code'           => (string) $app['code'],
                'icon'           => (string) ($app['icon'] ?? ''),
                'description'    => (string) ($app['description'] ?? ''),
                'latest_version' => $live ? (string) $live['version_name'] : '',
                'published_at'   => $live ? (string) ($live['published_at'] ?? '') : '',
            ];
        }, $apps);

        return success($list);
    }

    /**
     * 应用信息
     * GET /api/v1/app/info?app_code=xxx
     */
    public function info(): Json
    {
        $code = trim((string) $this->request->get('app_code', ''));
        if ($code === '') {
            return fail(10002, 'app_code 不能为空');
        }
        $app = $this->ensureApp($code);

        return success([
            'id'           => (int) $app['id'],
            'name'         => (string) $app['name'],
            'code'         => (string) $app['code'],
            'package_name' => (string) ($app['package_name'] ?? ''),
            'bundle_id'    => (string) ($app['bundle_id'] ?? ''),
            'icon'         => (string) ($app['icon'] ?? ''),
            'description'  => (string) ($app['description'] ?? ''),
            'website'      => (string) ($app['website'] ?? ''),
            'privacy_url'  => (string) ($app['privacy_url'] ?? ''),
            'agreement_url'=> (string) ($app['agreement_url'] ?? ''),
            'contact'      => (string) ($app['contact'] ?? ''),
        ]);
    }
}