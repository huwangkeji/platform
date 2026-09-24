# 应用分发平台 V1.0 — 部署指南

> **开发者**：沧州虎王科技  
> **版本**：V1.0  
> **更新日期**：2026-09-24

---

## 一、环境要求

### 1.1 系统环境

- **操作系统**：Linux（推荐 CentOS 7+ / Ubuntu 20.04+）
- **Web 服务器**：Nginx 1.18+ 或 Apache 2.4+
- **PHP 版本**：8.0 或更高（推荐 8.1）
- **数据库**：SQLite 3（快速体验）或 MySQL 5.7+ / MariaDB 10.3+（生产环境）

### 1.2 PHP 必需扩展

```bash
# 检查扩展是否已安装
php -m | grep -E "pdo|mbstring|openssl|fileinfo|json|ctype|filter"
```

| 扩展 | 用途 | 必须 |
|------|------|------|
| `pdo_sqlite` | SQLite 数据库驱动 | SQLite 模式必需 |
| `pdo_mysql` | MySQL 数据库驱动 | MySQL 模式必需 |
| `mbstring` | 多字节字符串处理 | ✅ |
| `openssl` | 加密与签名验证 | ✅ |
| `fileinfo` | 文件类型检测（上传校验） | ✅ |
| `json` | API 数据序列化 | ✅ |
| `ctype` | 字符类型验证 | ✅ |
| `filter` | 输入过滤 | ✅ |

### 1.3 可选但推荐的扩展

| 扩展 | 用途 |
|------|------|
| `bcmath` | 高精度计算（统计报表） |
| `curl` | Webhook 外部通知 |
| `zip` | 安装包处理 |
| `gd` 或 `imagick` | 二维码生成（已本地化，可不装） |

---

## 二、安装步骤

### 2.1 上传项目代码

将项目代码上传到服务器 Web 目录，推荐路径：

```
/www/wwwroot/app-release-platform/
```

**宝塔面板用户**：
1. 登录宝塔面板 → 网站 → 添加站点
2. 选择 PHP 版本 8.1+
3. 将项目代码解压到站点根目录

### 2.2 安装 Composer 依赖

```bash
cd /www/wwwroot/app-release-platform/

# 如果没有 composer，先安装
# curl -sS https://getcomposer.org/installer | php
# mv composer.phar /usr/local/bin/composer

# 安装依赖（生产环境）
composer install --no-dev --optimize-autoloader

# 开发环境
# composer install
```

> **注意**：`vendor/` 目录包含 ThinkPHP 8.1 框架及依赖，生产部署时务必执行 `composer install`。

### 2.3 目录权限设置

```bash
cd /www/wwwroot/app-release-platform/

# 设置运行时目录可写
chmod -R 755 runtime/
chmod -R 755 storage/
chmod -R 755 public/uploads/ 2>/dev/null || true

# 设置数据库文件权限（SQLite 模式）
chmod 755 database/
chmod 664 database/app_release.sqlite 2>/dev/null || true

# 确保 Nginx/PHP-FPM 用户有写入权限
chown -R www:www runtime/ storage/ database/  # 根据实际用户调整
```

---

## 三、数据库初始化

### 3.1 SQLite 模式（快速体验 / 小型部署）

SQLite 数据库文件已包含在项目中：

```
database/app_release.sqlite   # 演示数据（含测试应用）
```

**配置 `.env`**：

```ini
DB_DRIVER = sqlite
SQLITE_DATABASE = database/app_release.sqlite
```

> SQLite 模式开箱即用，适合个人测试或低并发场景。首次使用已有演示数据。

### 3.2 MySQL 模式（生产环境推荐）

**步骤 1：创建数据库**

```sql
CREATE DATABASE app_release CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**步骤 2：执行建表脚本**

```bash
# 连接 MySQL 并执行 schema
mysql -u root -p app_release < database/schema.mysql.sql

# 导入初始种子数据（管理员、角色权限等）
mysql -u root -p app_release < database/seed.sql
```

**步骤 3：配置 `.env`**

```ini
DB_DRIVER = mysql
DB_HOST = 127.0.0.1
DB_NAME = app_release
DB_USER = root
DB_PASS = your_mysql_password
DB_PORT = 3306
DB_CHARSET = utf8mb4
```

### 3.3 增量更新（已有数据库）

如果从旧版本升级，执行增量 DDL：

```bash
# SQLite 增量
php run_sqlite_ddl.php database/app_release.sqlite sqlite_incremental.sql

