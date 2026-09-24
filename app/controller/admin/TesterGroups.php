<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * 测试人员群组管理：分组维护测试人员/设备，供定向发布与邀请使用
 */
class TesterGroups extends BaseController
{
    /**
     * 群组列表（含成员数）
     */
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);

        $query = Db::name('tester_groups');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$g) {
            $g['app_name'] = (int) $g['app_id'] === 0 ? '（全局）' : ($appNames[$g['app_id']] ?? '');
            $g['member_count'] = (int) Db::name('tester_group_members')->where('group_id', (int) $g['id'])->count();
        }
        unset($g);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 创建群组
     */
    public function create(): Json
    {
        $appId = (int) $this->request->post('app_id', 0);
        $name  = trim((string) $this->request->post('name', ''));
        if ($name === '') {
            return fail(10003, '群组名称不能为空');
        }
        if ($appId < 0) {
            return fail(10003, 'app_id 不合法');
        }
        $now = datetime_now();
        $id  = Db::name('tester_groups')->insertGetId([
            'app_id'      => $appId,
            'name'        => $name,
            'description' => (string) $this->request->post('description', ''),
            'status'      => 1,
            'created_by'  => $this->adminId(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        $this->opLog('tester', 'create', $id, '创建测试群组 ' . $name . '（app_id=' . $appId . '）');
        return success(['id' => $id], '创建成功');
    }

    /**
     * 更新群组
     */
    public function update(): Json
    {
        $id = (int) $this->request->param('id');
        $g  = Db::name('tester_groups')->where('id', $id)->find();
        if (!$g) {
            throw BizException::notFound('群组不存在');
        }
        $data = [
            'name'        => trim((string) $this->request->put('name', $g['name'])),
            'description' => (string) $this->request->put('description', $g['description'] ?? ''),
            'updated_at'  => datetime_now(),
        ];
        if ($data['name'] === '') {
            return fail(10003, '群组名称不能为空');
        }
        Db::name('tester_groups')->where('id', $id)->update($data);
        $this->opLog('tester', 'update', $id, '更新测试群组 ' . $data['name']);
        return success(null, '更新成功');
    }

    /**
     * 删除群组（级联删除成员关系）
     */
    public function delete(): Json
    {
        $id = (int) $this->request->param('id');
        $g  = Db::name('tester_groups')->where('id', $id)->find();
        if (!$g) {
            throw BizException::notFound('群组不存在');
        }
        Db::transaction(function () use ($id) {
            Db::name('tester_group_members')->where('group_id', $id)->delete();
            Db::name('tester_groups')->where('id', $id)->delete();
        });
        $this->opLog('tester', 'delete', $id, '删除测试群组 ' . $g['name']);
        return success(null, '删除成功');
    }

    /**
     * 群组成员列表
     * GET /api/v1/admin/tester-groups/:id/members
     */
    public function members(): Json
    {
        $id = (int) $this->request->param('id');
        if (!Db::name('tester_groups')->where('id', $id)->find()) {
            throw BizException::notFound('群组不存在');
        }
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));

        $query = Db::name('tester_group_members')->where('group_id', $id);
        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        // 补充用户/设备名称
        $userIds   = array_filter(array_column($list, 'user_id'));
        $deviceIds = array_filter(array_column($list, 'device_id'));
        $users  = $userIds   ? Db::name('users')->whereIn('id', $userIds)->column('username', 'id') : [];
        $devs   = $deviceIds ? Db::name('devices')->whereIn('id', $deviceIds)->column('device_id', 'id') : [];
        foreach ($list as &$m) {
            $m['username']   = $users[$m['user_id']] ?? '';
            $m['device_code'] = $devs[$m['device_id']] ?? '';
        }
        unset($m);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 添加成员
     * POST /api/v1/admin/tester-groups/:id/members
     * body: user_id / device_id / email
     */
    public function addMember(): Json
    {
        $id = (int) $this->request->param('id');
        if (!Db::name('tester_groups')->where('id', $id)->find()) {
            throw BizException::notFound('群组不存在');
        }
        $userId   = (int) $this->request->post('user_id', 0);
        $deviceId = (int) $this->request->post('device_id', 0);
        $email    = trim((string) $this->request->post('email', ''));
        if ($userId <= 0 && $deviceId <= 0 && $email === '') {
            return fail(10003, '请至少提供 user_id / device_id / email 之一');
        }
        $now = datetime_now();

        // 用户转设备：仅记录 user_id 时把它名下最近设备也关联，方便发布规则分流
        if ($userId > 0 && $deviceId <= 0) {
            $dev = Db::name('devices')->where('user_id', $userId)->order('last_active_at', 'desc')->find();
            if ($dev) {
                $deviceId = (int) $dev['id'];
            }
        }
        $dup = Db::name('tester_group_members')->where('group_id', $id)
            ->where(function ($q) use ($userId, $deviceId, $email) {
                if ($userId > 0) {
                    $q->whereOr('user_id', $userId);
                }
                if ($deviceId > 0) {
                    $q->whereOr('device_id', $deviceId);
                }
                if ($email !== '') {
                    $q->whereOr('email', $email);
                }
            })
            ->find();
        if ($dup) {
            return fail(10005, '该成员已在群组中');
        }

        $mid = Db::name('tester_group_members')->insertGetId([
            'group_id'   => $id,
            'user_id'    => $userId > 0 ? $userId : null,
            'device_id'  => $deviceId > 0 ? $deviceId : null,
            'email'      => $email !== '' ? $email : null,
            'remark'     => (string) $this->request->post('remark', ''),
            'status'     => 1,
            'created_at' => $now,
        ]);
        $this->opLog('tester', 'add_member', $mid, '添加测试成员到群组 #' . $id);
        return success(['id' => $mid], '添加成功');
    }

    /**
     * 移除成员
     */
    public function removeMember(): Json
    {
        $id = (int) $this->request->param('id');
        $m  = Db::name('tester_group_members')->where('id', $id)->find();
        if (!$m) {
            throw BizException::notFound('成员不存在');
        }
        Db::name('tester_group_members')->where('id', $id)->delete();
        $this->opLog('tester', 'remove_member', $id, '移除测试成员（群组 #' . $m['group_id'] . '）');
        return success(null, '移除成功');
    }
}