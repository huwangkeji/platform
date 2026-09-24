-- ============================================================
-- PHP App发布/升级分发平台 V1.0
-- 数据库结构（MySQL 8.0 / InnoDB / utf8mb4）
-- 数据库名：app_release
-- ============================================================

CREATE DATABASE IF NOT EXISTS `app_release` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `app_release`;

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 21.1 管理员表
-- ------------------------------------------------------------
CREATE TABLE `admin_users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(64) NOT NULL UNIQUE COMMENT '登录账号',
    `password` VARCHAR(255) NOT NULL COMMENT '密码哈希(password_hash)',
    `nickname` VARCHAR(64) DEFAULT NULL,
    `email` VARCHAR(120) DEFAULT NULL,
    `mobile` VARCHAR(30) DEFAULT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台管理员';

-- ------------------------------------------------------------
-- 21.2 角色表
-- ------------------------------------------------------------
CREATE TABLE `roles` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(64) NOT NULL COMMENT '角色名称',
    `code` VARCHAR(64) NOT NULL UNIQUE COMMENT '角色标识',
    `status` TINYINT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色';

-- ------------------------------------------------------------
-- 21.3 权限表
-- ------------------------------------------------------------
CREATE TABLE `permissions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT '权限名称',
    `code` VARCHAR(100) NOT NULL UNIQUE COMMENT '权限标识',
    `parent_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '父级权限',
    `type` VARCHAR(20) DEFAULT 'menu' COMMENT 'menu菜单 oper操作',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限';

-- ------------------------------------------------------------
-- 21.4 管理员角色关联
-- ------------------------------------------------------------
CREATE TABLE `admin_role` (
    `admin_id` BIGINT UNSIGNED NOT NULL,
    `role_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`admin_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员-角色';

-- ------------------------------------------------------------
-- 21.5 角色权限关联
-- ------------------------------------------------------------
CREATE TABLE `role_permission` (
    `role_id` BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='角色-权限';

-- ------------------------------------------------------------
-- 22 应用表
-- ------------------------------------------------------------
CREATE TABLE `apps` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT '应用名称',
    `code` VARCHAR(64) NOT NULL UNIQUE COMMENT 'App ID(代码标识,用于URL/接口)',
    `package_name` VARCHAR(150) DEFAULT NULL COMMENT 'Android包名',
    `bundle_id` VARCHAR(150) DEFAULT NULL COMMENT 'iOS Bundle ID',
    `icon` VARCHAR(255) DEFAULT NULL COMMENT '应用图标URL',
    `description` TEXT COMMENT '应用简介',
    `website` VARCHAR(255) DEFAULT NULL COMMENT '官方网站',
    `privacy_url` VARCHAR(255) DEFAULT NULL COMMENT '隐私政策地址',
    `agreement_url` VARCHAR(255) DEFAULT NULL COMMENT '用户协议地址',
    `contact` VARCHAR(120) DEFAULT NULL COMMENT '客服联系方式',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='应用';

-- ------------------------------------------------------------
-- 23 版本表
-- ------------------------------------------------------------
CREATE TABLE `app_versions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `version_name` VARCHAR(50) NOT NULL COMMENT '版本号 如3.2.0',
    `version_code` INT NOT NULL COMMENT '版本Code',
    `version_type` VARCHAR(30) NOT NULL DEFAULT 'release' COMMENT 'release正式版 test测试版 beta RC internal内部版',
    `release_title` VARCHAR(255) DEFAULT NULL COMMENT '发布标题',
    `release_note` TEXT COMMENT '更新说明',
    `platform` VARCHAR(30) NOT NULL COMMENT 'android/ios/windows/macos/linux/other',
    `os_version` VARCHAR(50) DEFAULT NULL COMMENT '最低系统版本',
    `architecture` VARCHAR(50) DEFAULT NULL COMMENT 'CPU架构',
    `file_name` VARCHAR(255) DEFAULT NULL COMMENT '文件名',
    `file_path` VARCHAR(500) DEFAULT NULL COMMENT '文件存储路径',
    `file_size` BIGINT UNSIGNED DEFAULT 0 COMMENT '文件大小(字节)',
    `md5` VARCHAR(64) DEFAULT NULL,
    `sha1` VARCHAR(64) DEFAULT NULL,
    `sha256` VARCHAR(128) DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'draft' COMMENT 'draft草稿 testing测试中 pending待发布 gray灰度中 published已发布 paused已暂停 rollback已回滚 offline已下线',
    `is_force_update` TINYINT NOT NULL DEFAULT 0 COMMENT '是否强制升级',
    `minimum_version_code` INT DEFAULT NULL COMMENT '最低版本限制',
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `published_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_app_version` (`app_id`, `version_code`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='应用版本';

-- ------------------------------------------------------------
-- 24 发布任务表
-- ------------------------------------------------------------
CREATE TABLE `release_tasks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL COMMENT '发布任务名称',
    `release_type` VARCHAR(30) NOT NULL COMMENT 'full全量 gray灰度 target_user指定用户 target_device指定设备 target_channel指定渠道',
    `rollout_percent` INT NOT NULL DEFAULT 100 COMMENT '发布比例(灰度)',
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending待发布 running发布中 finished已结束 stopped已停止',
    `start_at` DATETIME DEFAULT NULL,
    `end_at` DATETIME DEFAULT NULL,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `approved_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_app_status` (`app_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='发布任务';

-- ------------------------------------------------------------
-- 25 发布规则表（灰度和定向发布）
-- ------------------------------------------------------------
CREATE TABLE `release_rules` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `release_id` BIGINT UNSIGNED NOT NULL,
    `rule_type` VARCHAR(30) NOT NULL COMMENT 'user/device/channel/platform/os_version/architecture/region/percent',
    `rule_key` VARCHAR(100) NOT NULL COMMENT '如channel_code/user_id/device_id',
    `rule_value` TEXT,
    `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='发布规则';

-- ------------------------------------------------------------
-- 26 渠道表
-- ------------------------------------------------------------
CREATE TABLE `channels` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL COMMENT '0表示全局渠道',
    `channel_code` VARCHAR(64) NOT NULL,
    `channel_name` VARCHAR(100) NOT NULL,
    `status` TINYINT NOT NULL DEFAULT 1,
    `remark` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    UNIQUE KEY `uk_app_channel` (`app_id`, `channel_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='渠道';

-- ------------------------------------------------------------
-- 27 用户表
-- ------------------------------------------------------------
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(64) DEFAULT NULL,
    `password` VARCHAR(255) DEFAULT NULL COMMENT '本地密码哈希(password_hash)，UCenter 接入后为本地兜底',
    `mobile` VARCHAR(30) DEFAULT NULL,
    `email` VARCHAR(120) DEFAULT NULL,
    `uc_uid` BIGINT UNSIGNED DEFAULT NULL COMMENT 'UCenter 用户ID',
    `uc_username` VARCHAR(64) DEFAULT NULL COMMENT 'UCenter 用户名',
    `uc_sync` TINYINT NOT NULL DEFAULT 0 COMMENT 'UCenter 同步状态：0未接入/1已同步/2同步失败待重试',
    `uc_sync_at` DATETIME DEFAULT NULL COMMENT '最近一次 UCenter 同步时间',
    `status` TINYINT NOT NULL DEFAULT 1,
    `register_at` DATETIME DEFAULT NULL,
    `last_login_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    UNIQUE KEY `uk_users_uc_uid` (`uc_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='终端用户';

-- ------------------------------------------------------------
-- 28 设备表
-- ------------------------------------------------------------
CREATE TABLE `devices` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `device_id` VARCHAR(120) NOT NULL UNIQUE,
    `device_sn` VARCHAR(120) DEFAULT NULL,
    `imei` VARCHAR(50) DEFAULT NULL,
    `mac` VARCHAR(50) DEFAULT NULL,
    `model` VARCHAR(100) DEFAULT NULL,
    `firmware_version` VARCHAR(50) DEFAULT NULL,
    `app_version` VARCHAR(50) DEFAULT NULL,
    `os_version` VARCHAR(50) DEFAULT NULL,
    `channel_code` VARCHAR(64) DEFAULT NULL,
    `status` TINYINT NOT NULL DEFAULT 1,
    `first_active_at` DATETIME DEFAULT NULL,
    `last_active_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_model` (`model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='设备';

-- ------------------------------------------------------------
-- 29 反馈表
-- ------------------------------------------------------------
CREATE TABLE `feedbacks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `device_id` BIGINT UNSIGNED DEFAULT NULL,
    `type` VARCHAR(30) NOT NULL COMMENT 'bug/suggestion/question/account/device/crash/network/other',
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `images` TEXT COMMENT '图片列表(JSON)',
    `attachments` TEXT COMMENT '附件列表(JSON)',
    `app_version` VARCHAR(50) DEFAULT NULL,
    `os_version` VARCHAR(50) DEFAULT NULL,
    `device_model` VARCHAR(100) DEFAULT NULL,
    `channel_code` VARCHAR(64) DEFAULT NULL,
    `contact` VARCHAR(120) DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending/open/processing/solved/closed',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_app_status` (`app_id`, `status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户反馈';

-- ------------------------------------------------------------
-- 30 工单表
-- ------------------------------------------------------------
CREATE TABLE `tickets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_no` VARCHAR(50) NOT NULL UNIQUE COMMENT '工单编号 BUG-YYYYMMDD-xxx',
    `feedback_id` BIGINT UNSIGNED DEFAULT NULL,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT,
    `priority` VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'low/normal/high/urgent',
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending待处理 processing处理中 dev_pending待研发 dev_processing研发中 test_pending待测试 solved已解决 closed已关闭 unreproducible无法复现 duplicate重复问题',
    `assignee_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '负责人(admin_users.id)',
    `solution` TEXT COMMENT '处理结果',
    `fixed_version_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '解决版本(app_versions.id)',
    `closed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_app_status` (`app_id`, `status`),
    INDEX `idx_assignee` (`assignee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单';

-- ------------------------------------------------------------
-- 31 Crash上报表
-- ------------------------------------------------------------
CREATE TABLE `crash_reports` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `device_id` BIGINT UNSIGNED DEFAULT NULL,
    `version_code` INT DEFAULT NULL,
    `version_name` VARCHAR(50) DEFAULT NULL,
    `device_model` VARCHAR(100) DEFAULT NULL,
    `os_version` VARCHAR(50) DEFAULT NULL,
    `error_message` TEXT,
    `stack_trace` LONGTEXT,
    `log_file` VARCHAR(500) DEFAULT NULL,
    `occurred_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_app_version` (`app_id`, `version_code`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='崩溃记录';

-- ------------------------------------------------------------
-- 32 下载日志表
-- ------------------------------------------------------------
CREATE TABLE `download_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `device_id` BIGINT UNSIGNED DEFAULT NULL,
    `channel_code` VARCHAR(64) DEFAULT NULL,
    `platform` VARCHAR(30) DEFAULT NULL,
    `ip` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_app_version` (`app_id`, `version_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='下载日志';

-- ------------------------------------------------------------
-- 33 升级检测/升级记录表
-- ------------------------------------------------------------
CREATE TABLE `update_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `old_version` VARCHAR(50) DEFAULT NULL,
    `new_version` VARCHAR(50) DEFAULT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `device_id` BIGINT UNSIGNED DEFAULT NULL,
    `channel_code` VARCHAR(64) DEFAULT NULL,
    `result` VARCHAR(30) DEFAULT NULL COMMENT 'check检测 upgrade升级',
    `created_at` DATETIME NOT NULL,
    INDEX `idx_app` (`app_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='升级记录';

-- ------------------------------------------------------------
-- 34 公告表
-- ------------------------------------------------------------
CREATE TABLE `notices` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL表示全局公告',
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `status` TINYINT NOT NULL DEFAULT 1,
    `start_at` DATETIME DEFAULT NULL,
    `end_at` DATETIME DEFAULT NULL,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='公告';

-- ------------------------------------------------------------
-- 35 操作日志表
-- ------------------------------------------------------------
CREATE TABLE `operation_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id` BIGINT UNSIGNED DEFAULT NULL,
    `module` VARCHAR(50) DEFAULT NULL COMMENT '操作模块',
    `action` VARCHAR(50) DEFAULT NULL COMMENT '操作类型 create/update/delete/publish/...',
    `target_id` BIGINT UNSIGNED DEFAULT NULL,
    `description` TEXT COMMENT '操作内容描述',
    `ip` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_admin` (`admin_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作日志';
-- ============================================================
-- Phase 1 功能扩展表（2026-09-23）
-- ============================================================

-- 36 发布通知模板表
CREATE TABLE `notification_templates` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT '模板名称',
    `event_type` VARCHAR(50) NOT NULL COMMENT '事件类型: release/publish/pause/rollback',
    `channel` VARCHAR(30) NOT NULL COMMENT '通知渠道: email/webhook/sms',
    `subject` VARCHAR(255) DEFAULT NULL COMMENT '邮件主题',
    `content` TEXT COMMENT '通知内容模板',
    `status` TINYINT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知模板';

-- 37 WebHook 配置表
CREATE TABLE `webhooks` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Webhook名称',
    `url` VARCHAR(500) NOT NULL COMMENT '目标URL',
    `secret` VARCHAR(255) DEFAULT NULL COMMENT '签名密钥',
    `events` VARCHAR(255) NOT NULL COMMENT '订阅事件列表(JSON)',
    `status` TINYINT NOT NULL DEFAULT 1,
    `last_triggered_at` DATETIME DEFAULT NULL,
    `last_response` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='WebHook配置';

-- 38 定时发布任务表（扩展 release_tasks 的 scheduled_at 支持）
-- 使用现有 release_tasks 表，增加 scheduled_at 字段
ALTER TABLE `release_tasks` ADD COLUMN `scheduled_at` DATETIME DEFAULT NULL COMMENT '定时发布时间' AFTER `end_at`;
ALTER TABLE `release_tasks` ADD COLUMN `is_scheduled` TINYINT NOT NULL DEFAULT 0 COMMENT '是否定时发布' AFTER `scheduled_at`;

-- 39 版本访问控制表（扩展 app_versions 表字段）
ALTER TABLE `app_versions` ADD COLUMN `access_type` VARCHAR(30) NOT NULL DEFAULT 'public' COMMENT '访问类型: public公开 password密码 protected保护' AFTER `is_force_update`;
ALTER TABLE `app_versions` ADD COLUMN `access_password` VARCHAR(64) DEFAULT NULL COMMENT '访问密码' AFTER `access_type`;

-- 40 通知发送记录表
CREATE TABLE `notification_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `template_id` BIGINT UNSIGNED DEFAULT NULL,
    `webhook_id` BIGINT UNSIGNED DEFAULT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `target` VARCHAR(500) NOT NULL COMMENT '发送目标',
    `content` TEXT,
    `status` VARCHAR(30) NOT NULL DEFAULT 'pending' COMMENT 'pending/sent/failed',
    `response` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通知发送记录';

-- ============================================================
-- Phase 1-6/1-7 + Phase 2 功能扩展表（2026-09-23）
-- ============================================================

-- 41 符号文件表（崩溃堆栈符号化：Android mapping.txt / iOS dSYM）
CREATE TABLE `symbol_files` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `platform` VARCHAR(30) NOT NULL COMMENT 'android/ios',
    `package_label` VARCHAR(100) DEFAULT NULL COMMENT '标签，如 3.2.0 (32)',
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `md5` VARCHAR(64) DEFAULT NULL,
    `symbol_type` VARCHAR(30) NOT NULL DEFAULT 'mapping' COMMENT 'mapping/dsym',
    `parse_count` INT NOT NULL DEFAULT 0 COMMENT '已符号化次数',
    `status` TINYINT NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_app_platform` (`app_id`, `platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='符号文件';

-- 42 版本保留策略表（自动归档/清理）
CREATE TABLE `retention_policies` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL COMMENT '0表示全局默认策略',
    `keep_count` INT NOT NULL DEFAULT 10 COMMENT '保留最新版本数',
    `max_age_days` INT NOT NULL DEFAULT 180 COMMENT '超过该天数的非当前版本归档/清理',
    `auto_archive` TINYINT NOT NULL DEFAULT 1 COMMENT '是否自动归档',
    `physical_delete` TINYINT NOT NULL DEFAULT 0 COMMENT '归档后是否物理删除文件(0仅标记offline 1删除文件)',
    `status` TINYINT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    UNIQUE KEY `uk_app` (`app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='版本保留策略';

-- 43 测试人员群组表
CREATE TABLE `tester_groups` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL COMMENT '0表示全局群组',
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `status` TINYINT NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_app` (`app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='测试人员群组';

-- 44 群组成员表
CREATE TABLE `tester_group_members` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '终端用户ID',
    `device_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '设备ID',
    `email` VARCHAR(120) DEFAULT NULL,
    `remark` VARCHAR(255) DEFAULT NULL,
    `status` TINYINT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uk_group_user` (`group_id`, `user_id`),
    UNIQUE KEY `uk_group_device` (`group_id`, `device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='群组成员';

-- 45 邀请链接/邮件邀请表
CREATE TABLE `invites` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `group_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '加入的测试群组',
    `email` VARCHAR(120) DEFAULT NULL COMMENT '邀请邮箱',
    `token` VARCHAR(64) NOT NULL UNIQUE COMMENT '邀请令牌',
    `invite_type` VARCHAR(30) NOT NULL DEFAULT 'test' COMMENT 'test测试邀请 alpha内测',
    `max_uses` INT NOT NULL DEFAULT 1 COMMENT '最大使用次数(-1不限)',
    `used_count` INT NOT NULL DEFAULT 0,
    `expire_at` DATETIME DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'active' COMMENT 'active/disabled/expired/used',
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_app` (`app_id`),
    INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='邀请';

-- 46 崩溃上报增加符号化字段
ALTER TABLE `crash_reports` ADD COLUMN `symbolized` TINYINT NOT NULL DEFAULT 0 COMMENT '是否已符号化' AFTER `log_file`;
ALTER TABLE `crash_reports` ADD COLUMN `symbolized_stack` LONGTEXT DEFAULT NULL COMMENT '符号化后的堆栈' AFTER `symbolized`;

-- 47 版本表增加签名验证字段
ALTER TABLE `app_versions` ADD COLUMN `signer_fingerprint` VARCHAR(128) DEFAULT NULL COMMENT '签名证书指纹(SHA256)' AFTER `sha256`;
ALTER TABLE `app_versions` ADD COLUMN `signature_verified` TINYINT NOT NULL DEFAULT 0 COMMENT '签名是否已验证' AFTER `signer_fingerprint`;
ALTER TABLE `app_versions` ADD COLUMN `signature_verified_at` DATETIME DEFAULT NULL COMMENT '签名验证时间' AFTER `signature_verified`;

-- ============================================================
-- Phase 3 功能扩展表（2026-09-23）
-- ============================================================

-- 48 性能监控上报数据表（启动时间/内存/CPU/ANR）
CREATE TABLE `perf_reports` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `device_id` BIGINT UNSIGNED DEFAULT NULL,
    `version_code` INT DEFAULT NULL,
    `version_name` VARCHAR(50) DEFAULT NULL,
    `platform` VARCHAR(30) NOT NULL DEFAULT 'android',
    `metric_type` VARCHAR(30) NOT NULL COMMENT 'startup/memory/cpu/anr/network/frame',
    `metric_name` VARCHAR(100) DEFAULT NULL,
    `value` DOUBLE NOT NULL COMMENT '指标值',
    `extra` TEXT COMMENT '附加JSON（内存详情/卡顿堆栈等）',
    `occurred_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    INDEX `idx_app_metric` (`app_id`, `metric_type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='性能上报';

-- 49 实验（A/B测试）表
CREATE TABLE `experiments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `group_tags` VARCHAR(255) NOT NULL DEFAULT '["control","treatment"]' COMMENT '实验组标签(JSON)',
    `status` VARCHAR(30) NOT NULL DEFAULT 'draft' COMMENT 'draft/running/paused/finished',
    `start_at` DATETIME DEFAULT NULL,
    `end_at` DATETIME DEFAULT NULL,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    INDEX `idx_app` (`app_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='A/B实验';

-- 50 实验版本分配表（实验组 -> 版本）
CREATE TABLE `experiment_versions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `experiment_id` BIGINT UNSIGNED NOT NULL,
    `group_tag` VARCHAR(50) NOT NULL COMMENT '实验组标签',
    `version_id` BIGINT UNSIGNED NOT NULL,
    `rollout_percent` INT NOT NULL DEFAULT 100 COMMENT '该组命中比例',
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uk_exp_group` (`experiment_id`, `group_tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='实验版本分配';

-- 51 增量更新差分包表
CREATE TABLE `delta_packages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `app_id` BIGINT UNSIGNED NOT NULL,
    `version_id` BIGINT UNSIGNED NOT NULL COMMENT '目标版本',
    `base_version_id` BIGINT UNSIGNED NOT NULL COMMENT '基础版本',
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `md5` VARCHAR(64) DEFAULT NULL,
    `sha256` VARCHAR(128) DEFAULT NULL,
    `patch_type` VARCHAR(30) NOT NULL DEFAULT 'bsdiff' COMMENT 'bsdiff/hdiff/v1',
    `status` TINYINT NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uk_base_target` (`version_id`, `base_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='增量差分包';

-- 52 SSO 登录提供商配置表
CREATE TABLE `sso_providers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `provider` VARCHAR(50) NOT NULL UNIQUE COMMENT 'wechat_work/dingtalk/oauth2/saml/ad',
    `name` VARCHAR(100) NOT NULL,
    `client_id` VARCHAR(255) DEFAULT NULL,
    `client_secret` VARCHAR(255) DEFAULT NULL,
    `authorize_url` VARCHAR(500) DEFAULT NULL,
    `token_url` VARCHAR(500) DEFAULT NULL,
    `userinfo_url` VARCHAR(500) DEFAULT NULL,
    `scopes` VARCHAR(255) DEFAULT NULL,
    `mapping` VARCHAR(500) DEFAULT NULL COMMENT '用户字段映射JSON',
    `config` TEXT COMMENT '扩展配置JSON（企业微信corp_id/钉钉agent_id等）',
    `redirect_url` VARCHAR(500) DEFAULT NULL,
    `enabled` TINYINT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SSO提供商';

-- 53 CDN 节点表（海外加速/多节点分发）
CREATE TABLE `cdn_nodes` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `region` VARCHAR(50) NOT NULL DEFAULT 'global' COMMENT 'cn/hk/us/sg/eu/global',
    `host` VARCHAR(255) NOT NULL COMMENT 'CDN域名或节点地址',
    `weight` INT NOT NULL DEFAULT 1 COMMENT '权重',
    `secret_key` VARCHAR(255) DEFAULT NULL COMMENT 'URL签名密钥(可选)',
    `status` TINYINT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CDN节点';
