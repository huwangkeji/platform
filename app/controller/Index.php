<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use app\exception\BizException;
use app\service\CdnService;
use think\facade\Config;
use think\facade\Db;
use think\Response;

/**
 * 前台页面与下载重定向
 */
class Index extends BaseController
{
    /**
     * 官方首页
     */
    public function index(): Response
    {
        return $this->page('index');
    }

    /**
     * 应用下载页 /app/:code
     */
    public function download(string $code): Response
    {
        $app = $this->findApp($code);
        if (!$app) {
            throw BizException::notFound('应用不存在或已停用');
        }
        return $this->page('download', ['app_code' => $code]);
    }

    /**
     * 历史版本页 /app/:code/history
     */
    public function history(string $code): Response
    {
        $app = $this->findApp($code);
        if (!$app) {
            throw BizException::notFound('应用不存在或已停用');
        }
        return $this->page('history', ['app_code' => $code]);
    }

    /**
     * 渠道/官方二维码下载：/download/{app_code}?channel=xxx
     * 解析为最新可见版本并 302 到 /download/{version_id}（保留渠道参数用于统计）
     */
    public function downloadByCode(string $code): Response
    {
        $app = $this->findApp($code);
        if (!$app) {
            throw BizException::notFound('应用不存在或已停用');
        }
        $version = Db::name('app_versions')
            ->where('app_id', (int) $app['id'])
            ->whereIn('status', ['published', 'gray'])
            ->order('version_code', 'desc')
            ->find();
        if (!$version) {
            throw BizException::notFound('该应用暂无可下载版本');
        }
        $query = $this->request->get();
        unset($query['channel']);
        $qs = http_build_query($query);
        $channel = (string) $this->request->get('channel', '');
        $target  = '/download/' . (int) $version['id'] . ($channel !== '' ? '?channel=' . rawurlencode($channel) : '') . ($qs !== '' ? ($channel !== '' ? '&' : '?') . $qs : '');
        return redirect($target)->code(302);
    }

    /**
     * 下载 + 统计
     * GET /download/:id
     */
    public function downloadRedirect(int $id): Response
    {
        $version = Db::name('app_versions')->where('id', $id)->find();
        if (!$version) {
            throw BizException::notFound('版本不存在');
        }

        // 访问控制校验
        $accessType = (string) ($version['access_type'] ?? 'public');
        if ($accessType !== 'public') {
            $provided = (string) $this->request->get('password', '');
            if ($accessType === 'password') {
                $expected = (string) ($version['access_password'] ?? '');
                if ($expected === '' || $provided !== $expected) {
                    throw BizException::failed('访问密码错误');
                }
            } elseif ($accessType === 'protected') {
                // protected 模式需要登录态（未来扩展）
                throw BizException::unauth('该版本需要登录后才能下载');
            }
        }

        $app = Db::name('apps')->where('id', (int) $version['app_id'])->where('status', 1)->find();
        if (!$app) {
            throw BizException::notFound('应用不存在或已停用');
        }
        $path = (string) ($version['file_path'] ?? '');
        if ($path === '') {
            throw BizException::failed('该版本尚未上传安装包');
        }
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        $abs  = $root . '/' . $path;
        if (!is_file($abs)) {
            throw BizException::failed('安装包文件不存在');
        }

        // 记录下载日志（不影响下载主流程）
        try {
            $deviceIdStr = (string) $this->request->get('device_id', '');
            $device      = null;
            if ($deviceIdStr !== '') {
                $device = Db::name('devices')->where('device_id', $deviceIdStr)->find();
            }
            Db::name('download_logs')->insert([
                'app_id'       => (int) $version['app_id'],
                'version_id'   => (int) $version['id'],
                'user_id'      => $device && (int) ($device['user_id'] ?? 0) > 0 ? (int) $device['user_id'] : null,
                'device_id'    => $device ? (int) $device['id'] : null,
                'channel_code' => (string) $this->request->get('channel', ''),
                'platform'     => (string) $this->request->get('platform', $version['platform']),
                'ip'           => (string) $this->request->ip(),
                'user_agent'   => substr((string) $this->request->header('user-agent', ''), 0, 500),
                'created_at'   => datetime_now(),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响下载
        }

        // 按配置分发下载模式
        $mode = strtolower((string) Config::get('app.download_mode', 'redirect'));

        // CDN 节点分发（Phase 3-2）：请求携带 ?cdn=1 或配置优先用 CDN 时，重定向到节点地址
        $useCdn = (string) $this->request->get('cdn', '') === '1'
            || strtolower((string) Config::get('app.download_mode', 'redirect')) === 'cdn';
        if ($mode === 'redirect' || $useCdn) {
            if ($useCdn) {
                $region = CdnService::regionOfIp((string) $this->request->ip());
                $node   = CdnService::pickNode($region);
                if ($node) {
                    $fileUrl = CdnService::signedUrl($node, $path);
                    return redirect($fileUrl)->code(302);
                }
            }
            $storageUrl = rtrim((string) Config::get('app.storage_url'), '/');
            $fileUrl    = $storageUrl . '/' . $path;
            return redirect($fileUrl)->code(302);
        }
        if ($mode === 'xaccel') {
            // 交由 Nginx X-Accel-Redirect 分发（storage 目录在 web 根之外，由 nginx alias 暴露）
            return Response::create('', 'html', 200)->header([
                'Content-Type'        => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . rawurlencode((string) ($version['file_name'] ?: basename($path))) . '"',
                'X-Accel-Redirect'    => '/storage/' . $path,
            ]);
        }

        // stream：PHP 直出（沙箱/无 Nginx 场景）
        $fileName = (string) ($version['file_name'] ?: basename($path));
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) filesize($abs));
        header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
        header('Cache-Control: no-cache');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        readfile($abs);
        exit;
    }

