<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\service\ExportService;
use think\facade\Db;
use think\response\Json;
use think\Response;

/**
 * 统计报表
 */
class Stats extends BaseController
{
    /**
     * 下载统计：按应用汇总 + 近N天趋势
     */
    public function downloads(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $days  = min(90, max(1, (int) $this->request->get('days', 7)));
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        $query = Db::name('download_logs')->where('created_at', '>=', $since);
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        $rows = $query->select()->toArray();

        $byApp = [];
        $byDay = [];
        foreach ($rows as $r) {
            $appKey = (int) $r['app_id'];
            $byApp[$appKey] = ($byApp[$appKey] ?? 0) + 1;
            $d = substr((string) $r['created_at'], 0, 10);
            $byDay[$d] = ($byDay[$d] ?? 0) + 1;
        }

        $appNames = Db::name('apps')->column('name', 'id');
        $appStat  = [];
        foreach ($byApp as $appKey => $cnt) {
            $appStat[] = ['app_id' => $appKey, 'app_name' => $appNames[$appKey] ?? '', 'downloads' => $cnt];
        }
        usort($appStat, fn ($a, $b) => $b['downloads'] - $a['downloads']);

        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' days'));
            $dates[] = ['date' => $d, 'downloads' => $byDay[$d] ?? 0];
        }

        return success([
            'days'     => $days,
            'total'    => count($rows),
            'by_app'   => $appStat,
            'by_day'   => $dates,
        ]);
    }

    /**
     * 升级检测统计
     */
    public function updates(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $days  = min(90, max(1, (int) $this->request->get('days', 7)));
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        $query = Db::name('update_logs')->where('created_at', '>=', $since);
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        $rows = $query->select()->toArray();

        $byDay = [];
        $byVer = [];
        foreach ($rows as $r) {
            $d = substr((string) $r['created_at'], 0, 10);
            $byDay[$d] = ($byDay[$d] ?? 0) + 1;
            $nv = (string) $r['new_version'];
            if ($nv !== '') {
                $byVer[$nv] = ($byVer[$nv] ?? 0) + 1;
            }
        }

        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' days'));
            $dates[] = ['date' => $d, 'updates' => $byDay[$d] ?? 0];
        }
        $verStat = [];
        foreach ($byVer as $ver => $cnt) {
            $verStat[] = ['version' => $ver, 'checks' => $cnt];
        }
        usort($verStat, fn ($a, $b) => $b['checks'] - $a['checks']);

        return success(['days' => $days, 'total' => count($rows), 'by_day' => $dates, 'by_version' => $verStat]);
    }

    /**
     * 版本占比（当前设备安装版本分布，全局维度）
     */
    public function versions(): Json
    {
        $query = Db::name('devices')
            ->field('app_version, COUNT(*) AS total')
            ->whereNotNull('app_version')
            ->where('app_version', '<>', '');
        $rows  = $query->group('app_version')->select()->toArray();
        $total = array_sum(array_column($rows, 'total')) ?: 1;

        $data = array_map(function ($r) use ($total) {
            return [
                'version' => (string) $r['app_version'],
                'count'   => (int) $r['total'],
                'percent' => round((int) $r['total'] / $total * 100, 1),
            ];
        }, $rows);
        usort($data, fn ($a, $b) => $b['count'] - $a['count']);

        return success(['total_devices' => (int) $total, 'list' => $data]);
    }

    /**
     * 平台占比（下载）
     */
    public function platforms(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $query = Db::name('download_logs')->field('platform, COUNT(*) AS total');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        $rows  = $query->group('platform')->select()->toArray();
        $total = array_sum(array_column($rows, 'total')) ?: 1;

        return success([
            'total' => (int) $total,
            'list'  => array_map(function ($r) use ($total) {
                return ['platform' => (string) $r['platform'], 'count' => (int) $r['total'], 'percent' => round((int) $r['total'] / $total * 100, 1)];
            }, $rows),
        ]);
    }

    /**
     * 渠道占比（下载）
     */
    public function channels(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $query = Db::name('download_logs')->field('channel_code, COUNT(*) AS total')->whereNotNull('channel_code');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        $rows  = $query->group('channel_code')->select()->toArray();
        $total = array_sum(array_column($rows, 'total')) ?: 1;

        $data = array_map(function ($r) use ($total) {
            return ['channel' => (string) $r['channel_code'], 'count' => (int) $r['total'], 'percent' => round((int) $r['total'] / $total * 100, 1)];
        }, $rows);
        usort($data, fn ($a, $b) => $b['count'] - $a['count']);

        return success(['total' => (int) $total, 'list' => $data]);
    }

    /**
     * 反馈统计（类型分布 + 状态分布）
     */
    public function feedbacks(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $q1 = Db::name('feedbacks')->field('type, COUNT(*) AS total')->group('type');
        $q2 = Db::name('feedbacks')->field('status, COUNT(*) AS total')->group('status');
        if ($appId > 0) {
            $q1->where('app_id', $appId);
            $q2->where('app_id', $appId);
        }
        return success([
            'by_type'  => $q1->select()->toArray(),
            'by_status'=> $q2->select()->toArray(),
        ]);
    }

    /**
     * 导出下载日志为 CSV
     * GET /api/v1/admin/stats/downloads/export?format=csv
     */
    public function exportDownloads(): Response
    {
        $filters = [
            'app_id'      => (int) $this->request->get('app_id', 0),
            'version_id'  => (int) $this->request->get('version_id', 0),
            'start_date'  => (string) $this->request->get('start_date', ''),
            'end_date'    => (string) $this->request->get('end_date', ''),
        ];

        $csv = ExportService::exportDownloadsCsv($filters);

        $filename = 'downloads_' . date('Ymd_His') . '.csv';
        return Response::create($csv)
            ->header([
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
    }

    /**
     * 留存分析（Phase 2-3）：基于设备注册/心跳，计算各注册日新增设备的 N 日留存率
     * GET /api/v1/admin/stats/retention?days=14&app_version=1.0.0
     */
    public function retention(): Json
    {
        $period  = min(60, max(7, (int) $this->request->get('days', 14)));
        $appVer  = (string) $this->request->get('app_version', '');

        $cohorts = [];
        // 按首次活跃日分组统计新增量
        $regRows = Db::name('devices')
            ->field('DATE(first_active_at) AS d, COUNT(DISTINCT id) AS c')
            ->whereNotNull('first_active_at')
            ->whereBetweenTime('first_active_at', date('Y-m-d', strtotime('-' . ($period + 30) . ' days')), date('Y-m-d 23:59:59'))
            ->group('d')
            ->select()
            ->toArray();
        $regMap = [];
        foreach ($regRows as $r) {
            $regMap[(string) $r['d']] = (int) $r['c'];
        }

        // 活跃矩阵：注册日->活跃日（DISTINCT 设备）
        $actRows = Db::name('devices')
            ->field('DATE(first_active_at) AS cohort, DATE(last_active_at) AS act, COUNT(DISTINCT id) AS c')
            ->whereNotNull('first_active_at')
            ->whereNotNull('last_active_at')
            ->whereBetweenTime('last_active_at', date('Y-m-d', strtotime('-' . ($period + 30) . ' days')), date('Y-m-d 23:59:59'))
            ->group('cohort', 'act')
            ->select()
            ->toArray();
        $actMap = [];
        foreach ($actRows as $r) {
            $key = (string) $r['cohort'] . '|' . (string) $r['act'];
            $actMap[$key] = (int) $r['c'];
        }

        for ($i = $period - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' days'));
            $new = $regMap[$d] ?? 0;
            if ($new <= 0) {
                continue;
            }
            $row = ['cohort' => $d, 'new_users' => $new];
            for ($n = 1; $n <= 7; $n++) {
                $actD = date('Y-m-d', strtotime($d . ' +' . $n . ' days'));
                $alive = $actMap[$d . '|' . $actD] ?? 0;
                $row['d' . $n] = $alive;
                $row['d' . $n . '_rate'] = round($alive / $new * 100, 1);
            }
            $cohorts[] = $row;
        }
        // 新→旧排序
        usort($cohorts, fn ($a, $b) => strcmp($b['cohort'], $a['cohort']));

        return success([
            'days'        => $period,
            'app_version' => $appVer,
            'cohorts'     => array_slice($cohorts, 0, $period),
            'summary'     => [
                'total_devices'  => (int) Db::name('devices')->count(),
                'active_30d'     => (int) Db::name('devices')->where('last_active_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))->count(),
                'avg_d1_retention' => $this->avgRate($cohorts, 'd1_rate'),
                'avg_d7_retention' => $this->avgRate($cohorts, 'd7_rate'),
            ],
        ]);
    }

    /**
     * 漏斗分析（Phase 2-4）：串联 下载 -> 设备注册 -> 更新检测/升级 -> 反馈/崩溃
     * GET /api/v1/admin/stats/funnel?app_id=1&days=30
     */
    public function funnel(): Json
    {
        $appId = (int) $this->request->get('app_id', 0);
        $days  = min(90, max(1, (int) $this->request->get('days', 30)));
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        // 阶段1：下载（次数与设备数）
        $q1 = Db::name('download_logs')->where('created_at', '>=', $since);
        if ($appId > 0) {
            $q1->where('app_id', $appId);
        }
        $downloadsTotal = (int) $q1->count();
        $downloadsDev   = (int) $q1->distinct(true)->count('device_id');

        // 阶段2：设备注册（下载漏斗视角：有下载记录的设备中已注册的比例）
        $regDev = (int) Db::name('devices')->where('created_at', '>=', $since)->count();

        // 阶段3：更新检测（发起升级检测的终端）
        $q3 = Db::name('update_logs')->where('created_at', '>=', $since)->where('result', 'check');
        if ($appId > 0) {
            $q3->where('app_id', $appId);
        }
        $checkTotal = (int) $q3->count();
        $checkDev   = (int) $q3->distinct(true)->count('device_id');

        // 阶段4：实际升级（升级动作）
        $q4 = Db::name('update_logs')->where('created_at', '>=', $since)->where('result', 'upgrade');
        if ($appId > 0) {
            $q4->where('app_id', $appId);
        }
        $upgradeTotal = (int) $q4->count();
        $upgradeDev   = (int) $q4->distinct(true)->count('device_id');

        // 阶段5：反馈/工单
        $q5 = Db::name('feedbacks')->where('created_at', '>=', $since);
        if ($appId > 0) {
            $q5->where('app_id', $appId);
        }
        $feedbackTotal = (int) $q5->count();

        $q6 = Db::name('crash_reports')->where('created_at', '>=', $since);
        if ($appId > 0) {
            $q6->where('app_id', $appId);
        }
        $crashTotal = (int) $q6->count();

        $stages = [];
        $stages[] = ['step' => 1, 'name' => '下载安装包', 'metric' => $downloadsDev > 0 ? $downloadsDev : $downloadsTotal, 'event' => 'download', 'prev_rate' => null];
        $stages[] = ['step' => 2, 'name' => '设备注册', 'metric' => $regDev, 'event' => 'register', 'prev_rate' => $this->percent($regDev, $stages[0]['metric'])];
        $stages[] = ['step' => 3, 'name' => '更新检测', 'metric' => $checkDev > 0 ? $checkDev : $checkTotal, 'event' => 'update_check', 'prev_rate' => $this->percent($checkDev > 0 ? $checkDev : $checkTotal, $regDev)];
        $stages[] = ['step' => 4, 'name' => '完成升级', 'metric' => $upgradeDev > 0 ? $upgradeDev : $upgradeTotal, 'event' => 'upgrade', 'prev_rate' => $this->percent($upgradeDev > 0 ? $upgradeDev : $upgradeTotal, $stages[2]['metric'])];
        $stages[] = ['step' => 5, 'name' => '反馈提交', 'metric' => $feedbackTotal, 'event' => 'feedback', 'prev_rate' => $this->percent($feedbackTotal, $stages[3]['metric'])];
        $stages[] = ['step' => 6, 'name' => '崩溃上报（风险）', 'metric' => $crashTotal, 'event' => 'crash', 'prev_rate' => $this->percent($crashTotal, $stages[3]['metric'])];

        // 整体转化：下载设备 -> 升级设备
        return success([
            'days'        => $days,
            'app_id'      => $appId,
            'overall'     => [
                'download_to_upgrade' => $this->percent($stages[3]['metric'], $stages[0]['metric']),
                'register_to_upgrade' => $this->percent($stages[3]['metric'], $stages[1]['metric']),
            ],
            'stages'      => $stages,
        ]);
    }

    protected function percent(int $part, int $base): ?float
    {
        return $base > 0 ? round($part / $base * 100, 1) : null;
    }

    protected function avgRate(array $cohorts, string $key): ?float
    {
        $vals = array_filter(array_column($cohorts, $key), fn ($v) => $v !== null);
        return $vals ? round(array_sum($vals) / count($vals), 1) : null;
    }
}