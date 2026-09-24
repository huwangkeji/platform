<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\response\Json;

/**
 * 公告列表（APP 端公开）
 */
class Notice extends BaseController
{
    /**
     * GET /api/v1/notice/list?app_code=xxx
     */
    public function list(): Json
    {
        $appCode = trim((string) $this->request->get('app_code', ''));
        $now     = datetime_now();

        $query = Db::name('notices')
            ->where('status', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('start_at')->whereOr('start_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_at')->whereOr('end_at', '>=', $now);
            })
            ->order('id', 'desc');

        if ($appCode !== '') {
            $app = $this->findApp($appCode);
            if (!$app) {
                return fail(10002, '应用不存在或已停用');
            }
            $query->where(function ($q) use ($app) {
                $q->whereNull('app_id')->whereOr('app_id', (int) $app['id']);
            });
        } else {
            $query->whereNull('app_id');
        }

        $list = $query->limit(50)->select()->toArray();
        $data = array_map(function ($n) {
            return [
                'id'         => (int) $n['id'],
                'title'      => (string) $n['title'],
                'content'    => (string) $n['content'],
                'start_at'   => (string) ($n['start_at'] ?? ''),
                'end_at'     => (string) ($n['end_at'] ?? ''),
                'created_at' => (string) $n['created_at'],
            ];
        }, $list);

        return success($data);
    }
}