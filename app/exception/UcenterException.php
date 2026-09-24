<?php
declare (strict_types = 1);

namespace app\exception;

/**
 * UCenter 通信异常：网络不可达 / 签名校验失败 / 协议错误 / 未配置
 * 与本地账号体系无关，业务层应根据降级策略决定本地流程是否继续
 */
class UcenterException extends BizException
{
    /**
     * 通信类异常不面向最终用户透传细节
     */
    public static function connection(string $message = 'UCenter 服务暂不可用，请稍后重试'): static
    {
        return new static($message, 1, 200);
    }

    /**
     * 签名或协议校验失败
     */
    public static function signature(string $message = 'UCenter 通信验证失败'): static
    {
        return new static($message, 1, 200);
    }

    /**
     * 配置缺失
     */
    public static function unconfigured(string $message = 'UCenter 未配置或未启用'): static
    {
        return new static($message, 1, 200);
    }
}