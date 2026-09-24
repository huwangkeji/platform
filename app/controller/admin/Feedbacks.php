<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * 用户反馈管理
 */
class Feedbacks extends BaseController
{
    private const STATUS = ['pending', 'open', 'processing', 'solved', 'closed'];

    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $status   = (string) $this->request->get('status', '');
        $type     = (string) $this->request->get('type', '');
        $keyword  = trim((string) $this->request->get('keyword', ''));

        $query = Db::name('feedbacks');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', '%' . $keyword . '%')
                    ->whereOr('content', 'like', '%' . $keyword . '%')
                    ->whereOr('contact', 'like', '%' . $keyword . '%');
            });
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$f) {
            $f['app_name'] = $appNames[$f['app_id']] ?? '';
        }
        unset($f);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    public function read(): Json
    {
        $f = Db::name('feedbacks')->where('id', (int) $this->request->param('id'))->find();
        if (!$f) {
            throw BizException::notFound('反馈不存在');
        }
        $f['app_name'] = Db::name('apps')->where('id', (int) $f['app_id'])->value('name') ?: '';
        $f['ticket']   = Db::name('tickets')->where('feedback_id', (int) $f['id'])->find();
        return success($f);
    }

    /**
     * 处理反馈（状态流转）
     */
    public function update(): Json
    {
        $f = Db::name('feedbacks')->where('id', (int) $this->request->param('id'))->find();
        if (!$f) {
            throw BizException::notFound('反馈不存在');
        }
        $status = (string) $this->request->put('status', '');
        if ($status !== '' && !in_array($status, self::STATUS, true)) {
            return fail(10003, '反馈状态不合法');
        }
        $data = ['updated_at' => datetime_now()];
        if ($status !== '') {
            $data['status'] = $status;
        }

        Db::name('feedbacks')->where('id', (int) $f['id'])->update($data);
        $this->opLog('feedback', 'update', (int) $f['id'], '处理反馈：' . $f['title'] . ' → ' . ($status ?: '无变化'));
        return success(null, '更新成功');
    }
}