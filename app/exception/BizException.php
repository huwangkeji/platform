<?php
declare (strict_types = 1);

namespace app\exception;

use RuntimeException;

/**
 * 业务异常：message 面向客户端展示
 * code 即业务码：401 未登录 / 403 无权限 / 404 不存在 / 422 参数错误 / 423 锁定 / 1 通用失败
 */
class BizException extends RuntimeException
{
    protected int $httpCode = 200;

    public function __construct(string $message = 'error', int $code = 1, int $httpCode = 200)
    {
        parent::__construct($message, $code);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public static function unauth(string $message = '请先登录'): static
    {
        return new static($message, 401, 401);
    }

    public static function forbidden(string $message = '无权限操作'): static
    {
        return new static($message, 403, 403);
    }

    public static function notFound(string $message = '资源不存在'): static
    {
        return new static($message, 404, 404);
    }

    public static function param(string $message = '参数错误'): static
    {
        return new static($message, 422, 422);
    }

    public static function failed(string $message = '操作失败'): static
    {
        return new static($message, 1, 200);
    }
}