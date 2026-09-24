<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * 设备管理
 */
class Devices extends BaseController
{
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $channel  = (string) $this->request->get('channel', '');
        $model    = (string) $this->request->get('model', '');

        $query = Db::name('devices');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('device_id', '%' . $keyword . '%')
                    ->whereOr('device_sn', 'like', '%' . $keyword . '%')
                    ->whereOr('imei', 'like', '%' . $keyword . '%')
                    ->whereOr('mac', 'like', '%' . $keyword . '%')
                    ->whereOr('model', 'like', '%' . $keyword . '%');
            });
        }
        if ($channel !== '') {
            $query->where('channel_code', $channel);
        }
        if ($model !== '') {
            $query->where('model', $model);
        }

        $total = $query->count();
        $list  = $query->order('last_active_at', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($list as &$d) {
            $d['download_count'] = (int) Db::name('download_logs')->where('device_id', (int) $d['id'])->count();
        }
        unset($d);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    public function read(): Json
    {
        $d = Db::name('devices')->where('id', (int) $this->request->param('id'))->find();
        if (!$d) {
            throw BizException::notFound('设备不存在');
        }
        $d['download_count'] = (int) Db::name('download_logs')->where('device_id', (int) $d['id'])->count();
        $d['crash_count']    = (int) Db::name('crash_reports')->where('device_id', (int) $d['id'])->count();
        $d['feedback_count'] = (int) Db::name('feedbacks')->where('device_id', (int) $d['id'])->count();
        return success($d);
    }
}