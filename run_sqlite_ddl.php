<?php
/**
 * SQLite 增量 DDL 执行脚本
 * 用法：php run_sqlite_ddl.php /path/to/database/app_release.sqlite /path/to/sqlite_incremental.sql
 */

$dbFile = $argv[1] ?? 'database/app_release.sqlite';
$sqlFile = $argv[2] ?? 'database/sqlite_incremental.sql';

if (!file_exists($dbFile)) {
    die("错误：数据库文件不存在: $dbFile\n");
}

if (!file_exists($sqlFile)) {
    die("错误：SQL 文件不存在: $sqlFile\n");
}

$pdo = new PDO("sqlite:$dbFile");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = file_get_contents($sqlFile);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success = 0;
$failed = 0;
foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    try {
        $pdo->exec($stmt);
        $success++;
        echo "✅ " . substr($stmt, 0, 60) . "...\n";
    } catch (PDOException $e) {
        $failed++;
        echo "❌ " . substr($stmt, 0, 60) . "...\n";
        echo "   错误: " . $e->getMessage() . "\n";
    }
}

echo "\n============================\n";
echo "执行完成：成功 $success 条，失败 $failed 条\n";
echo "数据库: $dbFile\n";

// 列出所有表
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
echo "当前表数量: " . count($tables) . "\n";
