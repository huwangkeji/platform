<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\ReleaseService;
use think\facade\Db;
use think\response\Json;

/**
 * 发布任务管理
 */
class Releases extends BaseController
{
    /**
     * 发布任务列表
     */
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $status   = (string) $this->request->get('status', '');
        $type     = (string) $this->request->get('release_type', '');

        $query = Db::name('release_tasks');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($type !== '') {
            $query->where('release_type', $type);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames   = Db::name('apps')->column('name', 'id');
        $verNames   = Db::name('app_versions')->column('version_name', 'id');
        $adminNames = Db::name('admin_users')->column('nickname', 'id');
        foreach ($list as &$t) {
            $t['app_name']     = $appNames[$t['app_id']] ?? '';
            $t['version_name'] = $verNames[$t['version_id']] ?? '';
            $t['creator_name'] = $adminNames[$t['created_by'] ?? 0] ?? '';
            $t['rules']        = Db::name('release_rules')->where('release_id', (int) $t['id'])->select()->toArray();
        }
        unset($t);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 创建发布任务
     * POST /api/v1/admin/releases
     */
    public function create(): Json
    {
        $rulesRaw = $this->request->post('rules', '');
        $rules    = [];
        if (is_string($rulesRaw) && $rulesRaw !== '') {
            $decoded = json_decode($rulesRaw, true);
            if (is_array($decoded)) {
                $rules = $decoded;
            }
        } elseif (is_array($rulesRaw)) {
            $rules = $rulesRaw;
        }

        $versionId = (int) $this->request->post('version_id', 0);
        $version   = Db::name('app_versions')->where('id', $versionId)->find();
        if (!$version) {
            return fail(10004, '版本不存在');
        }

        $rid = ReleaseService::create([
            'app_id'          => (int) $version['app_id'],
            'version_id'      => $versionId,
            'name'            => (string) $this->request->post('name', '发布 ' . $version['version_name']),
            'version_name'    => (string) $version['version_name'],
            'release_type'    => (string) $this->request->post('release_type', 'full'),
            'rollout_percent' => (int) $this->request->post('rollout_percent', 100),
            'start_at'        => (string) $this->request->post('start_at', ''),
            'end_at'          => (string) $this->request->post('end_at', ''),
            'scheduled_at'    => (string) $this->request->post('scheduled_at', ''),
            'rules'           => $rules,
        ], $this->adminId());

        return success(['release_id' => $rid], '发布任务创建成功');
    }

    /**
     * 更新发布任务（比例/规则/时间窗）
     * PUT /api/v1/admin/releases/:id
     */
    public function update(): Json
    {
        $task = Db::name('release_tasks')->where('id', (int) $this->request->param('id'))->find();
        if (!$task) {
            throw BizException::notFound('发布任务不存在');
        }

        $data = ['updated_at' => datetime_now()];
        $name = trim((string) $this->request->put('name', ''));
        if ($name !== '') {
            $data['name'] = $name;
        }
        // 灰度比例仅允许白名单值（与 ReleaseService::create 保持一致），
        // 非灰度任务不接受自定义比例，防止绕过前端白名单写入非法灰度值。
        $percent = (int) $this->request->put('rollout_percent', $task['rollout_percent']);
        if ((string) ($task['release_type'] ?? '') === 'gray') {
            if ($percent > 0 && !in_array($percent, ReleaseService::GRAY_PERCENTS, true)) {
                return fail(10003, '灰度比例仅支持 1/5/10/20/30/50/100');
            }
            if ($percent > 0) {
                $data['rollout_percent'] = $percent;
            }
        } else {
            $data['rollout_percent'] = $task['release_type'] === 'full' ? 100 : 0;
        }
        $endAt = (string) $this->request->put('end_at', '');
        if ($endAt !== '') {
            $data['end_at'] = $endAt;
        }

        Db::transaction(function () use ($task, $data) {
            Db::name('release_tasks')->where('id', (int) $task['id'])->update($data);
            $rulesRaw = $this->request->put('rules', '');
            $rules    = [];
            if (is_string($rulesRaw) && $rulesRaw !== '') {
                $decoded = json_decode($rulesRaw, true);
                if (is_array($decoded)) {
                    $rules = $decoded;
                }
            } elseif (is_array($rulesRaw)) {
                $rules = $rulesRaw;
            }
            if ($rules) {
                $now = datetime_now();
                Db::name('release_rules')->where('release_id', (int) $task['id'])->delete();
                foreach ($rules as $rule) {
                    $rt = (string) ($rule['rule_type'] ?? '');
                    $rk = (string) ($rule['rule_key'] ?? '');
                    $rv = (string) ($rule['rule_value'] ?? '');
                    if ($rt === '' || $rv === '') {
                        continue;
                    }
                    // 设备定向规则归一化：决策上下文仅携带 device_id
                    if ($rt === 'device') {
                        $rk = 'device_id';
                    }
                    Db::name('release_rules')->insert([
                        'release_id' => (int) $task['id'],
                        'rule_type'  => $rt,
                        'rule_key'   => $rk,
                        'rule_value' => $rv,
                        'created_at' => $now,
                    ]);
                }
            }
        });

        $this->opLog('release', 'update', (int) $task['id'], '更新发布任务：' . $task['name']);
        return success(null, '更新成功');
    }

    /**
     * 停止发布任务
     */
    public function stop(): Json
    {
        $task = Db::name('release_tasks')->where('id', (int) $this->request->param('id'))->find();
        if (!$task) {
            throw BizException::notFound('发布任务不存在');
        }
        ReleaseService::stop((int) $task['id'], $this->adminId());
        return success(null, '发布任务已停止');
    }
}