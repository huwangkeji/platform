<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Db;
use think\facade\Request;

/**
 * 操作日志写入
 */
class OperationLogService
{
    public static function write(int $adminId, string $module, string $action, ?int $targetId, string $description): void
    {
        try {
            Db::name('operation_logs')->insert([
                'admin_id'    => $adminId,
                'module'      => mb_substr($module, 0, 50),
                'action'      => mb_substr($action, 0, 50),
                'target_id'   => $targetId,
                'description' => mb_substr($description, 0, 1000),
                'ip'          => (string) Request::ip(),
                'user_agent'  => mb_substr((string) Request::header('user-agent', ''), 0, 500),
                'created_at'  => datetime_now(),
            ]);
        } catch (\Throwable $e) {
            // 日志写入失败不影响主流程
        }
    }
}