<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\ExportService;
use app\service\RbacService;
use think\Response;
use think\facade\Db;
use think\response\Json;

/**
 * 系统设置：管理员/角色/权限/操作日志
 */
class System extends BaseController
{
    /**
     * 管理员列表
     */
    public function admins(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $keyword  = trim((string) $this->request->get('keyword', ''));

        $query = Db::name('admin_users');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('username', '%' . $keyword . '%')
                    ->whereOr('nickname', 'like', '%' . $keyword . '%');
            });
        }

        $total = $query->count();
        $list  = $query->order('id', 'asc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        foreach ($list as &$u) {
            unset($u['password']);
            $roleIds = Db::name('admin_role')->where('admin_id', (int) $u['id'])->column('role_id');
            $u['roles'] = $roleIds ? Db::name('roles')->whereIn('id', $roleIds)->select()->toArray() : [];
        }
        unset($u);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 创建管理员
     */
    public function createAdmin(): Json
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');
        if ($username === '' || strlen($password) < 6) {
            return fail(10003, '用户名不能为空且密码至少 6 位');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,63}$/', $username)) {
            return fail(10003, '用户名仅支持字母开头、字母数字下划线，3-64 位');
        }
        if (Db::name('admin_users')->where('username', $username)->find()) {
            return fail(10004, '用户名已存在');
        }

        $now = datetime_now();
        // insertGetId 在 MySQL 驱动下返回 string，需强转 int 再传入强类型参数（bindRoles/opLog）
        $id  = (int) Db::name('admin_users')->insertGetId([
            'username'   => $username,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'nickname'   => (string) $this->request->post('nickname', ''),
            'email'      => (string) $this->request->post('email', ''),
            'mobile'     => (string) $this->request->post('mobile', ''),
            'avatar'     => (string) $this->request->post('avatar', ''),
            'status'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleIds = $this->roleIdsFromPost((array) $this->request->post('roles', []));
        if ($roleIds) {
            $this->bindRoles($id, $roleIds);
        }

        $this->opLog('admin', 'create', $id, '创建管理员：' . $username);
        return success(['id' => $id], '创建成功');
    }

    /**
     * 更新管理员
     */
    public function updateAdmin(): Json
    {
        $id = (int) $this->request->param('id');
        $u  = Db::name('admin_users')->where('id', $id)->find();
        if (!$u) {
            throw BizException::notFound('管理员不存在');
        }
        $me = $this->adminId();
        $data = ['updated_at' => datetime_now()];

        $nickname = (string) $this->request->put('nickname', '');
        if ($this->request->has('nickname', 'put')) {
            $data['nickname'] = $nickname;
        }
        $email = (string) $this->request->put('email', '');
        if ($this->request->has('email', 'put')) {
            $data['email'] = $email;
        }
        $mobile = (string) $this->request->put('mobile', '');
        if ($this->request->has('mobile', 'put')) {
            $data['mobile'] = $mobile;
        }
        $status = (string) $this->request->put('status', '');
        if ($status !== '' && in_array($status, ['0', '1'], true)) {
            if ((int) $status === 0 && $me === $id) {
                return fail(10008, '不能禁用自己的账号');
            }
            $data['status'] = (int) $status;
        }
        $password = (string) $this->request->put('password', '');
        if ($password !== '') {
            if (strlen($password) < 6) {
                return fail(10003, '密码至少 6 位');
            }
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        Db::name('admin_users')->where('id', $id)->update($data);

        // 角色重绑
        if ($this->request->has('roles', 'put')) {
            $roles = (array) $this->request->put('roles', []);
            if ($me === $id) {
                // 防止把自己从 super_admin 移除导致系统失管
                if (RbacService::isSuper($id)
                    && !in_array((string) Db::name('roles')->where('code', 'super_admin')->value('id'), array_map('strval', $this->roleIdsFromPost($roles)), true)) {
                    return fail(10008, '不能移除自己的超级管理员角色');
                }
            }
            $this->bindRoles($id, $this->roleIdsFromPost($roles));
        }
        RbacService::invalidate($id);

        $this->opLog('admin', 'update', $id, '更新管理员：' . $u['username']);
        return success(null, '更新成功');
    }

    /**
     * 角色列表
     */
    public function roles(): Json
    {
        $list = Db::name('roles')->order('id', 'asc')->select()->toArray();
        foreach ($list as &$r) {
            $r['permission_count'] = (int) Db::name('role_permission')->where('role_id', (int) $r['id'])->count();
            $r['admin_count']      = (int) Db::name('admin_role')->where('role_id', (int) $r['id'])->count();
            $r['permissions']      = Db::name('role_permission')->where('role_id', (int) $r['id'])->column('permission_id');
        }
        unset($r);
        return success($list);
    }

    /**
     * 权限树
     */
    public function permissions(): Json
    {
        $all = Db::name('permissions')->order('id', 'asc')->select()->toArray();
        $map = [];
        foreach ($all as $p) {
            $p['children'] = [];
            $map[(int) $p['id']] = $p;
        }
        $tree = [];
        foreach ($map as $p) {
            if ((int) $p['parent_id'] > 0 && isset($map[(int) $p['parent_id']])) {
                $map[(int) $p['parent_id']]['children'][] = $p;
            } else {
                $tree[] = $p;
            }
        }
        // 重新挂载 children（引用修复）
        foreach ($tree as &$node) {
            $node = $this->attachChildren($node, $map);
        }
        unset($node);
        return success($tree);
    }

    /**
     * 更新角色（名称 + 权限集合）
     */
    public function updateRole(): Json
    {
        $id = (int) $this->request->param('id');
        $role = Db::name('roles')->where('id', $id)->find();
        if (!$role) {
            throw BizException::notFound('角色不存在');
        }

        $name = trim((string) $this->request->put('name', ''));
        $data = ['updated_at' => datetime_now()];
        if ($name !== '') {
            $data['name'] = $name;
        }
        $status = (string) $this->request->put('status', '');
        if ($status !== '' && in_array($status, ['0', '1'], true)) {
            $data['status'] = (int) $status;
        }

        Db::transaction(function () use ($id, $data, $role) {
            Db::name('roles')->where('id', $id)->update($data);

            if ($this->request->has('permissions', 'put')) {
                $permIds = array_map('intval', (array) $this->request->put('permissions', []));
                $permIds = array_values(array_unique(array_filter($permIds)));
                $valid   = $permIds ? Db::name('permissions')->whereIn('id', $permIds)->column('id') : [];
                Db::name('role_permission')->where('role_id', $id)->delete();
                foreach ($valid as $pid) {
                    Db::name('role_permission')->insert(['role_id' => $id, 'permission_id' => (int) $pid]);
                }
                // 清空受影响管理员的权限缓存
                $adminIds = Db::name('admin_role')->where('role_id', $id)->column('admin_id');
                foreach ($adminIds as $aid) {
                    RbacService::invalidate((int) $aid);
                }
            }
        });

        $this->opLog('admin', 'update_role', $id, '更新角色：' . $role['name']);
        return success(null, '更新成功');
    }

    /**
     * 操作日志
     */
    public function operationLogs(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $module   = (string) $this->request->get('module', '');
        $adminId  = (int) $this->request->get('admin_id', 0);

        $query = Db::name('operation_logs');
        if ($module !== '') {
            $query->where('module', $module);
        }
        if ($adminId > 0) {
            $query->where('admin_id', $adminId);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $names = Db::name('admin_users')->column('nickname', 'id');
        foreach ($list as &$log) {
            $log['admin_name'] = $names[$log['admin_id'] ?? 0] ?? '';
        }
        unset($log);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 导出操作日志合规报告 CSV
     * GET /api/v1/admin/operation-logs/export?module=&admin_id=&start_date=&end_date=
     */
    public function exportCompliance(): Response
    {
        $filters = [
            'module'     => (string) $this->request->get('module', ''),
            'admin_id'   => (int) $this->request->get('admin_id', 0),
            'start_date' => (string) $this->request->get('start_date', ''),
            'end_date'   => (string) $this->request->get('end_date', ''),
        ];

        $csv = ExportService::exportComplianceCsv($filters);

        $filename = 'compliance_' . date('Ymd_His') . '.csv';
        return Response::create($csv)
            ->header([
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
    }

    // ---------- helpers ----------

    protected function roleIdsFromPost(array $roles): array
    {
        $ids = array_map('intval', $roles);
        $ids = array_values(array_unique(array_filter($ids)));
        return $ids ? Db::name('roles')->whereIn('id', $ids)->column('id') : [];
    }

    protected function bindRoles(int $adminId, array $roleIds): void
    {
        Db::name('admin_role')->where('admin_id', $adminId)->delete();
        foreach ($roleIds as $rid) {
            Db::name('admin_role')->insert(['admin_id' => $adminId, 'role_id' => (int) $rid]);
        }
    }

    protected function attachChildren(array $node, array &$map): array
    {
        $node['children'] = [];
        foreach ($map as $p) {
            if ((int) $p['parent_id'] === (int) $node['id']) {
                $node['children'][] = $this->attachChildren($p, $map);
            }
        }
        return $node;
    }
}