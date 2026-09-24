<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\SymbolService;
use think\facade\Db;
use think\response\Json;

/**
 * 崩溃上报（APP 端公开）
 */
class Crash extends BaseController
{
    /**
     * POST /api/v1/crash/report
     */
    public function report(): Json
    {
        $appCode = trim((string) $this->request->post('app_code', ''));
        if ($appCode === '') {
            return fail(10002, 'app_code 不能为空');
        }
        $app = $this->ensureApp($appCode);

        $versionName = (string) $this->request->post('version_name', '');
        $versionCode = (int) $this->request->post('version_code', 0);

        $deviceIdStr = (string) $this->request->post('device_id', '');
        $device      = null;
        $userId      = null;
        if ($deviceIdStr !== '') {
            $device = Db::name('devices')->where('device_id', $deviceIdStr)->find();
            $userId = $device && (int) ($device['user_id'] ?? 0) > 0 ? (int) $device['user_id'] : null;
        }

        $crashId = (int) Db::name('crash_reports')->insertGetId([
            'app_id'        => (int) $app['id'],
            'user_id'       => $userId,
            'device_id'     => $device ? (int) $device['id'] : null,
            'version_code'  => $versionCode ?: null,
            'version_name'  => $versionName,
            'device_model'  => (string) $this->request->post('device_model', ''),
            'os_version'    => (string) $this->request->post('os_version', ''),
            'error_message' => (string) $this->request->post('error_message', ''),
            'stack_trace'   => (string) $this->request->post('stack_trace', ''),
            'log_file'      => (string) $this->request->post('log_file', ''),
            'occurred_at'   => (string) $this->request->post('occurred_at', '') ?: datetime_now(),
            'created_at'    => datetime_now(),
        ]);

        // 若平台为 android 且有匹配符号文件，则立即符号化（失败不影响上报结果）
        if ($crashId > 0) {
            SymbolService::symbolizeCrash($crashId);
        }

        return success(['crash_id' => $crashId], '上报成功');
    }
}