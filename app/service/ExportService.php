<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 数据导出服务：支持 CSV/Excel 格式导出
 */
class ExportService
{
    /**
     * 导出下载日志为 CSV
     * @param array $filters 过滤条件（app_id, version_id, start_date, end_date）
     */
    public static function exportDownloadsCsv(array $filters): string
    {
        $query = Db::name('download_logs');
        if (!empty($filters['app_id'])) {
            $query->where('app_id', (int) $filters['app_id']);
        }
        if (!empty($filters['version_id'])) {
            $query->where('version_id', (int) $filters['version_id']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date'] . ' 23:59:59');
        }

        $rows = $query->order('id', 'desc')->limit(50000)->select()->toArray();

        $headers = ['ID', '应用ID', '版本ID', '用户ID', '设备ID', '渠道', '平台', 'IP', 'User-Agent', '下载时间'];
        $csv = self::arrayToCsv([$headers]);

        foreach ($rows as $row) {
            $csv .= self::arrayToCsv([[
                $row['id'],
                $row['app_id'],
                $row['version_id'],
                $row['user_id'] ?? '-',
                $row['device_id'] ?? '-',
                $row['channel_code'] ?? '-',
                $row['platform'] ?? '-',
                $row['ip'] ?? '-',
                $row['user_agent'] ?? '-',
                $row['created_at'],
            ]]);
        }

        return $csv;
    }

    /**
     * 导出统计概览为 CSV
     */
    public static function exportStatsCsv(array $data): string
    {
        $headers = ['统计项', '数值'];
        $csv = self::arrayToCsv([$headers]);

        $rows = [
            ['应用总数', $data['total_apps'] ?? 0],
            ['版本总数', $data['total_versions'] ?? 0],
            ['累计下载', $data['total_downloads'] ?? 0],
            ['今日下载', $data['today_downloads'] ?? 0],
            ['本月下载', $data['month_downloads'] ?? 0],
            ['活跃设备', $data['active_devices'] ?? 0],
            ['待处理反馈', $data['pending_feedbacks'] ?? 0],
            ['运行中发布', $data['running_releases'] ?? 0],
        ];

        foreach ($rows as $row) {
            $csv .= self::arrayToCsv([$row]);
        }

        return $csv;
    }

    /**
     * 导出合规报告（操作日志审计）：包含关键字段 admin_id / action / module / detail / ip / created_at
     */
    public static function exportComplianceCsv(array $filters): string
    {
        $query = Db::name('operation_logs');
        if (!empty($filters['admin_id'])) {
            $query->where('admin_id', (int) $filters['admin_id']);
        }
        if (!empty($filters['module'])) {
            $query->where('module', (string) $filters['module']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date'] . ' 23:59:59');
        }

        $rows = $query->order('id', 'desc')->limit(50000)->select()->toArray();
        $names = Db::name('admin_users')->column('nickname', 'id');

        $headers = ['日志ID', '管理员ID', '管理员昵称', '动作', '模块', '详情', 'IP地址', '用户代理', '操作时间'];
        $csv = self::arrayToCsv([$headers]);

        foreach ($rows as $row) {
            $csv .= self::arrayToCsv([[
                $row['id'],
                $row['admin_id'],
                $names[$row['admin_id'] ?? 0] ?? '-',
                $row['action'],
                $row['module'],
                $row['detail'],
                $row['ip'] ?? '-',
                $row['user_agent'] ?? '-',
                $row['created_at'],
            ]]);
        }
        return $csv;
    }
    protected static function arrayToCsv(array $rows): string
    {
        $output = '';
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $cell) {
                $cell = (string) $cell;
                if (str_contains($cell, ',') || str_contains($cell, '"') || str_contains($cell, "\n")) {
                    $cell = '"' . str_replace('"', '""', $cell) . '"';
                }
                $line[] = $cell;
            }
            $output .= implode(',', $line) . "\n";
        }
        return $output;
    }
}
