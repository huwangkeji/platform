<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\response\Json;

/**
 * 设备注册/心跳（APP 端公开）
 */
class Device extends BaseController
{
    /**
     * 设备注册（device_id 幂等）
     * POST /api/v1/device/register
     */
    public function register(): Json
    {
        $deviceId = trim((string) $this->request->post('device_id', ''));
        if ($deviceId === '') {
            return fail(10002, 'device_id 不能为空');
        }
        $now = datetime_now();

        $exists = Db::name('devices')->where('device_id', $deviceId)->find();
        if ($exists) {
            Db::name('devices')->where('id', (int) $exists['id'])->update([
                'app_version'    => (string) $this->request->post('app_version', ''),
                'os_version'     => (string) $this->request->post('os_version', ''),
                'channel_code'   => (string) $this->request->post('channel', ''),
                'last_active_at' => $now,
                'updated_at'     => $now,
            ]);
            $id = (int) $exists['id'];
        } else {
            $id = Db::name('devices')->insertGetId([
                'user_id'         => (int) $this->request->post('user_id', 0) ?: null,
                'device_id'       => $deviceId,
                'device_sn'       => (string) $this->request->post('device_sn', ''),
                'model'           => (string) $this->request->post('model', ''),
                'os_version'      => (string) $this->request->post('os_version', ''),
                'app_version'     => (string) $this->request->post('app_version', ''),
                'channel_code'    => (string) $this->request->post('channel', ''),
                'imei'            => (string) $this->request->post('imei', ''),
                'mac'             => (string) $this->request->post('mac', ''),
                'firmware_version'=> (string) $this->request->post('firmware_version', ''),
                'status'          => 1,
                'first_active_at' => $now,
                'last_active_at'  => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // 返回客户端提交的原始 device_id（对外设备标识），并附带内部自增 id 供服务端后续查询
        return success(['device_id' => $deviceId, 'id' => $id], '注册成功');
    }

    /**
     * 设备心跳
     * POST /api/v1/device/heartbeat
     */
    public function heartbeat(): Json
    {
        $deviceId = trim((string) $this->request->post('device_id', ''));
        if ($deviceId === '') {
            return fail(10002, 'device_id 不能为空');
        }
        $now = datetime_now();

        $exists = Db::name('devices')->where('device_id', $deviceId)->find();
        if ($exists) {
            Db::name('devices')->where('id', (int) $exists['id'])->update([
                'app_version'    => (string) $this->request->post('app_version', ''),
                'os_version'     => (string) $this->request->post('os_version', ''),
                'channel_code'   => (string) $this->request->post('channel', ''),
                'last_active_at' => $now,
                'updated_at'     => $now,
            ]);
        } else {
            // 未注册的设备心跳自动注册
            Db::name('devices')->insert([
                'user_id'         => (int) $this->request->post('user_id', 0) ?: null,
                'device_id'       => $deviceId,
                'app_version'     => (string) $this->request->post('app_version', ''),
                'os_version'      => (string) $this->request->post('os_version', ''),
                'channel_code'    => (string) $this->request->post('channel', ''),
                'status'          => 1,
                'first_active_at' => $now,
                'last_active_at'  => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        return success(null, '心跳成功');
    }

    /**
     * 查应用但不抛异常（设备注册不强制应用存在）
     */
    protected function safeFindApp(string $code): ?array
    {
        $app = Db::name('apps')->where('code', $code)->where('status', 1)->find();
        return $app ?: null;
    }
}