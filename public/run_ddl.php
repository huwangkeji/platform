<?php
/**
 * 上线准备执行脚本
 * 访问后自动执行：
 * 1. SQLite增量DDL
 * 2. 修改APP_KEY为随机密钥
 * 3. 返回执行结果
 */

header('Content-Type: text/plain; charset=utf-8');

$results = [];

// ========== 1. 执行SQLite增量DDL ==========
$results[] = "=== 1. 执行SQLite增量DDL ===";

$dbFile = __DIR__ . '/../database/app_release.sqlite';
$sqlFile = __DIR__ . '/../sqlite_incremental.sql';

if (!file_exists($dbFile)) {
    $results[] = "❌ 数据库文件不存在: $dbFile";
} elseif (!file_exists($sqlFile)) {
    $results[] = "❌ SQL文件不存在: $sqlFile";
} else {
    try {
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
            } catch (PDOException $e) {
                $failed++;
                $results[] = "❌ DDL失败: " . substr($stmt, 0, 50) . " | " . $e->getMessage();
            }
        }
        
        $results[] = "✅ DDL执行完成: 成功 $success 条, 失败 $failed 条";
        
        // 检查表数量
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $results[] = "📊 当前表数量: " . count($tables) . " 张";
        
    } catch (Exception $e) {
        $results[] = "❌ DDL执行异常: " . $e->getMessage();
    }
}

// ========== 2. 修改APP_KEY ==========
$results[] = "\n=== 2. 修改APP_KEY为随机密钥 ===";

$envFile = __DIR__ . '/../.env';
$newKey = bin2hex(random_bytes(24)); // 48位随机密钥

if (!file_exists($envFile)) {
    $results[] = "❌ .env文件不存在";
} else {
    $content = file_get_contents($envFile);
    if (preg_match('/APP_KEY\s*=\s*(.+)/', $content, $matches)) {
        $oldKey = trim($matches[1]);
        $content = preg_replace('/APP_KEY\s*=\s*.+/', "APP_KEY = $newKey", $content);
        file_put_contents($envFile, $content);
        $results[] = "✅ APP_KEY已更新";
        $results[] = "   旧密钥: " . substr($oldKey, 0, 10) . "...";
        $results[] = "   新密钥: " . substr($newKey, 0, 10) . "...";
    } else {
        $results[] = "❌ .env中未找到APP_KEY配置";
    }
}

// ========== 3. 检查关键文件 ==========
$results[] = "\n=== 3. 检查关键文件 ===";

$checkFiles = [
    '../public/page/about.html' => '关于程序页面',
    '../public/static/css/admin.css' => '后台样式',
    '../route/app.php' => '路由配置',
];

foreach ($checkFiles as $file => $desc) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $results[] = "✅ $desc 已部署";
    } else {
        $results[] = "❌ $desc 未找到";
    }
}

// ========== 输出结果 ==========
echo implode("\n", $results);
echo "\n\n============================\n";
echo "执行时间: " . date('Y-m-d H:i:s') . "\n";
echo "请删除此文件以确保安全\n";
