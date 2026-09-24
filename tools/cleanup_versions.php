<?php
/**
 * 版本归档/清理定时任务
 * 用法: php tools/cleanup_versions.php [app_id]
 * 建议添加到 crontab: 0 3 * * * cd /path/to/app && php tools/cleanup_versions.php >> /var/log/cleanup_versions.log 2>&1
 */
require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use app\service\RetentionService;

$appId = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : null;
$stats = RetentionService::run($appId);
echo '[' . date('Y-m-d H:i:s') . '] 清理完成: ' . json_encode($stats, JSON_UNESCAPED_UNICODE) . PHP_EOL;