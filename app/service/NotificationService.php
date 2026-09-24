<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * 发布通知服务：支持邮件和 WebHook 通知
 */
class NotificationService
{
    /**
     * 触发发布相关事件通知
     * @param string $eventType 事件类型: release/publish/pause/rollback
     * @param array $context 上下文数据（应用名、版本名、发布类型等）
     */
    public static function trigger(string $eventType, array $context): void
    {
        // 1. 查找匹配的模板
        $templates = Db::name('notification_templates')
            ->where('event_type', $eventType)
            ->where('status', 1)
            ->select()
            ->toArray();

        foreach ($templates as $template) {
            if ($template['channel'] === 'email') {
                self::sendEmail($template, $context);
            } elseif ($template['channel'] === 'webhook') {
                // WebHook 由 WebhookService 统一处理
                WebhookService::trigger($eventType, $context);
            }
        }

        // 2. 触发所有匹配的 WebHook（无论是否有模板）
        WebhookService::trigger($eventType, $context);
    }

    /**
     * 发送邮件通知
     */
    protected static function sendEmail(array $template, array $context): void
    {
        $subject = self::renderTemplate((string) ($template['subject'] ?? ''), $context);
        $content = self::renderTemplate((string) ($template['content'] ?? ''), $context);

        // 记录发送日志
        Db::name('notification_logs')->insert([
            'template_id' => $template['id'],
            'event_type'   => $template['event_type'],
            'target'       => 'email',
            'content'      => $content,
            'status'       => 'sent',
            'created_at'   => datetime_now(),
        ]);

        // TODO: 接入真实邮件服务（SMTP/企业邮箱API）
        // 当前阶段：记录日志，邮件服务在 Phase 1 后续接入
    }

    /**
     * 模板变量替换
     */
    protected static function renderTemplate(string $template, array $context): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($context) {
            return (string) ($context[$matches[1]] ?? $matches[0]);
        }, $template);
    }
}
