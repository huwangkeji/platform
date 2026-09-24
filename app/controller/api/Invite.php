<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\exception\BizException;
use app\service\InviteService;
use think\facade\Db;
use think\response\Json;

/**
 * 邀请链接消费（APP/测试人员公开）
 * GET /api/v1/invite/check?token=xxx  校验邀请并返回应用/群组信息
 * POST /api/v1/invite/accept 确认加入（登记设备到群组）
 */
class Invite extends BaseController
{
    /**
     * 校验邀请链接
     */
    public function check(): Json
    {
        $token  = trim((string) $this->request->get('token', ''));
        $invite = InviteService::consume($token);

        $app  = Db::name('apps')->where('id', (int) $invite['app_id'])->find();
        $group = null;
        if (!empty($invite['group_id'])) {
            $group = Db::name('tester_groups')->where('id', (int) $invite['group_id'])->find();
        }
        return success([
            'token'      => $token,
            'app'        => $app ? ['id' => (int) $app['id'], 'name' => $app['name'], 'code' => $app['code'], 'icon' => $app['icon'] ?? ''] : null,
            'group'      => $group ? ['id' => (int) $group['id'], 'name' => $group['name']] : null,
            'invite_type'=> $invite['invite_type'],
            'expire_at'  => $invite['expire_at'],
        ]);
    }

    /**
     * 接受邀请并加入群组（登记设备）
     * POST /api/v1/invite/accept
     * body: token + device_id
     */
    public function accept(): Json
    {
        $token  = trim((string) $this->request->post('token', ''));
        $invite = InviteService::consume($token);

        $deviceIdStr = trim((string) $this->request->post('device_id', ''));
        $device      = null;
        if ($deviceIdStr !== '') {
            $device = Db::name('devices')->where('device_id', $deviceIdStr)->find();
            if (!$device) {
                return fail(10004, '设备未注册，请先调用设备注册接口');
            }
        }

        // 加入群组
        if (!empty($invite['group_id'])) {
            $exists = Db::name('tester_group_members')->where('group_id', (int) $invite['group_id'])
                ->where(function ($q) use ($device) {
                    if ($device) {
                        $q->whereOr('device_id', (int) $device['id']);
                    }
                })
                ->find();
            if (!$exists && $device) {
                Db::name('tester_group_members')->insert([
                    'group_id'   => (int) $invite['group_id'],
                    'device_id'  => (int) $device['id'],
                    'user_id'    => (int) ($device['user_id'] ?? 0) > 0 ? (int) $device['user_id'] : null,
                    'email'      => $invite['email'],
                    'remark'     => '邀请链接加入',
                    'status'     => 1,
                    'created_at' => datetime_now(),
                ]);
            }
        }

        return success(['group_id' => $invite['group_id'] ? (int) $invite['group_id'] : null], '欢迎加入测试计划');
    }
}