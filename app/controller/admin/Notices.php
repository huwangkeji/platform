<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * 公告管理
 */
class Notices extends BaseController
{
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $keyword  = trim((string) $this->request->get('keyword', ''));

        $query = Db::name('notices');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', '%' . $keyword . '%')
                    ->whereOr('content', 'like', '%' . $keyword . '%');
            });
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$n) {
            $n['app_name'] = $n['app_id'] ? ($appNames[$n['app_id']] ?? '') : '全局公告';
        }
        unset($n);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    public function create(): Json
    {
        $title   = trim((string) $this->request->post('title', ''));
        $content = trim((string) $this->request->post('content', ''));
        if ($title === '' || $content === '') {
            return fail(10003, 'title/content 不能为空');
        }
        $appId = (int) $this->request->post('app_id', 0);
        if ($appId > 0 && !Db::name('apps')->where('id', $appId)->find()) {
            return fail(10004, '应用不存在');
        }
        $now = datetime_now();
        $id  = Db::name('notices')->insertGetId([
            'app_id'     => $appId > 0 ? $appId : null,
            'title'      => $title,
            'content'    => $content,
            'status'     => (int) $this->request->post('status', 1) === 1 ? 1 : 0,
            'start_at'   => (string) $this->request->post('start_at', '') ?: null,
            'end_at'     => (string) $this->request->post('end_at', '') ?: null,
            'created_by' => $this->adminId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->opLog('notice', 'create', $id, '创建公告：' . $title);
        return success(['id' => $id], '创建成功');
    }

    public function update(): Json
    {
        $n = Db::name('notices')->where('id', (int) $this->request->param('id'))->find();
        if (!$n) {
            throw BizException::notFound('公告不存在');
        }
        $data = ['updated_at' => datetime_now()];

        $title = trim((string) $this->request->put('title', ''));
        if ($title !== '') {
            $data['title'] = $title;
        }
        if ($this->request->has('content', 'put')) {
            $data['content'] = (string) $this->request->put('content', '');
        }
        $status = (string) $this->request->put('status', '');
        if ($status !== '' && in_array($status, ['0', '1'], true)) {
            $data['status'] = (int) $status;
        }
        if ($this->request->has('start_at', 'put')) {
            $data['start_at'] = (string) $this->request->put('start_at', '') ?: null;
        }
        if ($this->request->has('end_at', 'put')) {
            $data['end_at'] = (string) $this->request->put('end_at', '') ?: null;
        }

        Db::name('notices')->where('id', (int) $n['id'])->update($data);
        $this->opLog('notice', 'update', (int) $n['id'], '更新公告：' . ($data['title'] ?? $n['title']));
        return success(null, '更新成功');
    }

    public function delete(): Json
    {
        $n = Db::name('notices')->where('id', (int) $this->request->param('id'))->find();
        if (!$n) {
            throw BizException::notFound('公告不存在');
        }
        Db::name('notices')->where('id', (int) $n['id'])->delete();
        $this->opLog('notice', 'delete', (int) $n['id'], '删除公告：' . $n['title']);
        return success(null, '删除成功');
    }
}