<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Config;
use think\facade\Db;

/**
 * 崩溃堆栈符号化服务：基于上传的 mapping.txt / dSYM 符号文件，将混淆堆栈还原为可读源码位置
 */
class SymbolService
{
    /**
     * 解析堆栈中的混淆符号，根据 mapping 文件还原
     * @param string $mappingPath mapping.txt 绝对路径
     * @param string $stackTrace 原始堆栈
     */
    public static function symbolize(string $mappingPath, string $stackTrace): string
    {
        if (!is_file($mappingPath) || trim($stackTrace) === '') {
            return $stackTrace;
        }
        $content = (string) file_get_contents($mappingPath);
        $content = preg_replace('/^#.*$/m', '', $content); // 去掉注释行

        // ProGuard/R8 mapping 格式（每行用 -> 分隔）：
        //   原类名 -> 混淆名:
        //   int 原方法(int) -> 混淆方法
        $map = self::buildMapping($content);
        if (!$map) {
            return $stackTrace;
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $stackTrace));
        foreach ($lines as &$line) {
            $trimmed = trim($line);
            // 匹配类名（如 com.example.MainActivity 或 a.b.c）
            if (preg_match('/(?:at\s+)([a-zA-Z_$][\w$]*(\.[a-zA-Z_$][\w$]*)+)/', $trimmed, $m)) {
                $obfClass = $m[1];
                if (isset($map['classes'][$obfClass])) {
                    $line = str_replace($obfClass, $map['classes'][$obfClass], $line);
                }
            }
            // 匹配形如 a.b.c.f(SourceFile:12) 或 a.b.c.f:12
            if (preg_match('/([a-zA-Z_$][\w$]*(\.[a-zA-Z_$][\w$]*)+)\.([a-zA-Z_$][\w$]*)\s*\((.+?)\)/', $trimmed, $m)) {
                $obfClass = $m[1];
                $obfMethod = $m[3];
                if (isset($map['classes'][$obfClass], $map['methods'][$obfClass][$obfMethod])) {
                    $orig = $map['methods'][$obfClass][$obfMethod];
                    $line = str_replace($obfClass . '.' . $obfMethod, $orig, $line);
                }
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * 构建符号映射表
     * @return array{classes:array,methods:array}
     */
    protected static function buildMapping(string $content): array
    {
        $classes = [];
        $methods = [];
        $currentClass = null;

        foreach (preg_split('/\R/', $content) as $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(.+?)\s*->\s*([a-zA-Z_$][\w$]*):\s*$/', $line, $m)) {
                $currentClass = trim($m[2]);
                $classes[$currentClass] = trim($m[1]);
                $methods[$currentClass] = $methods[$currentClass] ?? [];
                continue;
            }
            if ($currentClass !== null && preg_match('/^.+?\s+(.+?)\s*->\s*([a-zA-Z_$][\w$]*)/', $line, $m)) {
                $methods[$currentClass][trim($m[2])] = trim($m[1]);
            }
        }

        return ['classes' => $classes, 'methods' => $methods];
    }

    /**
     * 选择匹配版本的符号文件（按 version_code 近似匹配或最近上传）
     * @return array|null symbol_files 记录
     */
    public static function findSymbolFile(int $appId, string $platform, int $versionCode): ?array
    {
        $query = Db::name('symbol_files')
            ->where('app_id', $appId)
            ->where('platform', $platform)
            ->where('status', 1);

        // 优先精确版本匹配：标签格式如 3.2.0 (32) / mapping-32
        if ($versionCode > 0) {
            $rows = $query->select()->toArray();
            $exact = null;
            $fallback = null;
            foreach ($rows as $r) {
                $label = (string) ($r['package_label'] ?? '');
                if (preg_match('/(\d+)/', $label, $m) && (int) $m[1] === $versionCode) {
                    $exact = $r;
                    break;
                }
                if (strpos($label, (string) $versionCode) !== false) {
                    $exact = $exact ?: $r;
                }
                $fallback = $fallback ?: $r;
            }
            return $exact ?: $fallback;
        }

        return $query->order('id', 'desc')->find() ?: null;
    }

    /**
     * 异步符号化：崩溃入库后请求触发
     */
    public static function symbolizeCrash(int $crashId): bool
    {
        try {
            $crash = Db::name('crash_reports')->where('id', $crashId)->find();
            if (!$crash || (int) $crash['symbolized'] === 1) {
                return false;
            }
            $symbol = self::findSymbolFile((int) $crash['app_id'], 'android', (int) $crash['version_code']);
            if (!$symbol) {
                return false;
            }
            $root  = rtrim((string) Config::get('app.storage_path'), '/');
            $abs   = $root . '/' . ltrim((string) $symbol['file_path'], '/');
            if (!is_file($abs)) {
                return false;
            }
            $result = self::symbolize($abs, (string) $crash['stack_trace']);
            Db::name('crash_reports')->where('id', $crashId)->update([
                'symbolized'        => 1,
                'symbolized_stack'  => $result,
                'updated_at'        => datetime_now(),
            ]);
            Db::name('symbol_files')->where('id', (int) $symbol['id'])->inc('parse_count')->update();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}