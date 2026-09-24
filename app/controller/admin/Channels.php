<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * 渠道管理
 */
class Channels extends BaseController
{
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);

        $query = Db::name('channels');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$c) {
            $c['app_name'] = $c['app_id'] == 0 ? '全局渠道' : ($appNames[$c['app_id']] ?? '');
            $c['download_count'] = (int) Db::name('download_logs')->where('channel_code', (string) $c['channel_code'])->count();
        }
        unset($c);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    public function create(): Json
    {
        $appId  = (int) $this->request->post('app_id', 0);
        $code   = trim((string) $this->request->post('channel_code', ''));
        $name   = trim((string) $this->request->post('channel_name', ''));
        if ($code === '' || $name === '') {
            return fail(10003, 'channel_code/channel_name 不能为空');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]{0,63}$/', $code)) {
            return fail(10003, '渠道编码仅支持字母/数字/下划线/中划线');
        }
        if ($appId > 0 && !Db::name('apps')->where('id', $appId)->find()) {
            return fail(10004, '应用不存在');
        }
        $dup = Db::name('channels')->where('app_id', $appId)->where('channel_code', $code)->find();
        if ($dup) {
            return fail(10005, '该应用下渠道编码已存在');
        }

        $now = datetime_now();
        $id  = Db::name('channels')->insertGetId([
            'app_id'       => $appId,
            'channel_code' => $code,
            'channel_name' => $name,
            'status'       => 1,
            'remark'       => (string) $this->request->post('remark', ''),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $this->opLog('channel', 'create', $id, '创建渠道：' . $name . ' (' . $code . ')');
        return success(['id' => $id], '创建成功');
    }

    public function update(): Json
    {
        $c = Db::name('channels')->where('id', (int) $this->request->param('id'))->find();
        if (!$c) {
            throw BizException::notFound('渠道不存在');
        }
        $data = ['updated_at' => datetime_now()];

        $name = trim((string) $this->request->put('channel_name', ''));
        if ($name !== '') {
            $data['channel_name'] = $name;
        }
        $code = trim((string) $this->request->put('channel_code', ''));
        if ($code !== '') {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]{0,63}$/', $code)) {
                return fail(10003, '渠道编码仅支持字母/数字/下划线/中划线');
            }
            $dup = Db::name('channels')->where('app_id', (int) $c['app_id'])->where('channel_code', $code)->where('id', '<>', (int) $c['id'])->find();
            if ($dup) {
                return fail(10005, '该应用下渠道编码已存在');
            }
            $data['channel_code'] = $code;
        }
        $status = (string) $this->request->put('status', '');
        if ($status !== '' && in_array($status, ['0', '1'], true)) {
            $data['status'] = (int) $status;
        }
        $remark = (string) $this->request->put('remark', '');
        if ($this->request->has('remark', 'put')) {
            $data['remark'] = $remark;
        }

        Db::name('channels')->where('id', (int) $c['id'])->update($data);
        $this->opLog('channel', 'update', (int) $c['id'], '更新渠道：' . ($data['channel_name'] ?? $c['channel_name']));
        return success(null, '更新成功');
    }

    public function delete(): Json
    {
        $c = Db::name('channels')->where('id', (int) $this->request->param('id'))->find();
        if (!$c) {
            throw BizException::notFound('渠道不存在');
        }
        Db::name('channels')->where('id', (int) $c['id'])->delete();
        $this->opLog('channel', 'delete', (int) $c['id'], '删除渠道：' . $c['channel_name']);
        return success(null, '删除成功');
    }
}