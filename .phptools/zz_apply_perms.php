<?php
header('Content-Type: text/plain; charset=utf-8');
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4;dbname=app_release', 'app_release', 'AppRelease@2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
$now = date('Y-m-d H:i:s');
// 1. 补权限
$stmt = $pdo->prepare("INSERT IGNORE INTO permissions (id,name,code,parent_id,type,created_at,updated_at) VALUES (?,?,?,?,?,?,?)");
$perms = [
    [27,'公告管理','notices','0','menu'],
    [28,'公告查看','notice.view','27','oper'],
    [29,'公告编辑','notice.edit','27','oper'],
];
foreach ($perms as $p) { $stmt->execute([$p[0],$p[1],$p[2],$p[3],$p[4],$now,$now]); }
echo "permissions applied\n";
// 2. 补授权
$rp = [
    [1,27],[1,28],[1,29],
    [2,28],[2,29],
    [6,28],[6,29],
    [7,28],
];
$stmt2 = $pdo->prepare("INSERT IGNORE INTO role_permission (role_id,permission_id) VALUES (?,?)");
foreach ($rp as $r) { $stmt2->execute($r); }
echo "role_permission applied\n";
// 3. 验证
echo "permissions count: " . $pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn() . "\n";
echo "role_permission count: " . $pdo->query("SELECT COUNT(*) FROM role_permission")->fetchColumn() . "\n";
$ids = $pdo->query("SELECT id FROM permissions WHERE id IN (27,28,29)")->fetchAll(PDO::FETCH_COLUMN);
echo "ids 27-29: " . implode(',', $ids) . "\n";
echo "OK\n";
