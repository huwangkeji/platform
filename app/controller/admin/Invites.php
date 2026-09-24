<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\InviteService;
use think\facade\Db;
use think\response\Json;

/**
 * 邀请链接/邮件邀请管理
 */
class Invites extends BaseController
{
    /**
     * 邀请列表
     */
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $status   = (string) $this->request->get('status', '');

        $query = Db::name('invites');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($status !== '' && in_array($status, InviteService::STATUS, true)) {
            $query->where('status', $status);
        }
        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$inv) {
            $inv['app_name'] = $appNames[$inv['app_id']] ?? '';
            $inv['link']     = InviteService::inviteLink((string) $inv['token']);
        }
        unset($inv);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 创建邀请
     * POST /api/v1/admin/invites
     * body: app_id, email?, group_id?, max_uses?, expire_days?, invite_type?
     */
    public function create(): Json
    {
        $invite = InviteService::create([
            'app_id'      => (int) $this->request->post('app_id', 0),
            'email'       => (string) $this->request->post('email', ''),
            'group_id'    => (int) $this->request->post('group_id', 0),
            'max_uses'    => (int) $this->request->post('max_uses', 1),
            'expire_days' => (int) $this->request->post('expire_days', 7),
            'invite_type' => (string) $this->request->post('invite_type', 'test'),
        ], $this->adminId());

        return success($invite, '邀请创建成功');
    }

    /**
     * 停用/恢复邀请
     * POST /api/v1/admin/invites/:id/toggle
     */
    public function toggle(): Json
    {
        $id = (int) $this->request->param('id');
        $inv = Db::name('invites')->where('id', $id)->find();
        if (!$inv) {
            throw BizException::notFound('邀请不存在');
        }
        $newStatus = $inv['status'] === 'active' ? 'disabled' : 'active';
        if ($newStatus === 'active' && $inv['expire_at'] !== null && strtotime((string) $inv['expire_at']) < time()) {
            $newStatus = 'expired';
        }
        Db::name('invites')->where('id', $id)->update(['status' => $newStatus, 'updated_at' => datetime_now()]);
        $this->opLog('invite', 'toggle', $id, '邀请 #' . $id . ' 状态 → ' . $newStatus);
        return success(['status' => $newStatus], '已更新');
    }

    /**
     * 删除邀请
     */
    public function delete(): Json
    {
        $id = (int) $this->request->param('id');
        $inv = Db::name('invites')->where('id', $id)->find();
        if (!$inv) {
            throw BizException::notFound('邀请不存在');
        }
        Db::name('invites')->where('id', $id)->delete();
        $this->opLog('invite', 'delete', $id, '删除邀请 #' . $id);
        return success(null, '删除成功');
    }
}