<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use think\facade\Db;
use think\response\Json;

/**
 * 应用管理
 */
class Apps extends BaseController
{
    /**
     * 应用列表
     */
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = (string) $this->request->get('status', '');

        $query = Db::name('apps');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%')->whereOr('code', 'like', '%' . $keyword . '%');
            });
        }
        if ($status !== '' && in_array($status, ['0', '1'], true)) {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list  = $query->order('sort_order', 'desc')->order('id', 'desc')
            ->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        foreach ($list as &$app) {
            $live = Db::name('app_versions')
                ->where('app_id', (int) $app['id'])
                ->whereIn('status', ['published', 'gray'])
                ->order('version_code', 'desc')
                ->find();
            $app['current_version'] = $live ? ['id' => (int) $live['id'], 'name' => $live['version_name'], 'status' => $live['status']] : null;
            $app['version_count']   = (int) Db::name('app_versions')->where('app_id', (int) $app['id'])->count();
        }
        unset($app);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 应用详情
     */
    public function read(): Json
    {
        $app = $this->appRow();
        $app['version_count'] = (int) Db::name('app_versions')->where('app_id', (int) $app['id'])->count();
        $app['channel_count'] = (int) Db::name('channels')->where('app_id', (int) $app['id'])->count();
        $app['download_total']= (int) Db::name('download_logs')->where('app_id', (int) $app['id'])->count();
        return success($app);
    }

    /**
     * 创建应用
     */
    public function create(): Json
    {
                $name = trim((string) $this->request->post('name', ''));
        $code = trim((string) $this->request->post('code', ''));
        if ($name === '' || $code === '') {
            return fail(10003, '应用名称和 App Code 不能为空');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]{1,63}$/', $code)) {
            return fail(10003, 'App Code 仅支持字母/数字/下划线/中划线，且以字母开头');
        }
        if (Db::name('apps')->where('code', $code)->find()) {
            return fail(10004, 'App Code 已存在');
        }

        $now = datetime_now();
        $id  = Db::name('apps')->insertGetId([
            'name'         => $name,
            'code'         => $code,
            'package_name' => (string) $this->request->post('package_name', ''),
            'bundle_id'    => (string) $this->request->post('bundle_id', ''),
            'icon'         => (string) $this->request->post('icon', ''),
            'description'  => (string) $this->request->post('description', ''),
            'website'      => (string) $this->request->post('website', ''),
            'privacy_url'  => (string) $this->request->post('privacy_url', ''),
            'agreement_url'=> (string) $this->request->post('agreement_url', ''),
            'contact'      => (string) $this->request->post('contact', ''),
            'sort_order'   => (int) $this->request->post('sort_order', 0),
            'status'       => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->opLog('app', 'create', $id, '创建应用：' . $name . ' (' . $code . ')');
        return success(['id' => $id], '创建成功');
    }

    /**
     * 更新应用
     */
    public function update(): Json
    {
        $app = $this->appRow();
        $data = [
            'name'          => trim((string) $this->request->put('name', $app['name'])),
            'package_name'  => (string) $this->request->put('package_name', $app['package_name'] ?? ''),
            'bundle_id'     => (string) $this->request->put('bundle_id', $app['bundle_id'] ?? ''),
            'icon'          => (string) $this->request->put('icon', $app['icon'] ?? ''),
            'description'   => (string) $this->request->put('description', $app['description'] ?? ''),
            'website'       => (string) $this->request->put('website', $app['website'] ?? ''),
            'privacy_url'   => (string) $this->request->put('privacy_url', $app['privacy_url'] ?? ''),
            'agreement_url' => (string) $this->request->put('agreement_url', $app['agreement_url'] ?? ''),
            'contact'       => (string) $this->request->put('contact', $app['contact'] ?? ''),
            'sort_order'    => (int) $this->request->put('sort_order', $app['sort_order'] ?? 0),
            'updated_at'    => datetime_now(),
        ];
        $code = trim((string) $this->request->put('code', ''));
        if ($code !== '') {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]{1,63}$/', $code)) {
                return fail(10003, 'App Code 仅支持字母/数字/下划线/中划线，且以字母开头');
            }
            $dup = Db::name('apps')->where('code', $code)->where('id', '<>', (int) $app['id'])->find();
            if ($dup) {
                return fail(10004, 'App Code 已存在');
            }
            $data['code'] = $code;
        }

        Db::name('apps')->where('id', (int) $app['id'])->update($data);
        $this->opLog('app', 'update', (int) $app['id'], '更新应用：' . $data['name']);
        return success(null, '更新成功');
    }

    /**
     * 删除应用（级联删除版本/发布任务/规则/渠道）
     */
    public function delete(): Json
    {
        $app = $this->appRow();
        Db::transaction(function () use ($app) {
            $versionIds = Db::name('app_versions')->where('app_id', (int) $app['id'])->column('id');
            if ($versionIds) {
                $taskIds = Db::name('release_tasks')->whereIn('version_id', $versionIds)->column('id');
                if ($taskIds) {
                    Db::name('release_rules')->whereIn('release_id', $taskIds)->delete();
                }
                Db::name('release_tasks')->whereIn('version_id', $versionIds)->delete();
            }
            Db::name('app_versions')->where('app_id', (int) $app['id'])->delete();
            Db::name('channels')->where('app_id', (int) $app['id'])->delete();
            Db::name('notices')->where('app_id', (int) $app['id'])->delete();
            Db::name('apps')->where('id', (int) $app['id'])->delete();
        });

        $this->opLog('app', 'delete', (int) $app['id'], '删除应用：' . $app['name']);
        return success(null, '删除成功');
    }

    /**
     * 启用
     */
    public function enable(): Json
    {
        $app = $this->appRow();
        Db::name('apps')->where('id', (int) $app['id'])->update(['status' => 1, 'updated_at' => datetime_now()]);
        $this->opLog('app', 'enable', (int) $app['id'], '启用应用：' . $app['name']);
        return success(null, '已启用');
    }

    /**
     * 停用（停用后 APP 端接口将拒绝该应用）
     */
    public function disable(): Json
    {
        $app = $this->appRow();
        Db::name('apps')->where('id', (int) $app['id'])->update(['status' => 0, 'updated_at' => datetime_now()]);
        $this->opLog('app', 'disable', (int) $app['id'], '停用应用：' . $app['name']);
        return success(null, '已停用');
    }

    /**
     * 读取当前应用行
     */
    protected function appRow(): array
    {
        $id  = (int) $this->request->param('id');
        $app = Db::name('apps')->where('id', $id)->find();
        if (!$app) {
            throw BizException::notFound('应用不存在');
        }
        return $app;
    }
}