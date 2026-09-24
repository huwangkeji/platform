<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Config;
use think\facade\Db;

/**
 * 版本保留策略服务：按策略自动归档/清理旧版本，防存储膨胀
 */
class RetentionService
{
    /**
     * 获取策略（应用级 > 全局默认）
     */
    public static function policy(int $appId): array
    {
        $p = $appId > 0
            ? Db::name('retention_policies')->where('app_id', $appId)->find()
            : null;
        if (!$p) {
            $p = Db::name('retention_policies')->where('app_id', 0)->find();
        }
        return $p ?: [
            'keep_count'     => 10,
            'max_age_days'   => 180,
            'auto_archive'   => 1,
            'physical_delete'=> 0,
        ];
    }

    /**
     * 执行归档清理（定时任务入口，按应用扫描）
     * @return array 清理统计
     */
    public static function run(?int $appId = null): array
    {
        $apps = $appId
            ? Db::name('apps')->where('id', $appId)->select()->toArray()
            : Db::name('apps')->select()->toArray();

        $stats = ['scanned_apps' => 0, 'archived' => 0, 'deleted' => 0, 'skipped' => 0];
        foreach ($apps as $app) {
            $stats['scanned_apps']++;
            $r = self::cleanApp((int) $app['id']);
            $stats['archived'] += $r['archived'];
            $stats['deleted']  += $r['deleted'];
            $stats['skipped']  += $r['skipped'];
        }
        return $stats;
    }

    /**
     * 清理单个应用：保留最新 keep_count 个已发布/灰度版本，其余按年龄归档
     * @return array{archived:int,deleted:int,skipped:int}
     */
    public static function cleanApp(int $appId): array
    {
        $policy = self::policy($appId);
        if ((int) $policy['auto_archive'] !== 1) {
            return ['archived' => 0, 'deleted' => 0, 'skipped' => 0];
        }
        $keep    = max(1, (int) $policy['keep_count']);
        $maxDays = max(7, (int) $policy['max_age_days']);
        $cutoff  = date('Y-m-d H:i:s', strtotime('-' . $maxDays . ' days'));
        $now     = datetime_now();

        // 各平台分开统计保留名额
        $platforms = Db::name('app_versions')->where('app_id', $appId)->distinct(true)->column('platform');
        $root      = rtrim((string) Config::get('app.storage_path'), '/');

        $archived = 0;
        $deleted  = 0;
        $skipped  = 0;
        foreach ($platforms as $platform) {
            $versions = Db::name('app_versions')
                ->where('app_id', $appId)
                ->where('platform', $platform)
                ->whereIn('status', ['published', 'gray', 'paused', 'rollback'])
                ->order('version_code', 'desc')
                ->select()
                ->toArray();
            if (count($versions) <= $keep) {
                continue;
            }
            foreach (array_slice($versions, $keep) as $v) {
                // 只归档超过保留天数的
                $created = (string) ($v['created_at'] ?? '');
                if ($created !== '' && strtotime($created) > strtotime($cutoff)) {
                    $skipped++;
                    continue;
                }
                if ((int) $policy['physical_delete'] === 1) {
                    self::removePackage($root, (string) $v['file_path']);
                    Db::name('app_versions')->where('id', (int) $v['id'])->update(['status' => 'offline', 'file_path' => '', 'updated_at' => $now]);
                    $deleted++;
                } else {
                    Db::name('app_versions')->where('id', (int) $v['id'])->update(['status' => 'offline', 'updated_at' => $now]);
                    $archived++;
                }
            }
        }
        return ['archived' => $archived, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    protected static function removePackage(string $root, string $path): void
    {
        if ($path === '' || strpos($path, 'apps/') !== 0) {
            return;
        }
        $abs = $root . '/' . $path;
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}