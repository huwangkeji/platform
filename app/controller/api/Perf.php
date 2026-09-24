<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\response\Json;

/**
 * 性能数据上报（客户端 SDK 采集：启动时间/内存/CPU/ANR/帧率）
 * POST /api/v1/perf/report
 */
class Perf extends BaseController
{
    /**
     * 批量上报性能指标
     * body: app_code, device_id?, version_code?, version_name?, platform,
     *       metrics: [{type, name, value, extra?, occurred_at?}]
     */
    public function report(): Json
    {
        $appCode = trim((string) $this->request->post('app_code', ''));
        if ($appCode === '') {
            return fail(10002, 'app_code 不能为空');
        }
        $app = $this->ensureApp($appCode);
        $metrics = $this->request->post('metrics', '');
        if (is_string($metrics) && $metrics !== '') {
            $decoded = json_decode($metrics, true);
            $metrics = is_array($decoded) ? $decoded : [];
        } elseif (is_array($metrics)) {
            // 已是数组
        } else {
            $metrics = [];
        }
        if (!$metrics || count($metrics) > 200) {
            return fail(10003, 'metrics 不能为空且单次不超过 200 条');
        }

        $deviceIdStr = (string) $this->request->post('device_id', '');
        $device      = null;
        if ($deviceIdStr !== '') {
            $device = Db::name('devices')->where('device_id', $deviceIdStr)->find();
        }
        $appId      = (int) $app['id'];
        $versionCode= (int) $this->request->post('version_code', 0);
        $versionName= (string) $this->request->post('version_name', '');
        $platform   = strtolower((string) $this->request->post('platform', 'android'));
        $now        = datetime_now();
        // 替换设备 id：内部关联后直接使用设备主键
        $devicePk = $device ? (int) $device['id'] : null;

        $rows = [];
        $validTypes = ['startup', 'memory', 'cpu', 'anr', 'network', 'frame'];
        foreach ($metrics as $m) {
            if (!is_array($m)) {
                continue;
            }
            $type = (string) ($m['type'] ?? '');
            if (!in_array($type, $validTypes, true)) {
                continue;
            }
            $value = (float) ($m['value'] ?? 0);
            $rows[] = [
                'app_id'       => $appId,
                'device_id'    => $devicePk,
                'version_code' => $versionCode ?: null,
                'version_name' => $versionName,
                'platform'     => $platform,
                'metric_type'  => $type,
                'metric_name'  => (string) ($m['name'] ?? ''),
                'value'        => $value,
                'extra'        => isset($m['extra']) ? (is_string($m['extra']) ? $m['extra'] : json_encode($m['extra'], JSON_UNESCAPED_UNICODE)) : null,
                'occurred_at'  => !empty($m['occurred_at']) ? (string) $m['occurred_at'] : $now,
                'created_at'   => $now,
            ];
        }
        if (!$rows) {
            return fail(10003, '没有合法指标数据');
        }
        Db::name('perf_reports')->insertAll($rows);

        return success(['received' => count($rows)], '上报成功');
    }
}