# 部署文档 —— PHP App发布/升级分发平台 V1.0

本文档说明在 Linux 服务器（以 Ubuntu/Debian 为例）上，使用 **Nginx + PHP-FPM + MySQL** 部署本平台的完整步骤与注意事项。

---

## 1. 环境要求

| 组件 | 版本要求 | 说明 |
| --- | --- | --- |
| PHP | 8.0+（建议 8.1/8.2） | 扩展：`pdo_mysql`、`fileinfo`、`openssl`、`mbstring`、`curl`、`gd`（二维码不需要，图片处理需要） |
| PHP-FPM | 与 PHP 同版本 | `php*-fpm` |
| MySQL | 5.7+ / 8.0 | 生产环境推荐 8.0 |
| Nginx | 1.18+ | 需要 `http_rewrite_module`（默认） |
| Composer | 2.x | 仅部署时安装依赖需要 |

---

## 2. 安装基础软件（Ubuntu/Debian）

```bash
apt update
apt install -y nginx mysql-server php8.1-fpm php8.1-cli php8.1-mysql \
  php8.1-mbstring php8.1-xml php8.1-curl php8.1-fileinfo php8.1-gd \
  composer unzip
```

> PHP 版本按发行版可用版本替换（如 `php8.2-*`）。

---

## 3. 上传代码与安装依赖

```bash
# 将工程上传至 /var/www/app-release（示例目录）
mkdir -p /var/www/app-release
# 上传 app-release-platform 目录内容到 /var/www/app-release

cd /var/www/app-release

# 安装 PHP 依赖（vendor 若已随包提供可跳过）
composer install --no-dev --optimize-autoloader
```

---

## 4. MySQL 初始化

### 4.1 创建数据库与账号

```sql
CREATE DATABASE IF NOT EXISTS app_release DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'app_release'@'localhost' IDENTIFIED BY '请改为强密码';
GRANT ALL PRIVILEGES ON app_release.* TO 'app_release'@'localhost';
FLUSH PRIVILEGES;
```

### 4.2 导入表结构与种子数据

```bash
mysql -uapp_release -p app_release < database/schema.mysql.sql
mysql -uapp_release -p app_release < database/seed.sql
```

- `schema.mysql.sql`：19 张表完整定义（含索引、约束）。
- `seed.sql`：初始管理员 `admin / admin123`、超级管理员角色与权限、默认渠道。
- **部署后第一时间登录后台修改管理员密码。**

### 4.3 验证表结构

```sql
USE app_release;
SHOW TABLES;  -- 应列出 19 张表：admin_users、roles、permissions、apps、app_versions、release_tasks …
```

---

## 5. 环境配置（.env）

```bash
cd /var/www/app-release
cp .example.env .env
vim .env
```

关键配置：

```ini
APP_DEBUG = false                    ; 生产必须关闭
APP_KEY = <运行 php -r "echo bin2hex(random_bytes(24));" 生成并粘贴>   ; 必改，Token 签名密钥
DB_DRIVER = mysql
DB_HOST = 127.0.0.1
DB_NAME = app_release
DB_USER = app_release
DB_PASS = <第 4.1 步设置的强密码>
DB_PORT = 3306
DB_CHARSET = utf8mb4
DOWNLOAD_MODE = redirect             ; 推荐：Nginx alias 直出（见下文）
```

> **安全提醒**：`.env` 含数据库密码与 APP_KEY，严禁提交到 Git 仓库；建议用文件属主限制访问：`chmod 600 .env`。

---

## 6. 目录权限

```bash
cd /var/www/app-release
chown -R www-data:www-data storage runtime
chmod -R 755 storage runtime
```

- `storage/`（安装包、崩溃文件、反馈图片、二维码）与 `runtime/`（日志、缓存）必须对 PHP-FPM 运行用户（通常 `www-data`）可写。
- 若启用了 opcache，重启 php-fpm 后生效。

---

## 7. Nginx 配置

创建站点配置 `/etc/nginx/sites-available/app-release`（或放入 `nginx/conf.d`）：

