-- SQLite 增量DDL（适用于数据库 app_release.sqlite）
-- 执行方式：通过PHP脚本或sqlite3命令行执行

-- 1. 版本表增加访问控制字段
ALTER TABLE app_versions ADD COLUMN access_type VARCHAR(30) NOT NULL DEFAULT 'public';
ALTER TABLE app_versions ADD COLUMN access_password VARCHAR(64) DEFAULT NULL;
ALTER TABLE app_versions ADD COLUMN signer_fingerprint VARCHAR(128) DEFAULT NULL;
ALTER TABLE app_versions ADD COLUMN signature_verified INTEGER NOT NULL DEFAULT 0;
ALTER TABLE app_versions ADD COLUMN signature_verified_at DATETIME DEFAULT NULL;

-- 2. 发布任务表增加定时字段
ALTER TABLE release_tasks ADD COLUMN scheduled_at DATETIME DEFAULT NULL;
ALTER TABLE release_tasks ADD COLUMN is_scheduled INTEGER NOT NULL DEFAULT 0;

-- 3. 崩溃上报表增加符号化字段
ALTER TABLE crash_reports ADD COLUMN symbolized INTEGER NOT NULL DEFAULT 0;
ALTER TABLE crash_reports ADD COLUMN symbolized_stack LONGTEXT DEFAULT NULL;

-- 4. 创建通知模板表
CREATE TABLE IF NOT EXISTS notification_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    channel VARCHAR(30) NOT NULL DEFAULT 'email',
    subject VARCHAR(255) DEFAULT NULL,
    body TEXT NOT NULL,
    variables TEXT DEFAULT NULL,
    status INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

-- 5. 创建webhooks表
CREATE TABLE IF NOT EXISTS webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(500) NOT NULL,
    secret VARCHAR(255) DEFAULT NULL,
    events VARCHAR(255) NOT NULL DEFAULT 'release.created',
    status INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

-- 6. 创建通知日志表
CREATE TABLE IF NOT EXISTS notification_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    template_id INTEGER NOT NULL,
    release_id INTEGER DEFAULT NULL,
    channel VARCHAR(30) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    response TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL
);

-- 7. 创建符号化文件表
CREATE TABLE IF NOT EXISTS symbol_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    version_id INTEGER NOT NULL,
    platform VARCHAR(30) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INTEGER DEFAULT 0,
    created_at DATETIME NOT NULL
);

-- 8. 创建保留策略表
CREATE TABLE IF NOT EXISTS retention_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    policy_name VARCHAR(100) NOT NULL DEFAULT 'default',
    retention_days INTEGER NOT NULL DEFAULT 90,
    max_versions INTEGER NOT NULL DEFAULT 50,
    keep_minimum INTEGER NOT NULL DEFAULT 3,
    auto_delete INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

-- 9. 创建测试群组表
CREATE TABLE IF NOT EXISTS tester_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    status INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

-- 10. 创建群组成员表
CREATE TABLE IF NOT EXISTS tester_group_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    user_id INTEGER DEFAULT NULL,
    device_id INTEGER DEFAULT NULL,
    email VARCHAR(120) DEFAULT NULL,
    remark VARCHAR(255) DEFAULT NULL,
    status INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL
);

-- 11. 创建邀请表
CREATE TABLE IF NOT EXISTS invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    group_id INTEGER DEFAULT NULL,
    email VARCHAR(120) DEFAULT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    invite_type VARCHAR(30) NOT NULL DEFAULT 'test',
    max_uses INTEGER NOT NULL DEFAULT 1,
    used_count INTEGER NOT NULL DEFAULT 0,
    expire_at DATETIME DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_by INTEGER DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

-- 12. 创建性能监控表
CREATE TABLE IF NOT EXISTS perf_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    version_id INTEGER DEFAULT NULL,
    device_id VARCHAR(64) DEFAULT NULL,
    metric_type VARCHAR(50) NOT NULL,
    metric_name VARCHAR(100) NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    platform VARCHAR(30) DEFAULT NULL,
    os_version VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL
);

-- 13. 创建实验表
CREATE TABLE IF NOT EXISTS experiments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    group_tags VARCHAR(255) DEFAULT NULL,
    start_at DATETIME DEFAULT NULL,
    end_at DATETIME DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

-- 14. 创建实验版本表
CREATE TABLE IF NOT EXISTS experiment_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL,
    version_id INTEGER NOT NULL,
    group_tag VARCHAR(50) NOT NULL,
    weight INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL
);

-- 15. 创建差分包表
CREATE TABLE IF NOT EXISTS delta_packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id INTEGER NOT NULL,
    base_version_id INTEGER NOT NULL,
    target_version_id INTEGER NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INTEGER DEFAULT 0,
    md5 VARCHAR(64) DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

-- 16. 创建SSO提供商表
CREATE TABLE IF NOT EXISTS sso_providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    client_id VARCHAR(255) NOT NULL,
    client_secret VARCHAR(255) NOT NULL,
    authorize_url VARCHAR(500) NOT NULL,
    token_url VARCHAR(500) NOT NULL,
    userinfo_url VARCHAR(500) DEFAULT NULL,
    mapping VARCHAR(500) DEFAULT NULL,
    config TEXT DEFAULT NULL,
    redirect_url VARCHAR(500) DEFAULT NULL,
    enabled INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

-- 17. 创建CDN节点表
CREATE TABLE IF NOT EXISTS cdn_nodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    region VARCHAR(50) NOT NULL DEFAULT 'global',
    host VARCHAR(255) NOT NULL,
    weight INTEGER NOT NULL DEFAULT 1,
    secret_key VARCHAR(255) DEFAULT NULL,
    status INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