# MySQL 增量（从 schema.mysql.sql 第 385 行起）
# 手动提取并执行新增 CREATE TABLE / ALTER TABLE 语句
```

---

## 四、配置文件

### 4.1 环境配置 `.env`

复制模板并修改：

```bash
cp .example.env .env
```

**关键配置项**：

```ini
# 应用密钥（用于 Token 签名，必须改为随机长字符串）
APP_KEY = [请替换为 48 位随机字符串]

# 运行模式（生产环境必须设为 false）
APP_DEBUG = false

# 下载模式
# redirect = Nginx 302 跳转（推荐，性能最佳）
# xaccel = Nginx X-Accel-Redirect（需配置 internal 路径）
# stream = PHP 流式输出（开发调试用）
DOWNLOAD_MODE = redirect

# 上传限制（同步修改 Nginx client_max_body_size）
UPLOAD_MAX_SIZE = 1073741824    # 1GB
```

**生成随机 APP_KEY**：

```bash
# 方法 1：PHP
php -r "echo bin2hex(random_bytes(24));"

# 方法 2：OpenSSL
openssl rand -hex 24
```

### 4.2 下载模式说明

| 模式 | 配置 | 适用场景 |
|------|------|----------|
| `redirect` | Nginx `alias` 指向 storage | **生产推荐**，零 PHP 流量 |
| `xaccel` | Nginx `X-Accel-Redirect` | 大文件保护性下载 |
| `stream` | PHP `readfile()` | 开发调试，低性能 |

**Nginx alias 配置示例**（redirect 模式）：

```nginx
location /storage/ {
    alias /www/wwwroot/app-release-platform/storage/;
    expires 30d;
    add_header Cache-Control "public, immutable";
}
```

---

## 五、Nginx 配置

### 5.1 完整 Nginx 站点配置

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /www/wwwroot/app-release-platform/public;
    index index.php index.html;

    # 上传大小限制（与 UPLOAD_MAX_SIZE 同步）
    client_max_body_size 1G;

    # 伪静态（ThinkPHP 路由）
    location / {
        if (!-e $request_filename) {
            rewrite ^(.*)$ /index.php?s=$1 last;
            break;
        }
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass  unix:/tmp/php-cgi-81.sock;  # 根据实际 PHP 版本调整
        fastcgi_index index.php;
        include       fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        
        # 上传超时（大文件需要）
        fastcgi_connect_timeout 300s;
        fastcgi_send_timeout 300s;
        fastcgi_read_timeout 300s;
    }

    # 静态资源缓存
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 禁止访问敏感目录
    location ~ ^/(app|config|extend|vendor|runtime|database|route|think)/ {
        deny all;
        return 404;
    }

    # 禁止访问 .env 等敏感文件
    location ~ /\.(env|git|htaccess|ini)$ {
        deny all;
        return 404;
    }

    # Storage 下载加速（redirect 模式必需）
    location /storage/ {
        alias /www/wwwroot/app-release-platform/storage/;
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 日志
    access_log /www/wwwlogs/app-release-access.log;
    error_log  /www/wwwlogs/app-release-error.log;
}
```

### 5.2 Apache 配置（如使用 Apache）

项目 `public/.htaccess` 已包含伪静态规则：

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.*)$ index.php?s=$1 [QSA,L]
</IfModule>
```

---

## 六、HTTPS 配置（强烈推荐）

### 6.1 Let's Encrypt 证书（宝塔一键申请）

宝塔面板 → 网站 → 选择站点 → SSL → Let's Encrypt → 申请证书 → 开启强制 HTTPS。

### 6.2 手动配置

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;  # 强制跳转 HTTPS
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /www/wwwroot/app-release-platform/public;

    # SSL 证书
    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-ECDSA-AES128-GCM-SHA256:...;
    
    # ... 其余配置同 5.1
}
```

---

## 七、定时任务配置

### 7.1 版本自动清理（cleanup_versions.php）

```bash
# 宝塔面板 → 计划任务 → 添加任务
# 任务类型：Shell 脚本
# 执行周期：每天 0 点 0 分
# 脚本内容：
cd /www/wwwroot/app-release-platform/
php tools/cleanup_versions.php
```

### 7.2 定时发布扫描（scheduled_release.php）

```bash
# 执行周期：每 5 分钟
# 脚本内容：
cd /www/wwwroot/app-release-platform/
php tools/scheduled_release.php
```

### 7.3 Crontab 方式（无宝塔时）