    /**
     * 增量包下载
     * GET /download/delta/:id
     */
    public function deltaRedirect(int $id): Response
    {
        $delta = Db::name('delta_packages')->where('id', $id)->where('status', 1)->find();
        if (!$delta) {
            throw BizException::notFound('增量包不存在');
        }
        $path = (string) ($delta['file_path'] ?? '');
        if ($path === '') {
            throw BizException::failed('增量包文件不存在');
        }
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        $abs  = $root . '/' . $path;
        if (!is_file($abs)) {
            throw BizException::failed('增量包文件不存在');
        }

        // 记录下载日志（目标版本）
        try {
            $target = Db::name('app_versions')->where('id', (int) $delta['version_id'])->find();
            $deviceIdStr = (string) $this->request->get('device_id', '');
            $device      = null;
            if ($deviceIdStr !== '') {
                $device = Db::name('devices')->where('device_id', $deviceIdStr)->find();
            }
            if ($target) {
                Db::name('download_logs')->insert([
                    'app_id'       => (int) $target['app_id'],
                    'version_id'   => (int) $delta['version_id'],
                    'user_id'      => $device && (int) ($device['user_id'] ?? 0) > 0 ? (int) $device['user_id'] : null,
                    'device_id'    => $device ? (int) $device['id'] : null,
                    'channel_code' => (string) $this->request->get('channel', ''),
                    'platform'     => (string) $this->request->get('platform', $target['platform']),
                    'ip'           => (string) $this->request->ip(),
                    'user_agent'   => substr((string) $this->request->header('user-agent', ''), 0, 500),
                    'created_at'   => datetime_now(),
                ]);
            }
        } catch (\Throwable $e) {
            // 日志失败不影响下载
        }

        // CDN 优先
        $useCdn = (string) $this->request->get('cdn', '') === '1'
            || strtolower((string) Config::get('app.download_mode', 'redirect')) === 'cdn';
        if ($useCdn) {
            $node = CdnService::pickNode(CdnService::regionOfIp((string) $this->request->ip()));
            if ($node) {
                return redirect(CdnService::signedUrl($node, $path))->code(302);
            }
        }
        $storageUrl = rtrim((string) Config::get('app.storage_url'), '/');
        return redirect($storageUrl . '/' . $path)->code(302);
    }

    /**
     * 关于程序
     */
    public function about(): Response
    {
        return $this->page('about');
    }

    /**面 /developer
     */
    public function developer(): Response
    {
        return $this->page('developer');
    }

    /**
     * 后台入口 /admin/:page（LayUI 单页应用）
     * page 仅允许字母数字下划线中划线，用于后端注入 {{page}} 占位符，
     * 其余值统一回落 login（配合 admin.js 的 __ADMIN_PAGE__ 直达路由）
     */
    public function admin(string $page = 'login'): Response
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $page)) {
            $page = 'login';
        }
        return $this->page('admin', ['page' => $page]);
    }

    /**
     * 读取 public/page/*.html 模板并做变量替换
     */
    protected function page(string $name, array $vars = []): Response
    {
        $file = $this->app->getRootPath() . 'public/page/' . $name . '.html';
        if (!is_file($file)) {
            throw BizException::notFound('页面不存在');
        }
        $html = (string) file_get_contents($file);
        foreach ($vars as $k => $v) {
            $html = str_replace('{{' . $k . '}}', htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'), $html);
        }
        return Response::create($html, 'html');
    }
}