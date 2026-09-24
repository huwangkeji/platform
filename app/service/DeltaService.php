<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\BizException;
use think\facade\Config;
use think\facade\Db;

/**
 * 增量更新差分包服务：bsdiff 生成（需系统 bsdiff/bspatch 工具）+ 上传管理 + 查询
 */
class DeltaService
{
    /**
     * 自动生成差分包（old.apk -> new.apk），依赖系统 bsdiff 命令
     * @return string|null 生成的差分包相对路径；工具不可用返回 null
     */
    public static function generate(int $baseVersionId, int $targetVersionId): ?string
    {
        $base   = Db::name('app_versions')->where('id', $baseVersionId)->find();
        $target = Db::name('app_versions')->where('id', $targetVersionId)->find();
        if (!$base || !$target || (int) $base['app_id'] !== (int) $target['app_id']) {
            throw BizException::param('版本不存在或不属于同一应用');
        }
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        $baseAbs   = $root . '/' . ltrim((string) $base['file_path'], '/');
        $targetAbs = $root . '/' . ltrim((string) $target['file_path'], '/');
        if (!is_file($baseAbs) || !is_file($targetAbs)) {
            throw BizException::param('源版本安装包不存在');
        }

        // 探测 bsdiff 命令
        $out = [];
        @exec('command -v bsdiff 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return null;
        }

        $relDir = 'deltas/' . (int) $target['app_id'] . '/' . (int) $target['version_code'];
        $absDir = $root . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            throw BizException::failed('差分包目录创建失败');
        }
        $patch = $relDir . '/from-' . (int) $base['version_code'] . '-to-' . (int) $target['version_code'] . '.bsdiff';
        $patchAbs = $root . '/' . $patch;
        @exec('bsdiff ' . escapeshellarg($baseAbs) . ' ' . escapeshellarg($targetAbs) . ' ' . escapeshellarg($patchAbs) . ' 2>&1', $out, $code);
        if ($code !== 0 || !is_file($patchAbs)) {
            return null;
        }
        return $patch;
    }

    /**
     * 查询可用的增量包（目标版本 + 基础版本）
     */
    public static function find(int $targetVersionId, int $baseVersionId): ?array
    {
        return Db::name('delta_packages')
            ->where('version_id', $targetVersionId)
            ->where('base_version_id', $baseVersionId)
            ->where('status', 1)
            ->find() ?: null;
    }
}