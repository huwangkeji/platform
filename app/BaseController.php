<?php
declare (strict_types = 1);

namespace app;

use app\common\AdminContext;
use app\exception\BizException;
use app\service\OperationLogService;
use think\App;
use think\facade\Db;
use think\response\Json;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}

    /**
     * 当前登录管理员 ID
     */
    protected function adminId(): int
    {
        return AdminContext::id();
    }

    /**
     * 统一成功响应
     */
    protected function success($data = null, string $message = 'success'): Json
    {
        return success($data, $message);
    }

    /**
     * 统一失败响应
     */
    protected function fail(int $code = 1, string $message = 'error', $data = null): Json
    {
        return fail($code, $message, $data);
    }

    /**
     * 写操作日志
     * @param int|string|null $targetId 允许字符串（MySQL 下 insertGetId 返回 string）
     */
    protected function opLog(string $module, string $action, int|string|null $targetId, string $description): void
    {
        OperationLogService::write($this->adminId(), $module, $action, $targetId === null || $targetId === '' ? null : (int) $targetId, $description);
    }

    /**
     * 按 App Code 查应用（启用状态）
     */
    protected function findApp(string $code): ?array
    {
        $app = Db::name('apps')->where('code', $code)->where('status', 1)->find();
        return $app ?: null;
    }

    /**
     * 按 App Code 查应用，不存在则抛业务异常
     */
    protected function ensureApp(string $code): array
    {
        $app = $this->findApp($code);
        if (!$app) {
            throw BizException::notFound('应用不存在或已停用');
        }
        return $app;
    }

    /**
     * 验证数据（保留骨架能力）
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new \think\Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }
}