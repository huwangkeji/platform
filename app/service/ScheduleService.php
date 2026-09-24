<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 定时任务服务：扫描并执行待发布的定时发布任务
 */
class ScheduleService
{
    /**
     * 扫描并执行到期的定时发布任务
     * 建议每分钟执行一次（通过 Cron/定时任务调度器调用）
     */
    public static function scanScheduledReleases(): int
    {
        $now = datetime_now();
        $tasks = Db::name('release_tasks')
            ->where('is_scheduled', 1)
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', $now)
            ->select()
            ->toArray();

        $executed = 0;
        foreach ($tasks as $task) {
            try {
                Db::transaction(function () use ($task, $now) {
                    // 更新任务状态为运行中
                    Db::name('release_tasks')->where('id', (int) $task['id'])->update([
                        'status'       => 'running',
                        'start_at'     => $now,
                        'is_scheduled' => 0,
                        'updated_at'   => $now,
                    ]);

                    // 联动版本状态
                    $version = Db::name('app_versions')->where('id', (int) $task['version_id'])->find();
                    if ($version) {
                        $type = (string) ($task['release_type'] ?? 'full');
                        if ($type === 'full') {
                            ReleaseService::markOthersPaused((int) $version['app_id'], (string) $version['platform'], (int) $version['id']);
                            Db::name('app_versions')->where('id', (int) $version['id'])->update([
                                'status'       => 'published',
                                'published_at' => $now,
                                'updated_at'   => $now,
                            ]);
                        } else {
                            Db::name('app_versions')->where('id', (int) $version['id'])->update([
                                'status'       => 'gray',
                                'published_at' => $now,
                                'updated_at'   => $now,
                            ]);
                        }
                    }
                });

                // 触发通知
                $version = Db::name('app_versions')->where('id', (int) $task['version_id'])->find();
                $app     = $version ? Db::name('apps')->where('id', (int) $version['app_id'])->find() : null;
                if ($app && $version) {
                    NotificationService::trigger('publish', [
                        'app_name'     => $app['name'] ?? '',
                        'app_code'     => $app['code'] ?? '',
                        'version_name' => $version['version_name'] ?? '',
                        'version_code' => $version['version_code'] ?? '',
                        'release_type' => $task['release_type'] ?? 'full',
                        'platform'     => $version['platform'] ?? '',
                    ]);
                }

                $executed++;
            } catch (\Throwable $e) {
                // 单任务失败不影响其他任务
                Db::name('release_tasks')->where('id', (int) $task['id'])->update([
                    'status'     => 'failed',
                    'updated_at' => $now,
                ]);
            }
        }

        return $executed;
    }
}
