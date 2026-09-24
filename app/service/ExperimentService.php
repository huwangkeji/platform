<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\BizException;
use think\facade\Db;

/**
 * A/B 实验服务：实验管理 + 更新检测按实验组分流
 */
class ExperimentService
{
    public const STATUS = ['draft', 'running', 'paused', 'finished'];

    /**
     * 判断设备/用户命中哪个运行中的实验及组
     * @return array|null ['experiment_id'=>, 'group_tag'=>, 'version_id'=>, 'rollout_percent'=>]
     */
    public static function match(array $params): ?array
    {
        $appId    = (int) ($params['app_id'] ?? 0);
        $deviceId = (string) ($params['device_id'] ?? '');
        $userId   = (string) ($params['user_id'] ?? '');
        if ($appId <= 0) {
            return null;
        }
        $now   = datetime_now();
        $experiments = Db::name('experiments')
            ->where('app_id', $appId)
            ->where('status', 'running')
            ->where(function ($q) use ($now) {
                $q->whereNull('start_at')->whereOr('start_at', '<=', $now);
                $q->where(function ($q2) use ($now) {
                    $q2->whereNull('end_at')->whereOr('end_at', '>=', $now);
                });
            })
            ->order('id', 'desc')
            ->select()
            ->toArray();
        if (!$experiments) {
            return null;
        }

        foreach ($experiments as $exp) {
            $groups = Db::name('experiment_versions')
                ->where('experiment_id', (int) $exp['id'])
                ->select()
                ->toArray();
            if (!$groups) {
                continue;
            }
            // 稳定分桶：按 设备ID/用户ID + 实验ID 哈希到大写字幕区间 0-99
            $base = $deviceId !== '' ? $deviceId : ($userId !== '' ? $userId : 'anon');
            $bucket = (int) (sprintf('%u', crc32($base . '_exp' . $exp['id'])) % 100);

            // 组之间按 rollout 累计取桶（与灰度一致：先注册的组先占比例）
            $acc = 0;
            foreach ($groups as $g) {
                $acc += max(0, min(100, (int) $g['rollout_percent']));
                if ($bucket < $acc) {
                    $version = Db::name('app_versions')->where('id', (int) $g['version_id'])->find();
                    if (!$version || !in_array($version['status'], ['published', 'gray'], true)) {
                        break; // 版本不可用则不算命中
                    }
                    return [
                        'experiment_id'   => (int) $exp['id'],
                        'experiment_name' => (string) $exp['name'],
                        'group_tag'       => (string) $g['group_tag'],
                        'version_id'      => (int) $g['version_id'],
                        'rollout_percent' => (int) $g['rollout_percent'],
                    ];
                }
            }
        }
        return null;
    }

    /**
     * 创建实验
     */
    public static function create(array $data, int $adminId): int
    {
        $appId = (int) ($data['app_id'] ?? 0);
        $name  = trim((string) ($data['name'] ?? ''));
        if ($appId <= 0 || $name === '') {
            throw BizException::param('app_id/name 不能为空');
        }
        if (!Db::name('apps')->where('id', $appId)->find()) {
            throw BizException::notFound('应用不存在');
        }
        $groupTags = isset($data['group_tags']) && is_array($data['group_tags']) ? $data['group_tags'] : ['control', 'treatment'];
        $groupTags = array_slice(array_values(array_filter(array_map('trim', $groupTags), fn ($t) => $t !== '')), 0, 8);

        $now = datetime_now();
        $id  = Db::name('experiments')->insertGetId([
            'app_id'       => $appId,
            'name'         => $name,
            'description'  => (string) ($data['description'] ?? ''),
            'group_tags'   => json_encode($groupTags, JSON_UNESCAPED_UNICODE),
            'status'       => 'draft',
            'start_at'     => !empty($data['start_at']) ? $data['start_at'] : null,
            'end_at'       => !empty($data['end_at']) ? $data['end_at'] : null,
            'created_by'   => $adminId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        return (int) $id;
    }

    /**
     * 绑定组版本
     */
    public static function assignVersion(int $experimentId, string $groupTag, int $versionId, int $percent): void
    {
        $exp = Db::name('experiments')->where('id', $experimentId)->find();
        if (!$exp) {
            throw BizException::notFound('实验不存在');
        }
        $version = Db::name('app_versions')->where('id', $versionId)->find();
        if (!$version || (int) $version['app_id'] !== (int) $exp['app_id']) {
            throw BizException::param('版本不存在或不属于该应用');
        }
        $percent = max(0, min(100, $percent));
        $exists = Db::name('experiment_versions')
            ->where('experiment_id', $experimentId)
            ->where('group_tag', $groupTag)
            ->find();
        if ($exists) {
            Db::name('experiment_versions')->where('id', (int) $exists['id'])->update([
                'version_id'      => $versionId,
                'rollout_percent' => $percent,
            ]);
        } else {
            Db::name('experiment_versions')->insert([
                'experiment_id'   => $experimentId,
                'group_tag'       => $groupTag,
                'version_id'      => $versionId,
                'rollout_percent' => $percent,
                'created_at'      => datetime_now(),
            ]);
        }
    }
}