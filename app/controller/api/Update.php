<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\ExperimentService;
use app\service\VersionDecisionService;
use think\response\Json;

/**
 * 更新检测（APP 端核心接口）
 */
class Update extends BaseController
{
    /**
     * 检查更新
     * GET /api/v1/app/update/check
     */
    public function check(): Json
    {
        $appCode = trim((string) $this->request->get('app_code', ''));
        if ($appCode === '') {
            return fail(10002, 'app_code 不能为空');
        }
        $app = $this->ensureApp($appCode);

        $params = [
            'app_id'       => (int) $app['id'],
            'version_code' => (int) $this->request->get('version_code', 0),
            'version_name' => (string) $this->request->get('version_name', ''),
            'platform'     => strtolower((string) $this->request->get('platform', 'android')),
            'os_version'   => (string) $this->request->get('os_version', ''),
            'architecture' => (string) $this->request->get('architecture', ''),
            'channel'      => (string) $this->request->get('channel', 'official'),
            'device_id'    => (string) $this->request->get('device_id', ''),
            'user_id'      => (string) $this->request->get('user_id', ''),
        ];

        // A/B 实验分流（Phase 3-5）：命中运行中实验则采用实验组版本，否则走默认决策
        $experiment = ExperimentService::match($params);
        $version = null;
        if ($experiment) {
            $version = \think\facade\Db::name('app_versions')->where('id', (int) $experiment['version_id'])->find();
        }
        if (!$version) {
            $version = VersionDecisionService::decide($app, $params);
        }
        VersionDecisionService::logCheck($app, $params, $version);

        $resp = VersionDecisionService::response($version, $params['version_code']);
        if ($experiment) {
            $resp['experiment'] = [
                'id'         => (int) $experiment['experiment_id'],
                'name'       => (string) $experiment['experiment_name'],
                'group'      => (string) $experiment['group_tag'],
            ];
        }
        return success($resp);
    }
}