```bash
# 编辑 crontab
crontab -e

# 添加以下行
# 每天 0 点执行版本清理
0 0 * * * cd /www/wwwroot/app-release-platform/ && php tools/cleanup_versions.php

# 每 5 分钟扫描定时发布
*/5 * * * * cd /www/wwwroot/app-release-platform/ && php tools/scheduled_release.php
```

---

## 八、首次登录与初始化

### 8.1 默认管理员账号

| 账号 | 密码 |
|------|------|
| `admin` | `admin123` |

> **上线后请立即修改默认密码！**

### 8.2 访问地址

| 入口 | URL | 说明 |
|------|-----|------|
| 前台首页 | `http://your-domain.com/` | 应用列表与下载中心 |
| 管理后台 | `http://your-domain.com/admin` | 管理员登录 |
| API 接口 | `http://your-domain.com/api/v1/...` | APP 端接入 |
| 关于程序 | `http://your-domain.com/about` | 平台介绍页 |

---

## 九、安全加固建议

### 9.1 必做项（上线前）

| 优先级 | 操作 | 命令/路径 |
|--------|------|-----------|
| P0 | 修改 APP_KEY | `.env` → `APP_KEY = [48位随机字符串]` |
| P0 | 修改默认管理员密码 | 后台 → 系统管理 → 管理员 |
| P0 | 关闭调试模式 | `.env` → `APP_DEBUG = false` |
| P1 | 启用 HTTPS | 宝塔 SSL 或手动配置证书 |
| P1 | 设置目录权限 | `chmod 755` 代码目录，`777` 仅 runtime/storage |
| P2 | 配置防火墙 | 仅开放 80/443，关闭 3306 外网访问 |

### 9.2 禁止访问的目录

Nginx 配置中已包含以下禁止访问规则：

```
/app/        → 应用源代码
/config/     → 配置文件
/vendor/     → 依赖包
/runtime/    → 日志缓存
/database/   → 数据库文件
/.env        → 环境配置
```

---

## 十、故障排查

### 10.1 常见错误

| 现象 | 原因 | 解决 |
|------|------|------|
| 500 错误 / 空白页 | `runtime/` 不可写 | `chmod -R 755 runtime/` |
| 数据库连接失败 | 配置错误或权限 | 检查 `.env` 数据库配置 |
| 上传文件失败 | `storage/` 不可写 / 大小限制 | 检查目录权限与 `client_max_body_size` |
| CSS/JS 加载 404 | 伪静态未配置 | 检查 Nginx rewrite 规则 |
| 后台样式异常 | LayUI 资源未加载 | 确认 `public/static/layui/` 存在 |

### 10.2 日志位置

| 日志类型 | 路径 |
|----------|------|
| PHP 错误 | `runtime/log/` |
| Nginx 访问 | `/www/wwwlogs/...` |
| Nginx 错误 | `/www/wwwlogs/...-error.log` |

---

## 十一、更新维护

### 11.1 代码更新流程

```bash
cd /www/wwwroot/app-release-platform/

# 1. 备份现有代码
tar czf backup-$(date +%Y%m%d%H%M%S).tar.gz app/ public/ route/ config/

# 2. 上传新代码覆盖（保留 .env 和 database/）
# 使用 rsync 或宝塔文件管理器

# 3. 清理缓存
rm -rf runtime/cache/*
rm -rf runtime/temp/*

# 4. 执行数据库增量 DDL（如有）
php run_sqlite_ddl.php database/app_release.sqlite sqlite_incremental.sql

# 5. 验证
php -l app/controller/Index.php  # 语法检查
curl -I http://your-domain.com/   # HTTP 200 检查
```

### 11.2 数据库备份

```bash
# SQLite 备份
cp database/app_release.sqlite database/app_release_$(date +%Y%m%d).sqlite.bak

# MySQL 备份
mysqldump -u root -p app_release > backup_$(date +%Y%m%d).sql
```

---

## 十二、技术栈与依赖

| 组件 | 版本 | 说明 |
|------|------|------|
| ThinkPHP | 8.1 | PHP 框架 |
| PHP | 8.0+ | 运行环境 |
| MySQL / SQLite | 5.7+ / 3.x | 数据存储 |
| LayUI | 2.x | 后台 UI 框架 |
| Nginx | 1.18+ | Web 服务器 |
| Composer | 2.x | 依赖管理 |

---

**开发者**：沧州虎王科技  
**版本**：V1.0  
**授权**：企业级内部部署
