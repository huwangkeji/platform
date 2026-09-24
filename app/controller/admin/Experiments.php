<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\ExperimentService;
use think\facade\Db;
use think\response\Json;

/**
 * A/B 实验管理
 */
class Experiments extends BaseController
{
    /**
     * 实验列表
     */
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $status   = (string) $this->request->get('status', '');

        $query = Db::name('experiments');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($status !== '' && in_array($status, ExperimentService::STATUS, true)) {
            $query->where('status', $status);
        }
        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$e) {
            $e['app_name']   = $appNames[$e['app_id']] ?? '';
            $e['group_tags'] = json_decode((string) $e['group_tags'], true) ?: [];
            $e['versions']   = Db::name('experiment_versions')->where('experiment_id', (int) $e['id'])->select()->toArray();
        }
        unset($e);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 创建实验
     * POST /api/v1/admin/experiments
     */
    public function create(): Json
    {
        $groupTags = $this->request->post('group_tags', '');
        if (is_string($groupTags) && $groupTags !== '') {
            $decoded = json_decode($groupTags, true);
            $groupTags = is_array($decoded) ? $decoded : explode(',', $groupTags);
        } elseif (is_array($groupTags)) {
            // 已是数组
        } else {
            $groupTags = ['control', 'treatment'];
        }
        $id = ExperimentService::create([
            'app_id'       => (int) $this->request->post('app_id', 0),
            'name'         => (string) $this->request->post('name', ''),
            'description'  => (string) $this->request->post('description', ''),
            'group_tags'   => $groupTags,
            'start_at'     => (string) $this->request->post('start_at', ''),
            'end_at'       => (string) $this->request->post('end_at', ''),
        ], $this->adminId());

        $this->opLog('experiment', 'create', $id, '创建实验 ' . (string) $this->request->post('name', ''));
        return success(['id' => $id], '创建成功');
    }

    /**
     * 更新实验（元信息/启停）
     * PUT /api/v1/admin/experiments/:id
     */
    public function update(): Json
    {
        $id = (int) $this->request->param('id');
        $e  = Db::name('experiments')->where('id', $id)->find();
        if (!$e) {
            throw BizException::notFound('实验不存在');
        }
        $data = ['updated_at' => datetime_now()];
        $name = trim((string) $this->request->put('name', ''));
        if ($name !== '') {
            $data['name'] = $name;
        }
        $desc = (string) $this->request->put('description', '');
        if ($desc !== '') {
            $data['description'] = $desc;
        }
        $status = (string) $this->request->put('status', '');
        if ($status !== '' && in_array($status, ExperimentService::STATUS, true)) {
            $data['status'] = $status;
        }
        $startAt = (string) $this->request->put('start_at', '');
        if ($startAt !== '') {
            $data['start_at'] = $startAt;
        }
        $endAt = (string) $this->request->put('end_at', '');
        if ($endAt !== '') {
            $data['end_at'] = $endAt;
        }
        Db::name('experiments')->where('id', $id)->update($data);
        $this->opLog('experiment', 'update', $id, '更新实验 ' . $e['name'] . ' status=' . ($data['status'] ?? $e['status']));
        return success(null, '更新成功');
    }

    /**
     * 绑定组版本
     * POST /api/v1/admin/experiments/:id/assign
     * body: group_tag, version_id, rollout_percent
     */
    public function assign(): Json
    {
        $id = (int) $this->request->param('id');
        ExperimentService::assignVersion(
            $id,
            (string) $this->request->post('group_tag', ''),
            (int) $this->request->post('version_id', 0),
            (int) $this->request->post('rollout_percent', 100)
        );
        $this->opLog('experiment', 'assign', $id, '实验 #' . $id . ' 绑定版本 ' . (int) $this->request->post('version_id', 0) . ' 到组 ' . (string) $this->request->post('group_tag', ''));
        return success(null, '绑定成功');
    }

    /**
     * 删除实验
     */
    public function delete(): Json
    {
        $id = (int) $this->request->param('id');
        $e  = Db::name('experiments')->where('id', $id)->find();
        if (!$e) {
            throw BizException::notFound('实验不存在');
        }
        Db::transaction(function () use ($id) {
            Db::name('experiment_versions')->where('experiment_id', $id)->delete();
            Db::name('experiments')->where('id', $id)->delete();
        });
        $this->opLog('experiment', 'delete', $id, '删除实验 ' . $e['name']);
        return success(null, '删除成功');
    }
}