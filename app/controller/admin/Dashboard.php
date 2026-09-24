<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use think\facade\Db;
use think\response\Json;

/**
 * 仪表盘：核心数据 + 趋势 + 图表
 */
class Dashboard extends BaseController
{
    /**
     * 核心统计
     */
    public function index(): Json
    {
        $today    = date('Y-m-d') . ' 00:00:00';
        $monthAgo = date('Y-m-d H:i:s', strtotime('-30 days'));

        $apps = (int) Db::name('apps')->count();
        $versions = (int) Db::name('app_versions')->count();
        $devices  = (int) Db::name('devices')->count();

        $grayVersions = (int) Db::name('app_versions')->where('status', 'gray')->count();
        $rollbackVersions = (int) Db::name('app_versions')->where('status', 'rollback')->count();

        $todayDownloads = (int) Db::name('download_logs')->where('created_at', '>=', $today)->count();
        $todayUpdates   = (int) Db::name('update_logs')->where('created_at', '>=', $today)->count();
        $todayFeedback  = (int) Db::name('feedbacks')->where('created_at', '>=', $today)->count();

        $pendingFeedbacks = (int) Db::name('feedbacks')->whereIn('status', ['pending', 'open', 'processing'])->count();
        $pendingTickets   = (int) Db::name('tickets')->whereIn('status', ['pending', 'processing', 'dev_pending', 'dev_processing', 'test_pending'])->count();
        $activeDevices    = (int) Db::name('devices')->where('last_active_at', '>=', $monthAgo)->count();
        $todayCrashes     = (int) Db::name('crash_reports')->where('created_at', '>=', $today)->count();

        return success([
            'apps'             => $apps,
            'versions'         => $versions,
            'devices'          => $devices,
            'gray_versions'    => $grayVersions,
            'rollback_versions'=> $rollbackVersions,
            'today_downloads'  => $todayDownloads,
            'today_updates'    => $todayUpdates,
            'today_feedbacks'  => $todayFeedback,
            'pending_feedbacks'=> $pendingFeedbacks,
            'pending_tickets'  => $pendingTickets,
            'active_devices'   => $activeDevices,
            'today_crashes'    => $todayCrashes,
        ]);
    }

