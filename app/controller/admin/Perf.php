<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use think\facade\Db;
use think\response\Json;

/**
 * 性能监控聚合展示
 */
class Perf extends BaseController
{
    /**
     * 聚合概览：各指标均值/最新 + 趋势
     * GET /api/v1/admin/perf?app_id=1&type=startup&days=7
     */
    public function index(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $type  = (string) $this->request->get('type', '');
        $days  = min(90, max(1, (int) $this->request->get('days', 7)));
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        $query = Db::name('perf_reports')->where('created_at', '>=', $since);
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($type !== '') {
            $query->where('metric_type', $type);
        }
        $rows = $query->field('metric_type, metric_name, value, created_at, version_name')->select()->toArray();

        // 按类型汇总
        $byType = [];
        $byDay  = [];
        foreach ($rows as $r) {
            $t = (string) $r['metric_type'];
            $byType[$t][] = (float) $r['value'];
            $d = substr((string) $r['created_at'], 0, 10);
            $byDay[$d][$t][] = (float) $r['value'];
        }
        $summary = [];
        foreach ($byType as $t => $vals) {
            $summary[] = [
                'type'      => $t,
                'count'     => count($vals),
                'avg'       => round(array_sum($vals) / count($vals), 2),
                'min'       => round(min($vals), 2),
                'max'       => round(max($vals), 2),
                'p95'       => self::percentile($vals, 95),
                'last_value'=> (float) end($vals),
            ];
        }
        usort($summary, fn ($a, $b) => $b['count'] - $a['count']);

        // 每日均值序列
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' days'));
            $point = ['date' => $d];
            foreach ($byType as $t => $_v) {
                $vals = $byDay[$d][$t] ?? [];
                $point[$t] = $vals ? round(array_sum($vals) / count($vals), 2) : null;
            }
            $dates[] = $point;
        }

        // 最新版本分布（ANR/启动平均按版本）
        $byVersion = [];
        foreach ($rows as $r) {
            if (($r['version_name'] ?? '') === '' || ($r['metric_type'] ?? '') === '') {
                continue;
            }
            $v = (string) $r['version_name'];
            $t = (string) $r['metric_type'];
            $byVersion[$v][$t][] = (float) $r['value'];
        }
        $versionStat = [];
        foreach ($byVersion as $v => $types) {
            $row = ['version' => $v];
            foreach ($types as $t => $vals) {
                $row[$t . '_avg'] = round(array_sum($vals) / count($vals), 2);
            }
            $versionStat[] = $row;
        }

        return success([
            'days'          => $days,
            'total_points'  => count($rows),
            'summary'       => $summary,
            'daily'         => $dates,
            'by_version'    => $versionStat,
        ]);
    }

    /**
     * 最近 ANR/异常记录
     * GET /api/v1/admin/perf/anrs?app_id=1&limit=20
     */
    public function anrs(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $limit = min(100, max(1, (int) $this->request->get('limit', 20)));
        $query = Db::name('perf_reports')
            ->whereIn('metric_type', ['anr', 'crash'])
            ->order('id', 'desc')
            ->limit($limit);
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        $list = $query->select()->toArray();
        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$r) {
            $r['app_name'] = $appNames[$r['app_id']] ?? '';
        }
        unset($r);
        return success(['list' => $list]);
    }

    protected static function percentile(array $vals, int $p): float
    {
        if (!$vals) {
            return 0;
        }
        sort($vals, SORT_NUMERIC);
        $idx = (int) ceil(count($vals) * $p / 100) - 1;
        $idx = max(0, min(count($vals) - 1, $idx));
        return (float) $vals[$idx];
    }
}