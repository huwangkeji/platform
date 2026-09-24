<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * 工单管理
 */
class Tickets extends BaseController
{
    private const STATUS = ['pending', 'processing', 'dev_pending', 'dev_processing', 'test_pending', 'solved', 'closed', 'unreproducible', 'duplicate'];
    private const PRIORITY = ['low', 'normal', 'high', 'urgent'];

    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $status   = (string) $this->request->get('status', '');
        $priority = (string) $this->request->get('priority', '');
        $keyword  = trim((string) $this->request->get('keyword', ''));

        $query = Db::name('tickets');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($priority !== '') {
            $query->where('priority', $priority);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('ticket_no', '%' . $keyword . '%')
                    ->whereOr('title', 'like', '%' . $keyword . '%')
                    ->whereOr('content', 'like', '%' . $keyword . '%');
            });
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames   = Db::name('apps')->column('name', 'id');
        $adminNames = Db::name('admin_users')->column('nickname', 'id');
        foreach ($list as &$t) {
            $t['app_name']      = $appNames[$t['app_id']] ?? '';
            $t['assignee_name'] = $adminNames[$t['assignee_id'] ?? 0] ?? '';
        }
        unset($t);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 手动创建工单（无反馈来源）
     */
    public function create(): Json
    {
        $appId = (int) $this->request->post('app_id', 0);
        $title = trim((string) $this->request->post('title', ''));
        if ($appId <= 0 || $title === '') {
            return fail(10003, 'app_id 与 title 不能为空');
        }
        $app = Db::name('apps')->where('id', $appId)->find();
        if (!$app) {
            return fail(10004, '应用不存在');
        }
        $now = datetime_now();
        $priority = (string) $this->request->post('priority', 'normal');
        if (!in_array($priority, self::PRIORITY, true)) {
            $priority = 'normal';
        }

        // 工单号基于插入后的自增 id 生成（占位写入后回填），
        // 避免 max(id)+1 在并发创建时产生重复工单号。
        $id = Db::name('tickets')->insertGetId([
            'ticket_no'    => 'BUG-' . date('Ymd') . '-000',
            'feedback_id'  => (int) $this->request->post('feedback_id', 0) ?: null,
            'app_id'       => $appId,
            'title'        => $title,
            'content'      => (string) $this->request->post('content', ''),
            'priority'     => $priority,
            'status'       => 'pending',
            'assignee_id'  => (int) $this->request->post('assignee_id', 0) ?: null,
            'solution'     => '',
            'fixed_version_id' => (int) $this->request->post('fixed_version_id', 0) ?: null,
            'closed_at'    => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        $ticketNo = sprintf('BUG-%s-%03d', date('Ymd'), (int) $id);
        Db::name('tickets')->where('id', (int) $id)->update(['ticket_no' => $ticketNo, 'updated_at' => $now]);

        $this->opLog('ticket', 'create', $id, '创建工单 ' . $ticketNo . '：' . $title);
        return success(['id' => $id, 'ticket_no' => $ticketNo], '创建成功');
    }

    public function read(): Json
    {
        $t = Db::name('tickets')->where('id', (int) $this->request->param('id'))->find();
        if (!$t) {
            throw BizException::notFound('工单不存在');
        }
        $t['app_name']      = Db::name('apps')->where('id', (int) $t['app_id'])->value('name') ?: '';
        $t['assignee_name'] = Db::name('admin_users')->where('id', (int) ($t['assignee_id'] ?? 0))->value('nickname') ?: '';
        $t['feedback']      = $t['feedback_id'] ? Db::name('feedbacks')->where('id', (int) $t['feedback_id'])->find() : null;
        return success($t);
    }

    /**
     * 处理工单（状态/负责人/解决方案/解决版本）
     */
    public function update(): Json
    {
        $t = Db::name('tickets')->where('id', (int) $this->request->param('id'))->find();
        if (!$t) {
            throw BizException::notFound('工单不存在');
        }
        $data = ['updated_at' => datetime_now()];

        $status = (string) $this->request->put('status', '');
        if ($status !== '') {
            if (!in_array($status, self::STATUS, true)) {
                return fail(10003, '工单状态不合法');
            }
            $data['status'] = $status;
            if (in_array($status, ['solved', 'closed'], true)) {
                $data['closed_at'] = datetime_now();
            } elseif (in_array($status, ['pending', 'processing', 'dev_pending', 'dev_processing', 'test_pending'], true)) {
                $data['closed_at'] = null;
            }
        }
        $assignee = (int) $this->request->put('assignee_id', 0);
        if ($this->request->has('assignee_id', 'put')) {
            $data['assignee_id'] = $assignee > 0 ? $assignee : null;
        }
        $priority = (string) $this->request->put('priority', '');
        if ($priority !== '') {
            if (!in_array($priority, self::PRIORITY, true)) {
                return fail(10003, '优先级不合法');
            }
            $data['priority'] = $priority;
        }
        if ($this->request->has('solution', 'put')) {
            $data['solution'] = (string) $this->request->put('solution', '');
        }
        $fixed = (int) $this->request->put('fixed_version_id', 0);
        if ($this->request->has('fixed_version_id', 'put')) {
            $data['fixed_version_id'] = $fixed > 0 ? $fixed : null;
        }

        Db::name('tickets')->where('id', (int) $t['id'])->update($data);

        // 联动反馈状态
        if ($t['feedback_id'] && in_array($data['status'] ?? '', ['solved', 'closed'], true)) {
            Db::name('feedbacks')->where('id', (int) $t['feedback_id'])->update(['status' => 'solved', 'updated_at' => datetime_now()]);
        }

        $this->opLog('ticket', 'update', (int) $t['id'], '处理工单 ' . $t['ticket_no'] . '：' . ($data['status'] ?? '无状态变化'));
        return success(null, '更新成功');
    }
}