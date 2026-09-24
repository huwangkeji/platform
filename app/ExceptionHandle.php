<?php
namespace app;

use app\exception\BizException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类：统一 JSON 响应 + 错误信息脱敏
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ValidateException::class,
        BizException::class,
    ];

    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    public function render($request, Throwable $e): Response
    {
        // 业务异常
        if ($e instanceof BizException) {
            return json([
                'code'    => $e->getCode(),
                'message' => $e->getMessage(),
                'data'    => null,
            ], $e->getHttpCode());
        }

        // 参数校验异常
        if ($e instanceof ValidateException) {
            return json([
                'code'    => 422,
                'message' => $e->getError(),
                'data'    => null,
            ], 422);
        }

        // HTTP 异常（404 等）
        if ($e instanceof HttpException) {
            $message = $e->getMessage() ?: '请求不存在';
            if (is_string($message) && ($e->getStatusCode() === 404)) {
                $message = '请求的资源不存在';
            }
            return json([
                'code'    => $e->getStatusCode(),
                'message' => $message,
                'data'    => null,
            ], $e->getStatusCode());
        }

        // 其他异常：记录日志，对外脱敏
        $this->report($e);
        if ($this->app->isDebug()) {
            return json([
                'code'    => 500,
                'message' => $e->getMessage(),
                'data'    => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", (string) $e->getTraceAsString()),
                ],
            ], 500);
        }
        return json([
            'code'    => 500,
            'message' => '系统繁忙，请稍后再试',
            'data'    => null,
        ], 500);
    }
}