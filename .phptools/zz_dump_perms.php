<?php
header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4;dbname=app_release', 'app_release', 'AppRelease@2026', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    echo "==PERMS==\n";
    foreach ($pdo->query("SELECT id,name,code,parent_id,type FROM permissions ORDER BY id") as $r) {
        echo "{$r['id']}|{$r['name']}|{$r['code']}|{$r['parent_id']}|{$r['type']}\n";
    }
    echo "==ROLEPERM==\n";
    foreach ($pdo->query("SELECT role_id,permission_id FROM role_permission ORDER BY role_id,permission_id") as $r) {
        echo "{$r['role_id']}|{$r['permission_id']}\n";
    }
} catch (Throwable $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
