<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\BizException;
use think\facade\Db;

/**
 * 发布管理：创建发布任务、版本状态联动（发布/暂停/回滚）
 */
class ReleaseService
{
    public const TYPES = ['full', 'gray', 'target_channel', 'target_user', 'target_device', 'target_group'];
    public const GRAY_PERCENTS = [1, 5, 10, 20, 30, 50, 100];

    /**
     * 创建发布任务并联动版本状态
     * @param array $data 含 app_id, version_id, name, release_type, rollout_percent, start_at, end_at, rules
     * @return int release task id
     */
    public static function create(array $data, int $adminId): int
    {
        $versionId = (int) ($data['version_id'] ?? 0);
        $appId     = (int) ($data['app_id'] ?? 0);
        $type      = (string) ($data['release_type'] ?? 'full');
        if (!in_array($type, self::TYPES, true)) {
            throw BizException::param('发布类型不合法');
        }

        $version = Db::name('app_versions')->where('id', $versionId)->find();
        if (!$version) {
            throw BizException::notFound('版本不存在');
        }
        if ((int) $version['app_id'] !== $appId) {
            throw BizException::param('应用与版本不匹配');
        }

        $percent = (int) ($data['rollout_percent'] ?? 100);
        if ($type === 'gray' && !in_array($percent, self::GRAY_PERCENTS, true)) {
            throw BizException::param('灰度比例仅支持 1/5/10/20/30/50/100');
        }
        if ($type !== 'gray') {
            $percent = $type === 'full' ? 100 : 0;
        }

        $rules = isset($data['rules']) && is_array($data['rules']) ? $data['rules'] : [];
        if (in_array($type, ['target_channel', 'target_user', 'target_device', 'target_group'], true) && !$rules) {
            throw BizException::param('定向发布必须提供规则');
        }

        $now = datetime_now();
        $scheduledAt = !empty($data['scheduled_at']) ? $data['scheduled_at'] : null;
        $isScheduled = $scheduledAt !== null && $scheduledAt > $now;

        $rid = Db::transaction(function () use ($data, $type, $percent, $rules, $versionId, $appId, $adminId, $now, $version, $scheduledAt, $isScheduled) {
            $rid = Db::name('release_tasks')->insertGetId([
                'app_id'           => $appId,
                'version_id'       => $versionId,
                'name'             => mb_substr((string) ($data['name'] ?? ''), 0, 150) ?: ('发布 ' . ((string) ($data['version_name'] ?? ''))),
                'release_type'     => $type,
                'rollout_percent'  => $percent,
                'status'           => $isScheduled ? 'pending' : 'running',
                'start_at'         => !empty($data['start_at']) && !$isScheduled ? $data['start_at'] : ($isScheduled ? null : $now),
                'end_at'           => !empty($data['end_at']) ? $data['end_at'] : null,
                'scheduled_at'     => $scheduledAt,
                'is_scheduled'     => $isScheduled ? 1 : 0,
                'created_by'       => $adminId,
                'approved_by'      => $adminId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            foreach ($rules as $rule) {
                $rt = (string) ($rule['rule_type'] ?? '');
                $rk = (string) ($rule['rule_key'] ?? '');
                $rv = (string) ($rule['rule_value'] ?? '');
                if ($rt === '' || $rv === '') {
                    continue;
                }
                // 设备定向规则归一化：决策上下文仅携带 device_id，
                // 强制 rule_key=device_id，避免手输其他标识（sn/imei）导致规则永不命中。
                if ($rt === 'device') {
                    $rk = 'device_id';
                }
                Db::name('release_rules')->insert([
                    'release_id' => $rid,
                    'rule_type'  => $rt,
                    'rule_key'   => $rk,
                    'rule_value' => $rv,
                    'created_at' => $now,
                ]);
            }

            // 版本状态联动
            $platform = (string) $version['platform'];
            if ($type === 'full') {
                self::markOthersPaused($appId, $platform, $versionId);
                Db::name('app_versions')->where('id', $versionId)->update([
                    'status'       => 'published',
                    'published_at' => $now,
                    'updated_at'   => $now,
                ]);
            } else {
                Db::name('app_versions')->where('id', $versionId)->update([
                    'status'       => 'gray',
                    'published_at' => $now,
                    'updated_at'   => $now,
                ]);
            }
            return $rid;
        });

        // 触发发布通知
        $app = Db::name('apps')->where('id', $appId)->find();
        NotificationService::trigger('release', [
            'app_name'      => $app['name'] ?? '',
            'app_code'      => $app['code'] ?? '',
            'version_name'  => $version['version_name'] ?? '',
            'version_code'  => $version['version_code'] ?? '',
            'release_type'  => $type,
            'percent'       => $percent,
            'platform'      => $version['platform'] ?? '',
        ]);

        OperationLogService::write($adminId, 'release', 'create', (int) $rid, '创建发布任务：' . ($data['name'] ?? '') . '（' . $type . ' ' . $percent . '%）');
        return (int) $rid;
    }

    /**
     * 暂停发布：版本 paused，运行中任务 stopped
     */
    public static function pause(int $versionId, int $adminId): void
    {
        Db::transaction(function () use ($versionId, $adminId) {
            $now = datetime_now();
            Db::name('release_tasks')->where('version_id', $versionId)->where('status', 'running')->update([
                'status'     => 'stopped',
                'updated_at' => $now,
            ]);
            Db::name('app_versions')->where('id', $versionId)->update(['status' => 'paused', 'updated_at' => $now]);
        });
        OperationLogService::write($adminId, 'version', 'pause', $versionId, '暂停版本发布');
    }

    /**
     * 停止发布任务（保留版本状态）
     */
    public static function stop(int $releaseId, int $adminId): void
    {
        Db::name('release_tasks')->where('id', $releaseId)->whereIn('status', ['running', 'pending'])->update([
            'status'     => 'stopped',
            'updated_at' => datetime_now(),
        ]);
        $task = Db::name('release_tasks')->where('id', $releaseId)->find();
        if ($task) {
            $otherRunning = Db::name('release_tasks')->where('version_id', $task['version_id'])->where('status', 'running')->count();
            if ($otherRunning === 0) {
                Db::name('app_versions')->where('id', $task['version_id'])->update([
                    'status'     => 'paused',
                    'updated_at' => datetime_now(),
                ]);
            }
        }
        OperationLogService::write($adminId, 'release', 'stop', $releaseId, '停止发布任务');
    }

    /**
     * 版本回滚：停止问题版本推送，将上一稳定版本恢复为发布目标
     */
    public static function rollback(int $versionId, int $adminId): array
    {
        $version = Db::name('app_versions')->where('id', $versionId)->find();
        if (!$version) {
            throw BizException::notFound('版本不存在');
        }
        $rollbackTo = null;
        Db::transaction(function () use ($version, $versionId, $adminId, &$rollbackTo) {
            $now = datetime_now();
            // 1. 停止当前版本
            Db::name('release_tasks')->where('version_id', $versionId)->where('status', 'running')->update(['status' => 'stopped', 'updated_at' => $now]);
            Db::name('app_versions')->where('id', $versionId)->update(['status' => 'rollback', 'updated_at' => $now]);

            // 2. 找到同应用同平台的上一稳定版本
            $prev = Db::name('app_versions')
                ->where('app_id', $version['app_id'])
                ->where('platform', $version['platform'])
                ->where('version_code', '<', (int) $version['version_code'])
                ->whereIn('status', ['published', 'gray', 'paused'])
                ->order('version_code', 'desc')
                ->find();
            if ($prev) {
                Db::name('app_versions')->where('id', $prev['id'])->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
                // 重新建立全量发布任务，保证更新检测可命中
                Db::name('release_tasks')->insert([
                    'app_id'          => $prev['app_id'],
                    'version_id'      => $prev['id'],
                    'name'            => '回滚发布 ' . $prev['version_name'],
                    'release_type'    => 'full',
                    'rollout_percent' => 100,
                    'status'          => 'running',
                    'start_at'        => $now,
                    'end_at'          => null,
                    'created_by'      => $adminId,
                    'approved_by'     => $adminId,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
                $rollbackTo = $prev;
            }
        });

        OperationLogService::write($adminId, 'version', 'rollback', $versionId, '回滚版本 ' . $version['version_name'] . ($rollbackTo ? ' → ' . $rollbackTo['version_name'] : ''));
        return ['rollback_to' => $rollbackTo ? ['id' => (int) $rollbackTo['id'], 'version_name' => $rollbackTo['version_name']] : null];
    }

    /**
     * 全量发布时，同应用同平台的其他已发布/灰度版本标记为 paused
     */
    public static function markOthersPaused(int $appId, string $platform, int $exceptVersionId): void
    {
        Db::name('app_versions')
            ->where('app_id', $appId)
            ->where('platform', $platform)
            ->where('id', '<>', $exceptVersionId)
            ->whereIn('status', ['published', 'gray'])
            ->update(['status' => 'paused', 'updated_at' => datetime_now()]);
    }
}