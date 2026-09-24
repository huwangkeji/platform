<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\VersionDecisionService;
use think\facade\Db;
use think\response\Json;

/**
 * 版本查询（APP 端公开）
 */
class Version extends BaseController
{
    /**
     * 最新版本（复用决策引擎：version_code=0 视为全新安装）
     * GET /api/v1/app/version/latest
     */
    public function latest(): Json
    {
        $appCode = trim((string) $this->request->get('app_code', ''));
        if ($appCode === '') {
            return fail(10002, 'app_code 不能为空');
        }
        $app = $this->ensureApp($appCode);

        $params = [
            'version_code' => 0,
            'version_name' => '',
            'platform'     => strtolower((string) $this->request->get('platform', 'android')),
            'os_version'   => (string) $this->request->get('os_version', ''),
            'architecture' => (string) $this->request->get('architecture', ''),
            'channel'      => (string) $this->request->get('channel', 'official'),
            'device_id'    => (string) $this->request->get('device_id', ''),
            'user_id'      => (string) $this->request->get('user_id', ''),
        ];

        $version = VersionDecisionService::decide($app, $params);
        if (!$version) {
            return success(['has_version' => false]);
        }

        $appRow    = $app;
        $domain    = rtrim((string) $this->request->domain(), '/');
        return success([
            'has_version'  => true,
            'version'      => VersionDecisionService::response($version, 0),
            'app'          => [
                'id'   => (int) $appRow['id'],
                'name' => (string) $appRow['name'],
                'code' => (string) $appRow['code'],
            ],
            'download_url' => $domain . '/download/' . (int) $version['id'],
        ]);
    }

    /**
     * 历史版本列表（仅展示已发布/灰度版本）
     * GET /api/v1/app/version/history?app_code=xxx&platform=android
     */
    public function history(): Json
    {
        $appCode = trim((string) $this->request->get('app_code', ''));
        if ($appCode === '') {
            return fail(10002, 'app_code 不能为空');
        }
        $app = $this->ensureApp($appCode);

        $platform = strtolower((string) $this->request->get('platform', ''));
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));

        $query = Db::name('app_versions')
            ->where('app_id', (int) $app['id'])
            ->whereIn('status', ['published', 'gray'])
            ->order('version_code', 'desc');
        if ($platform !== '') {
            $query->where('platform', $platform);
        }

        $total = $query->count();
        $list  = $query->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $versions = array_map(function ($v) {
            return [
                'version_id'   => (int) $v['id'],
                'version_name' => (string) $v['version_name'],
                'version_code' => (int) $v['version_code'],
                'platform'     => (string) $v['platform'],
                'release_title'=> (string) ($v['release_title'] ?? ''),
                'release_note' => (string) ($v['release_note'] ?? ''),
                'file_size'    => (int) ($v['file_size'] ?? 0),
                'file_name'    => (string) ($v['file_name'] ?? ''),
                'md5'          => (string) ($v['md5'] ?? ''),
                'sha256'       => (string) ($v['sha256'] ?? ''),
                'download_url' => VersionDecisionService::downloadUrl($v),
                'published_at' => (string) ($v['published_at'] ?? ''),
            ];
        }, $list);

        return success(page_result($versions, (int) $total, $page, $pageSize));
    }
}