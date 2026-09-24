<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;

/**
 * WebHook 服务：管理 WebHook 配置并触发 HTTP 通知
 */
class WebhookService
{
    /**
     * 触发事件到所有匹配的 WebHook
     */
    public static function trigger(string $eventType, array $context): void
    {
        $webhooks = Db::name('webhooks')
            ->where('status', 1)
            ->select()
            ->toArray();

        foreach ($webhooks as $hook) {
            $events = json_decode((string) ($hook['events'] ?? '[]'), true);
            if (!is_array($events) || !in_array($eventType, $events, true)) {
                continue;
            }

            self::send($hook, $eventType, $context);
        }
    }

    /**
     * 发送 WebHook 请求
     */
    protected static function send(array $hook, string $eventType, array $context): void
    {
        $url = (string) ($hook['url'] ?? '');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $payload = json_encode([
            'event'     => $eventType,
            'timestamp' => time(),
            'data'      => $context,
        ], JSON_UNESCAPED_UNICODE);

        $secret = (string) ($hook['secret'] ?? '');
        $headers = ['Content-Type: application/json'];
        if ($secret !== '') {
            $signature = hash_hmac('sha256', $payload, $secret);
            $headers[] = 'X-Webhook-Signature: sha256=' . $signature;
        }

        // 异步发送（不阻塞主流程）
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            // 更新触发记录
            Db::name('webhooks')->where('id', (int) $hook['id'])->update([
                'last_triggered_at' => datetime_now(),
                'last_response'      => json_encode(['code' => $httpCode, 'error' => $error, 'body' => substr((string) $response, 0, 500)], JSON_UNESCAPED_UNICODE),
                'updated_at'         => datetime_now(),
            ]);

            // 记录发送日志
            Db::name('notification_logs')->insert([
                'webhook_id' => $hook['id'],
                'event_type' => $eventType,
                'target'     => $url,
                'content'    => $payload,
                'status'     => ($httpCode >= 200 && $httpCode < 300) ? 'sent' : 'failed',
                'response'   => $response,
                'created_at' => datetime_now(),
            ]);
        } catch (\Throwable $e) {
            // WebHook 发送失败不影响主流程
            Db::name('notification_logs')->insert([
                'webhook_id' => $hook['id'],
                'event_type' => $eventType,
                'target'     => $url,
                'content'    => $payload,
                'status'     => 'failed',
                'response'   => $e->getMessage(),
                'created_at' => datetime_now(),
            ]);
        }
    }
}
