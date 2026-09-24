<?php
header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4;dbname=app_release', 'app_release', 'AppRelease@2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    echo "permissions count: " . $pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn() . "\n";
    echo "max id: " . $pdo->query("SELECT MAX(id) FROM permissions")->fetchColumn() . "\n";
    echo "notice perms:\n";
    $rows = $pdo->query("SELECT id,name,code,parent_id,type FROM permissions WHERE code LIKE 'notice%' OR name LIKE '%公告%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) echo "  id={$r['id']} name={$r['name']} code={$r['code']} parent={$r['parent_id']} type={$r['type']}\n";
    $rp = $pdo->query("SELECT COUNT(*) FROM role_permission")->fetchColumn();
    echo "role_permission count: $rp\n";
    // 检查权限 id 27-29 是否已存在
    $ids = $pdo->query("SELECT id FROM permissions WHERE id IN (27,28,29)")->fetchAll(PDO::FETCH_COLUMN);
    echo "ids 27-29 present: " . implode(',', $ids) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
