<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * Crash 统计
 */
class Crashes extends BaseController
{
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $version  = (string) $this->request->get('version_name', '');
        $keyword  = trim((string) $this->request->get('keyword', ''));

        $query = Db::name('crash_reports');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($version !== '') {
            $query->where('version_name', $version);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('device_model', '%' . $keyword . '%')
                    ->whereOr('error_message', 'like', '%' . $keyword . '%');
            });
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$c) {
            $c['app_name'] = $appNames[$c['app_id']] ?? '';
        }
        unset($c);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 崩溃详情（含符号化结果）
     * GET /api/v1/admin/crashes/:id
     */
    public function read(): Json
    {
        $id = (int) $this->request->param('id');
        $c  = Db::name('crash_reports')->where('id', $id)->find();
        if (!$c) {
            throw BizException::notFound('崩溃记录不存在');
        }
        $app = Db::name('apps')->where('id', (int) $c['app_id'])->find();
        $c['app_name'] = $app ? $app['name'] : '';
        $c['symbolized'] = (int) $c['symbolized'];
        return success($c);
    }
}