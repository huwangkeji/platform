<?php
/**
 * 定时发布扫描任务
 * 用法: php tools/scheduled_release.php
 * 建议添加到 crontab: * * * * * cd /path/to/app && php tools/scheduled_release.php >> /var/log/scheduled_release.log 2>&1
 */
require __DIR__ . '/../vendor/autoload.php';

// 初始化 ThinkPHP 应用
$app = new \think\App();
$app->initialize();

use app\service\ScheduleService;

$executed = ScheduleService::scanScheduledReleases();
echo '[' . date('Y-m-d H:i:s') . '] 扫描完成，执行了 ' . $executed . ' 个定时发布任务' . PHP_EOL;