    /**
     * 近30天 下载/升级 趋势
     */
    public function trend(): Json
    {
        $days = min(90, max(7, (int) $this->request->get('days', 30)));
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        $downloads = Db::name('download_logs')->where('created_at', '>=', $since)->select()->toArray();
        $updates   = Db::name('update_logs')->where('created_at', '>=', $since)->select()->toArray();

        $dc = [];
        $uc = [];
        foreach ($downloads as $r) {
            $d = substr((string) $r['created_at'], 0, 10);
            $dc[$d] = ($dc[$d] ?? 0) + 1;
        }
        foreach ($updates as $r) {
            $d = substr((string) $r['created_at'], 0, 10);
            $uc[$d] = ($uc[$d] ?? 0) + 1;
        }

        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime('-' . $i . ' days'));
        }

        $trend = array_map(function ($d) use ($dc, $uc) {
            return [
                'date'      => $d,
                'downloads' => $dc[$d] ?? 0,
                'updates'   => $uc[$d] ?? 0,
            ];
        }, $dates);

        return success(['days' => $days, 'trend' => $trend]);
    }

    /**
     * 实时数据看板（Phase 2-5）：短窗口增量指标，供前端定时轮询刷新
     * GET /api/v1/admin/dashboard/realtime?app_id=1&minutes=5
     */
    public function realtime(): Json
    {
        $appId   = (int) $this->request->get('app_id', 0);
        $minutes = min(120, max(1, (int) $this->request->get('minutes', 5)));
        $since   = date('Y-m-d H:i:s', strtotime('-' . $minutes . ' minutes'));

        $q = function (string $table) use ($appId, $since): int {
            $query = Db::name($table)->where('created_at', '>=', $since);
            if ($appId > 0) {
                $query->where('app_id', $appId);
            }
            return (int) $query->count();
        };
        $activeDev = (int) Db::name('devices')
            ->where('last_active_at', '>=', $since)
            ->when($appId > 0, function ($query) use ($appId) {
                // 设备表无 app_id，按关联应用降级：跳过 app 过滤
                return $query;
            })
            ->count();

        // 近 N 分钟每分钟折线
        $dls = Db::name('download_logs')->where('created_at', '>=', $since)->select()->toArray();
        $uls = Db::name('update_logs')->where('created_at', '>=', $since)->select()->toArray();
        $perMinute = [];
        for ($i = $minutes - 1; $i >= 0; $i--) {
            $t = date('Y-m-d H:i', strtotime('-' . $i . ' minutes'));
            $perMinute[$t] = ['time' => $t, 'downloads' => 0, 'updates' => 0];
        }
        foreach ($dls as $r) {
            $t = substr((string) $r['created_at'], 0, 16);
            if (isset($perMinute[$t])) {
                $perMinute[$t]['downloads']++;
            }
        }
        foreach ($uls as $r) {
            $t = substr((string) $r['created_at'], 0, 16);
            if (isset($perMinute[$t])) {
                $perMinute[$t]['updates']++;
            }
        }

        // 最新事件流（下载/升级/反馈/崩溃）
        $events = [];
        $feedbacks = Db::name('feedbacks')->where('created_at', '>=', $since)->order('id', 'desc')->limit(20)->select()->toArray();
        $crashes   = Db::name('crash_reports')->where('created_at', '>=', $since)->order('id', 'desc')->limit(20)->select()->toArray();
        foreach ($feedbacks as $f) {
            $events[] = ['time' => $f['created_at'], 'type' => 'feedback', 'title' => mb_substr((string) $f['title'], 0, 60), 'id' => (int) $f['id']];
        }
        foreach ($crashes as $c) {
            $events[] = ['time' => $c['created_at'], 'type' => 'crash', 'title' => mb_substr((string) $c['error_message'], 0, 60), 'id' => (int) $c['id']];
        }
        usort($events, fn ($a, $b) => strcmp($b['time'], $a['time']));

        return success([
            'window'   => 'last_' . $minutes . '_minutes',
            'metrics'  => [
                'downloads'   => $q('download_logs'),
                'updates'     => $q('update_logs'),
                'feedbacks'   => $q('feedbacks'),
                'crashes'     => $q('crash_reports'),
                'active_devices' => $activeDev,
            ],
            'series'   => array_values($perMinute),
            'events'   => array_slice($events, 0, 20),
        ]);
    }

    /**
     * 图表：平台/渠道/版本/反馈 分布
     */
    public function charts(): Json
    {
        // 平台分布（下载）
        $platforms = Db::name('download_logs')
            ->field('platform, COUNT(*) AS total')
            ->group('platform')
            ->select()->toArray();
        $platformTotal = array_sum(array_column($platforms, 'total')) ?: 1;

        // 渠道分布（下载）
        $channels = Db::name('download_logs')
            ->field('channel_code, COUNT(*) AS total')
            ->whereNotNull('channel_code')
            ->group('channel_code')
            ->select()->toArray();

        // 设备版本分布
        $versionDist = Db::name('devices')
            ->field('app_version, COUNT(*) AS total')
            ->whereNotNull('app_version')
            ->where('app_version', '<>', '')
            ->group('app_version')
            ->select()->toArray();
        $versionTotal = array_sum(array_column($versionDist, 'total')) ?: 1;

        // 反馈类型分布
        $feedbackTypes = Db::name('feedbacks')
            ->field('type, COUNT(*) AS total')
            ->group('type')
            ->select()->toArray();

        return success([
            'platforms' => array_map(function ($p) use ($platformTotal) {
                return ['name' => (string) $p['platform'], 'value' => (int) $p['total'], 'percent' => round((int) $p['total'] / $platformTotal * 100, 1)];
            }, $platforms),
            'channels' => $channels,
            'versions' => array_map(function ($v) use ($versionTotal) {
                return ['name' => (string) $v['app_version'], 'value' => (int) $v['total'], 'percent' => round((int) $v['total'] / $versionTotal * 100, 1)];
            }, $versionDist),
            'feedback_types' => $feedbackTypes,
        ]);
    }
}