```nginx
server {
    listen 80;
    server_name dl.example.com;          # 改成你的域名

    root /var/www/app-release/public;    # 站点根指向 public/
    index index.php;

    client_max_body_size 1024m;          # 与 upload_max_size 一致（默认 1GB）

    # ThinkPHP 伪静态
    location / {
        try_files $uri $uri/ /index.php?s=$uri&$args;
    }

    # PHP-FPM 转发
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;   # 按实际 PHP 版本调整
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_read_timeout 600;
    }

    # 安装包静态直出（DOWNLOAD_MODE=redirect 时，/storage/ 由 Nginx 直接服务）
    # 注意：storage 在项目根下，不在 public 内，需单独暴露下载子目录
    location ^~ /storage/ {
        alias /var/www/app-release/storage/;
        add_header Content-Disposition "attachment";   # 按需决定是否强制下载
        expires 7d;
    }

    # 静态资源缓存
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|woff2?|svg)$ {
        expires 7d;
        access_log off;
    }

    # 禁止访问隐藏文件
    location ~ /\. {
        deny all;
    }
}
```

启用站点：

```bash
ln -s /etc/nginx/sites-available/app-release /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### 7.1 下载分发模式说明（DOWNLOAD_MODE）

| 模式 | 下载链路 | 适用 |
| --- | --- | --- |
| `redirect` | `/download/:id` → 302 到 `/storage/apps/...`，**Nginx `location ^~ /storage/` 直出** | **推荐生产** |
| `xaccel` | 302 → `X-Accel-Redirect: /storage/...`，Nginx internal 转发（需将 storage alias 配为 `internal;`） | Nginx 高并发下载 |
| `stream` | PHP 读取文件流式输出 | 开发调试、不支持静态直出的环境 |

- `redirect` 模式下无需给 PHP 读安装包，Nginx 直接发文件，性能最佳；务必保证 `storage` 的 alias 路径与 `config/app.php` 的 `storage_path` 一致。
- 大安装包下请调大 `fastcgi_read_timeout`（上传走 PHP 时）与 `client_max_body_size`。

---

## 8. PHP-FPM 调优（可选）

```ini
; /etc/php/8.1/fpm/php.ini
upload_max_filesize = 1024M
post_max_size = 1050M
memory_limit = 256M
max_execution_time = 300
```

```bash
systemctl restart php8.1-fpm
```

---

## 9. 上线后自检清单

```bash
# 1. 服务健康
curl -s http://<域名>/ping            # 期望 {"code":0,"message":"pong",...}

# 2. 后台登录
curl -s -X POST http://<域名>/api/v1/admin/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin123"}'     # 期望 code=0 且返回 token

# 3. 前台页面
curl -sI http://<域名>/                # 200
curl -sI http://<域名>/admin           # 200
curl -sI http://<域名>/app/kuaidi      # 200（应用 code 按实际调整）

# 4. 目录权限
# 登录后台 → 应用 → 上传一个安装包 → 发布 → 检查更新接口能否拿到下载地址

# 5. 安全项
#   - .env 权限 600、APP_DEBUG=false、APP_KEY 已改
#   - 管理员默认密码已修改
#   - storage/ 未暴露其上级目录（仅 /storage/ 子路径可访问，且无目录列表）
#   - runtime/ 与 database/ 不可被 Web 访问（已在 root 指向 public/ 时天然隔离）
```

---

## 10. 常见问题排查

| 现象 | 原因与处理 |
| --- | --- |
| 访问返回 404 / 500 | 站点 root 未指向 `public/`；`try_files` 伪静态缺失；`storage`/`runtime` 不可写 |
| 上传提示「存储目录创建失败」 | `storage/` 对 www-data 无写权限 |
| 上传提示「文件类型不允许」 | 平台按平台类型校验扩展名（如 android 允许 apk/aab；windows 允许 exe/msi），请核实平台与扩展名匹配 |
| 更新检测返回 has_update=false | 客户端 version_code ≥ 已发布版本；或灰度/定向规则未命中（渠道、百分比、规则字段） |
| 下载 404 | 版本未上传安装包、文件被删、或 `DOWNLOAD_MODE=redirect` 时 storage alias 配置错误 |
| 访问 /admin 出现样式错乱 | Nginx 静态资源路径问题：`/static/` 必须能访问到 `public/static/`（root 已指向 public 时正常） |
| 修改 .env 不生效 | 存在 `runtime/cache` 配置缓存，删除 `runtime/cache/*` 后重试 |

---

## 11. 升级与备份

- **备份**：数据库 `mysqldump -uapp_release -p app_release > backup_$(date +%F).sql`，安装包目录 `storage/apps/` 一并备份。
- **升级**：替换代码 → `composer install --no-dev --optimize-autoloader`（如有新增依赖）→ 执行增量 SQL（如有）→ 清空 `runtime/cache` → 重启 PHP-FPM。
- **监控建议**：对 `/ping` 做健康检查；监控 `storage/` 磁盘占用；定期清理 `operation_logs` / `download_logs` / `update_logs` 历史数据（保留策略自定）。