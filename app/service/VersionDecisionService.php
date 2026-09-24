<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Config;
use think\facade\Db;
use think\facade\Request;

/**
 * 更新检测决策引擎（APP 检查更新核心）
 *
 * 决策链路：应用 → 当前版本 → 发布状态 → 平台/架构 → 渠道 → 最低版本 → 灰度规则 → 用户/设备
 */
class VersionDecisionService
{
    /**
     * 决策：返回命中当前设备的目标版本；无更新返回 null
     * @param array $app    应用行
     * @param array $params version_code/platform/os_version/architecture/channel/device_id/user_id
     */
    public static function decide(array $app, array $params): ?array
    {
        $clientCode = (int) ($params['version_code'] ?? 0);
        $platform   = strtolower((string) ($params['platform'] ?? 'android'));
        $arch       = (string) ($params['architecture'] ?? '');
        $channel    = (string) ($params['channel'] ?? 'official');
        $deviceId   = (string) ($params['device_id'] ?? '');
        $userId     = (string) ($params['user_id'] ?? '');

        $versions = Db::name('app_versions')
            ->where('app_id', (int) $app['id'])
            ->where('platform', $platform)
            ->where('version_code', '>', $clientCode)
            ->whereIn('status', ['published', 'gray'])
            ->order('version_code', 'desc')
            ->select()
            ->toArray();

        // 架构过滤：版本未指定架构视为通用
        if ($arch !== '') {
            $versions = array_values(array_filter($versions, function ($v) use ($arch) {
                $va = (string) ($v['architecture'] ?? '');
                return $va === '' || $va === 'universal' || $va === $arch;
            }));
        }

        // 从高到低取第一个对该设备放行的版本
        foreach ($versions as $v) {
            if (self::versionAllowed($v, $deviceId, $userId, $channel)) {
                return $v;
            }
        }

        return null;
    }

