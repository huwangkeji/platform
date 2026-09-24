<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\SymbolService;
use app\service\UploadService;
use think\facade\Config;
use think\facade\Db;
use think\response\Json;

/**
 * 符号文件管理：上传 mapping.txt / dSYM，查看符号化效果
 */
class Symbols extends BaseController
{
    /**
     * 符号文件列表
     */
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->get('app_id', 0);
        $platform = (string) $this->request->get('platform', '');

        $query = Db::name('symbol_files');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($platform !== '') {
            $query->where('platform', $platform);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$s) {
            $s['app_name'] = $appNames[$s['app_id']] ?? '';
        }
        unset($s);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 上传符号文件
     * POST /api/v1/admin/symbols
     * multipart: symbol_file + app_id + platform + package_label + symbol_type
     */
    public function create(): Json
    {
        $appId   = (int) $this->request->post('app_id', 0);
        $platform = strtolower((string) $this->request->post('platform', 'android'));
        if ($appId <= 0) {
            return fail(10003, 'app_id 不能为空');
        }
        if (!in_array($platform, ['android', 'ios'], true)) {
            return fail(10003, '平台仅支持 android/ios');
        }
        $app = Db::name('apps')->where('id', $appId)->find();
        if (!$app) {
            return fail(10004, '应用不存在');
        }

        $file = $this->request->file('symbol_file');
        if (!$file || !$file->isValid()) {
            return fail(10003, '未接收到符号文件');
        }

        $symbolType = (string) $this->request->post('symbol_type', 'mapping');
        if ($symbolType !== 'mapping' && $symbolType !== 'dsym') {
            $symbolType = 'mapping';
        }
        // mapping.txt / zip(dSYM打包) / txt
        $info = UploadService::save($file, 'symbols', 104857600, ['txt', 'zip', 'map', 'dsym']);

        $now = datetime_now();
        $id  = Db::name('symbol_files')->insertGetId([
            'app_id'       => $appId,
            'platform'     => $platform,
            'package_label'=> (string) $this->request->post('package_label', ''),
            'file_name'    => $info['name'],
            'file_path'    => $info['path'],
            'file_size'    => $info['size'],
            'md5'          => $info['md5'],
            'symbol_type'  => $symbolType,
            'parse_count'  => 0,
            'status'       => 1,
            'created_by'   => $this->adminId(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->opLog('symbol', 'create', $id, '上传符号文件：' . $info['name'] . '（' . $platform . '/' . $symbolType . '）');
        return success(['id' => $id, 'file' => $info], '上传成功');
    }

    /**
     * 删除符号文件
     */
    public function delete(): Json
    {
        $id = (int) $this->request->param('id');
        $s  = Db::name('symbol_files')->where('id', $id)->find();
        if (!$s) {
            throw BizException::notFound('符号文件不存在');
        }
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        $path = (string) $s['file_path'];
        if ($path !== '' && strpos($path, 'symbols/') === 0) {
            $abs = $root . '/' . $path;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        Db::name('symbol_files')->where('id', $id)->delete();
        $this->opLog('symbol', 'delete', $id, '删除符号文件 ' . $s['file_name']);
        return success(null, '删除成功');
    }

    /**
     * 符号化调试：给定指定崩溃记录，手动触发/查看符号化结果
     * POST /api/v1/admin/symbols/symbolize
     */
    public function symbolize(): Json
    {
        $crashId = (int) $this->request->post('crash_id', 0);
        if ($crashId <= 0) {
            return fail(10003, 'crash_id 不能为空');
        }
        $crash = Db::name('crash_reports')->where('id', $crashId)->find();
        if (!$crash) {
            throw BizException::notFound('崩溃记录不存在');
        }
        $ok = SymbolService::symbolizeCrash($crashId);
        $now = Db::name('crash_reports')->where('id', $crashId)->find();
        return success([
            'symbolized'        => (int) ($now['symbolized'] ?? 0),
            'symbolized_stack'  => (string) ($now['symbolized_stack'] ?? ''),
            'found_symbol'      => $ok,
        ], $ok ? '符号化成功' : '未找到匹配符号文件或无需符号化');
    }
}