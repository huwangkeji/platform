<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * 终端用户管理
 */
class Users extends BaseController
{
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = (string) $this->request->get('status', '');

        $query = Db::name('users');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('username', '%' . $keyword . '%')
                    ->whereOr('mobile', 'like', '%' . $keyword . '%')
                    ->whereOr('email', 'like', '%' . $keyword . '%');
            });
        }
        if ($status !== '' && in_array($status, ['0', '1'], true)) {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        foreach ($list as &$u) {
            unset($u['password']); // 脱敏：禁止把密码哈希透出到后台接口
            $u['device_count'] = (int) Db::name('devices')->where('user_id', (int) $u['id'])->count();
            $u['feedback_count'] = (int) Db::name('feedbacks')->where('user_id', (int) $u['id'])->count();
        }
        unset($u);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    public function read(): Json
    {
        $u = Db::name('users')->where('id', (int) $this->request->param('id'))->find();
        if (!$u) {
            throw BizException::notFound('用户不存在');
        }
        unset($u['password']); // 脱敏：禁止把密码哈希透出到后台接口
        $u['device_count']   = (int) Db::name('devices')->where('user_id', (int) $u['id'])->count();
        $u['feedback_count'] = (int) Db::name('feedbacks')->where('user_id', (int) $u['id'])->count();
        $u['download_count'] = (int) Db::name('download_logs')->where('user_id', (int) $u['id'])->count();
        return success($u);
    }

    public function disable(): Json
    {
        $u = Db::name('users')->where('id', (int) $this->request->param('id'))->find();
        if (!$u) {
            throw BizException::notFound('用户不存在');
        }
        Db::name('users')->where('id', (int) $u['id'])->update(['status' => 0, 'updated_at' => datetime_now()]);
        $this->opLog('user', 'disable', (int) $u['id'], '禁用用户：' . ($u['username'] ?: ('#' . $u['id'])));
        return success(null, '已禁用');
    }

    public function enable(): Json
    {
        $u = Db::name('users')->where('id', (int) $this->request->param('id'))->find();
        if (!$u) {
            throw BizException::notFound('用户不存在');
        }
        Db::name('users')->where('id', (int) $u['id'])->update(['status' => 1, 'updated_at' => datetime_now()]);
        $this->opLog('user', 'enable', (int) $u['id'], '启用用户：' . ($u['username'] ?: ('#' . $u['id'])));
        return success(null, '已启用');
    }
}