    /**
     * 版本对该设备是否放行（检查发布任务与规则）
     */
    protected static function versionAllowed(array $version, string $deviceId, string $userId, string $channel): bool
    {
        $tasks = Db::name('release_tasks')
            ->where('version_id', (int) $version['id'])
            ->whereIn('status', ['running', 'finished'])
            ->select()
            ->toArray();

        if (!$tasks) {
            // 无活跃发布任务：仅当全量发布过且未暂停时视为放行（容错）
            return (string) ($version['status'] ?? '') === 'published';
        }

        foreach ($tasks as $task) {
            if (self::taskAllows($task, $deviceId, $userId, $channel)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 发布任务是否放行该设备
     */
    protected static function taskAllows(array $task, string $deviceId, string $userId, string $channel): bool
    {
        $type = (string) ($task['release_type'] ?? 'full');

        if ($type === 'full') {
            return true;
        }

        if ($type === 'gray') {
            // 稳定哈希：同一设备多次检查结果一致
            $base   = $deviceId !== '' ? $deviceId : ($userId !== '' ? $userId : 'global');
            $hash   = (int) (sprintf('%u', crc32($base . '_v' . $task['version_id'])) % 100);
            $percent = max(0, min(100, (int) ($task['rollout_percent'] ?? 0)));
            return $hash < $percent;
        }

        $rules = Db::name('release_rules')->where('release_id', (int) $task['id'])->select()->toArray();
        if (!$rules) {
            return false;
        }

        foreach ($rules as $rule) {
            $rt   = (string) ($rule['rule_type'] ?? '');
            $rk   = strtolower((string) ($rule['rule_key'] ?? ''));
            $rv   = trim((string) ($rule['rule_value'] ?? ''));
            if ($rt === 'channel' && strtolower($rv) === strtolower($channel)) {
                return true;
            }
            if ($rt === 'user' && $rv !== '' && $rv === $userId) {
                return true;
            }
            // 设备规则：当前决策参数仅携带 device_id，因此仅支持 device_id 精确匹配；
            // 规则写入时已归一化 rule_key=device_id（见 ReleaseService/Releases::update），
            // 此处直接按值匹配，避免 rule_key 手输为 sn/imei 时规则永不命中。
            if ($rt === 'device' && $rv !== '' && $rv === $deviceId) {
                return true;
            }
            // 测试群组规则（Phase 2-1）：rule_type=group，rule_value=群组id，命中即放行
            if ($rt === 'group' && $rv !== '' && self::deviceInGroup((int) $rv, $deviceId, $userId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 设备/用户是否属于指定测试群组
     */
    protected static function deviceInGroup(int $groupId, string $deviceId, string $userId): bool
    {
        if ($groupId <= 0) {
            return false;
        }
        $query = Db::name('tester_group_members')->where('group_id', $groupId)->where('status', 1);
        if ($deviceId !== '') {
            $device = Db::name('devices')->where('device_id', $deviceId)->find();
            if ($device) {
                $query->where(function ($q) use ($device, $userId) {
                    $q->where('device_id', (int) $device['id']);
                    $q->whereOr('user_id', (int) ($device['user_id'] ?? 0));
                    if ($userId !== '' && is_numeric($userId)) {
                        $q->whereOr('user_id', (int) $userId);
                    }
                });
            } elseif ($userId !== '' && is_numeric($userId)) {
                $query->where('user_id', (int) $userId);
            } else {
                return false;
            }
        } elseif ($userId !== '' && is_numeric($userId)) {
            $query->where('user_id', (int) $userId);
        } else {
            return false;
        }
        return (bool) $query->find();
    }

    /**
     * 组装更新检测响应
     */
    public static function response(?array $version, int $clientCode): array
    {
        if (!$version) {
            return ['has_update' => false];
        }

        $force = (int) ($version['is_force_update'] ?? 0) === 1
            || ((int) ($version['minimum_version_code'] ?? 0) > 0 && $clientCode < (int) $version['minimum_version_code']);

        $resp = [
            'has_update'    => true,
            'version_id'    => (int) $version['id'],
            'version_name'  => (string) $version['version_name'],
            'version_code'  => (int) $version['version_code'],
            'platform'      => (string) $version['platform'],
            'architecture'  => (string) ($version['architecture'] ?? ''),
            'force_update'  => $force,
            'title'         => (string) ($version['release_title'] ?: '新版本发布'),
            'release_note'  => (string) ($version['release_note'] ?? ''),
            'file_size'     => (int) ($version['file_size'] ?? 0),
            'file_name'     => (string) ($version['file_name'] ?? ''),
            'download_url'  => self::downloadUrl($version),
            'md5'           => (string) ($version['md5'] ?? ''),
            'sha1'          => (string) ($version['sha1'] ?? ''),
            'sha256'        => (string) ($version['sha256'] ?? ''),
            'published_at'  => (string) ($version['published_at'] ?? ''),
        ];

        // 签名验证信息（Phase 2-6）：通知客户端可校验包签名
        if (!empty($version['signer_fingerprint'])) {
            $resp['signer_fingerprint'] = (string) $version['signer_fingerprint'];
            $resp['signature_verified'] = (int) ($version['signature_verified'] ?? 0);
        }

        // 增量差分包（Phase 3-7）：按当前 base 版本查找增量包
        try {
            $delta = Db::name('delta_packages')
                ->where('version_id', (int) $version['id'])
                ->where('base_version_id', $clientCode)
                ->where('status', 1)
                ->find();
            if ($delta) {
                $resp['delta'] = [
                    'file_name' => (string) $delta['file_name'],
                    'file_size' => (int) ($delta['file_size'] ?? 0),
                    'md5'       => (string) ($delta['md5'] ?? ''),
                    'sha256'    => (string) ($delta['sha256'] ?? ''),
                    'patch_type'=> (string) ($delta['patch_type'] ?? 'bsdiff'),
                    'download_url' => self::deltaDownloadUrl((int) $delta['id']),
                ];
            }
        } catch (\Throwable $e) {
            // 增量包查询失败不影响主响应
        }

        return $resp;
    }

    /**
     * 下载 URL（默认主站，客户端可在下载阶段选择 CDN）
     */
    public static function downloadUrl(array $version): string
    {
        $domain = rtrim((string) Request::domain(), '/');
        return $domain . '/download/' . (int) $version['id'];
    }

    /**
     * 增量包下载 URL
     */
    public static function deltaDownloadUrl(int $deltaId): string
    {
        $domain = rtrim((string) Request::domain(), '/');
        return $domain . '/download/delta/' . $deltaId;
    }

    /**
     * 记录一次更新检测日志（result=check）
     */
    public static function logCheck(array $app, array $params, ?array $version): void
    {
        try {
            $deviceIdStr = (string) ($params['device_id'] ?? '');
            $deviceRow   = null;
            if ($deviceIdStr !== '') {
                $deviceRow = Db::name('devices')->where('device_id', $deviceIdStr)->find();
            }
            Db::name('update_logs')->insert([
                'app_id'       => (int) $app['id'],
                'old_version'  => (string) ($params['version_name'] ?? ($params['version_code'] ?? '')),
                'new_version'  => $version ? $version['version_name'] : null,
                'user_id'      => $deviceRow ? (int) $deviceRow['user_id'] : (isset($params['user_id']) && $params['user_id'] !== '' ? 0 : null),
                'device_id'    => $deviceRow ? (int) $deviceRow['id'] : null,
                'channel_code' => (string) ($params['channel'] ?? ''),
                'result'       => 'check',
                'created_at'   => datetime_now(),
            ]);
        } catch (\Throwable $e) {
            // 日志不影响主流程
        }
    }
}