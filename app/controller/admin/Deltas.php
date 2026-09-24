<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\DeltaService;
use app\service\UploadService;
use think\facade\Config;
use think\facade\Db;
use think\response\Json;

/**
 * 增量更新差分包管理
 */
class Deltas extends BaseController
{
    /**
     * 差分包列表
     */
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $versionId= (int) $this->request->get('version_id', 0);

        $query = Db::name('delta_packages');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($versionId > 0) {
            $query->where('version_id', $versionId);
        }
        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        $verNames = Db::name('app_versions')->column('version_name', 'id');
        foreach ($list as &$d) {
            $d['app_name']           = $appNames[$d['app_id']] ?? '';
            $d['version_name']       = $verNames[$d['version_id']] ?? '';
            $d['base_version_name']  = $verNames[$d['base_version_id']] ?? '';
        }
        unset($d);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 上传差分包
     * POST /api/v1/admin/deltas
     * multipart: patch_file + app_id + version_id + base_version_id
     */
    public function create(): Json
    {
        $appId    = (int) $this->request->post('app_id', 0);
        $versionId= (int) $this->request->post('version_id', 0);
        $baseId   = (int) $this->request->post('base_version_id', 0);
        if ($appId <= 0 || $versionId <= 0 || $baseId <= 0 || $versionId === $baseId) {
            return fail(10003, 'app_id/version_id/base_version_id 参数不合法');
        }
        $target = Db::name('app_versions')->where('id', $versionId)->find();
        $base   = Db::name('app_versions')->where('id', $baseId)->find();
        if (!$target || !$base || (int) $target['app_id'] !== $appId || (int) $base['app_id'] !== $appId) {
            return fail(10004, '版本不存在或不属于该应用');
        }
        $file = $this->request->file('patch_file');
        if (!$file || !$file->isValid()) {
            return fail(10003, '未接收到差分包文件');
        }
        $info = UploadService::save($file, 'deltas', 314572800, ['bsdiff', 'patch', 'bin', 'dat', 'zip']);

        // 自动生成模式下可能已存在，则更新
        $now = datetime_now();
        $exists = Db::name('delta_packages')
            ->where('version_id', $versionId)
            ->where('base_version_id', $baseId)
            ->find();
        $data = [
            'app_id'          => $appId,
            'file_name'       => $info['name'],
            'file_path'       => $info['path'],
            'file_size'       => $info['size'],
            'md5'             => $info['md5'],
            'sha256'          => $info['sha256'],
            'patch_type'      => (string) $this->request->post('patch_type', 'bsdiff'),
            'status'          => 1,
            'created_by'      => $this->adminId(),
        ];
        if ($exists) {
            Db::name('delta_packages')->where('id', (int) $exists['id'])->update($data + ['updated_at' => $now]);
            $id = (int) $exists['id'];
            $msg = '差分包已更新';
        } else {
            $data['created_at'] = $now;
            $id = (int) Db::name('delta_packages')->insertGetId($data);
            $msg = '差分包上传成功';
        }
        $this->opLog('delta', 'create', $id, '上传增量包 ' . $target['version_name'] . ' <- ' . $base['version_name'] . '（' . number_format($info['size'] / 1024 / 1024, 2) . 'MB）');
        return success(['id' => $id, 'file' => $info], $msg);
    }

    /**
     * 删除差分包
     */
    public function delete(): Json
    {
        $id = (int) $this->request->param('id');
        $d  = Db::name('delta_packages')->where('id', $id)->find();
        if (!$d) {
            throw BizException::notFound('差分包不存在');
        }
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        $path = (string) $d['file_path'];
        if ($path !== '' && strpos($path, 'deltas/') === 0) {
            $abs = $root . '/' . $path;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        Db::name('delta_packages')->where('id', $id)->delete();
        $this->opLog('delta', 'delete', $id, '删除增量包 #' . $id);
        return success(null, '删除成功');
    }

    /**
     * 服务端自动生成差分包（bsdiff）
     * POST /api/v1/admin/deltas/generate
     * body: version_id, base_version_id
     */
    public function generate(): Json
    {
        $versionId = (int) $this->request->post('version_id', 0);
        $baseId    = (int) $this->request->post('base_version_id', 0);
        $patchRel  = DeltaService::generate($baseId, $versionId);
        if (!$patchRel) {
            return fail(10006, '生成失败：服务器可能需要安装 bsdiff 工具，或源安装包不存在');
        }
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        $abs  = $root . '/' . $patchRel;
        $target = Db::name('app_versions')->where('id', $versionId)->find();
        $base   = Db::name('app_versions')->where('id', $baseId)->find();

        $now = datetime_now();
        $exists = Db::name('delta_packages')
            ->where('version_id', $versionId)
            ->where('base_version_id', $baseId)
            ->find();
        $data = [
            'app_id'          => (int) $target['app_id'],
            'file_name'       => basename($patchRel),
            'file_path'       => $patchRel,
            'file_size'       => (int) filesize($abs),
            'md5'             => md5_file($abs),
            'sha256'          => hash_file('sha256', $abs),
            'patch_type'      => 'bsdiff',
            'status'          => 1,
            'created_by'      => $this->adminId(),
        ];
        if ($exists) {
            Db::name('delta_packages')->where('id', (int) $exists['id'])->update($data + ['updated_at' => $now]);
            $id = (int) $exists['id'];
        } else {
            $data['created_at'] = $now;
            $id = (int) Db::name('delta_packages')->insertGetId($data);
        }
        $this->opLog('delta', 'generate', $id, '自动生成增量包 ' . $target['version_name'] . ' <- ' . $base['version_name']);
        return success(['id' => $id, 'file' => $data], '差分包生成成功');
    }
}