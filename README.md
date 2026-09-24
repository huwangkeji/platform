# PHP App 发布 / 升级分发平台 V1.0

> **开发者：沧州虎王科技**
>
> 企业级应用发布与升级分发平台，覆盖应用全生命周期管理、多渠道分发、灰度发布、版本控制与运维监控。

基于 ThinkPHP 8.1 的应用发布与升级分发平台：应用管理、安装包上传、版本发布（全量 / 灰度 / 定向 / 实验组）、渠道分发、更新检测决策、下载统计、用户反馈与工单、崩溃上报、后台管理（LayUI 单页）与前台下载中心（含二维码）。

## 功能一览

| 模块 | 说明 |
| --- | --- |
| 应用管理 | 应用增删改查、启停用、包名/Bundle ID、当前版本与版本数统计 |
| 版本管理 | 安装包上传（类型/扩展名校验、MD5/SHA1/SHA256 指纹、大小限制）、版本列表、删除 |
| 发布管理 | 全量发布、灰度发布（1/5/10/20/30/50/100% 稳定哈希）、定向发布（渠道/用户/设备规则）、A/B 实验分组发布、暂停、回滚、发布任务历史 |
| 更新检测 | APP 端检查更新：按应用→版本号→平台/架构→渠道→最低版本→灰度规则→设备→实验组 决策，返回新版本与下载地址 |
| 渠道分发 | 渠道管理、渠道二维码下载、渠道下载统计 |
| 反馈与工单 | 用户反馈提交（自动生成工单号）、反馈列表/详情、工单处理（指派/优先级/解决方案/关联修复版本） |
| 崩溃上报 | APP 端崩溃信息上报、堆栈符号化、后台查看 |
| 统计报表 | Dashboard 核心指标 + 趋势图、下载/升级统计、版本/平台/渠道/反馈类型分布、留存分析、漏斗分析、实时数据看板 |
| 前台下载中心 | 官方下载页（多平台识别）、版本详情、历史版本、官方/指定版本/渠道二维码、多语言支持 |
| 系统管理 | 管理员、角色与权限（RBAC）、操作日志、审计合规报告导出 |
| 通知与 Webhook | 发布自动通知（邮件/Webhook）、通知模板管理 |
| 访问控制 | 下载密码保护、访问类型控制（公开/密码/白名单） |
| 签名验证 | APK/IPA 安装包签名证书指纹提取与验证 |
| 版本归档 | 保留策略自动归档/清理旧版本 |
| 定时发布 | 发布窗口期控制，定时任务自动执行 |
| 测试群组 | 测试人员群组管理，定向群组发布 |
| 邀请系统 | Token 邀请链接生成/消费，邀请注册 |
| CDN 加速 | 多节点 CDN 分发，按地理位置就近下载 |
| SSO 单点登录 | OAuth2 授权码模式，支持企业微信/钉钉/通用身份提供商 |
| A/B 测试 | 实验分组管理，更新检测按实验组分流 |
| 增量更新 | bsdiff 差分包生成，客户端合并还原 |
| 性能监控 | 客户端 SDK 采集启动时间/内存/CPU/ANR，后台聚合展示 |
| 多语言 | 前台下载页支持中文/英文/日/韩/西/法/德/俄/葡/阿 10+ 语言 |
| 应用内更新 SDK | Android（Java）/ iOS（Swift）SDK，封装更新检测+下载+安装 |

## 技术栈

- PHP 8.0+ / ThinkPHP 8.1（think-orm 3.x）
- MySQL 5.7+ / SQLite 3（本地体验与沙箱验证）
- LayUI 2.x（后台管理界面，资源本地化离线可用）
- 原生 HTML/JS/CSS（前台，前端零构建）

## 目录结构

```
app-release-platform/
├── app/
│   ├── controller/
│   │   ├── admin/          # 后台管理接口（登录/应用/版本/发布/反馈/工单/统计…）
│   │   ├── api/            # APP 端接口（update check/latest/feedback/crash…）
│   │   └── Index.php       # 前台页面与下载重定向
│   ├── middleware/         # AuthAdmin（登录鉴权）、RbacCheck（RBAC 权限）
│   └── service/            # 决策引擎/发布/上传/操作日志/RBAC/Token/通知/Webhook/签名/符号化/归档/计划任务/导出/邀请/CDN/SSO/实验/差分包/性能 等
├── config/                 # 应用配置（app.php、database.php、cache.php…）
├── database/
│   ├── schema.mysql.sql    # MySQL 建表脚本（33 张表）
│   ├── seed.sql            # 初始管理员、角色权限、渠道种子
│   └── app_release.sqlite  # SQLite 演示数据库（含演示数据，可直接运行）
├── public/
│   ├── index.php           # 入口
│   ├── router.php          # php -S 内置服务器路由（开发调试用）
│   ├── page/               # 前台下载中心、后台管理页面
│   └── static/             # css/js/layui（layui 已本地化）
├── route/app.php           # 全部路由定义
├── storage/                # 安装包、头像、崩溃、反馈图片、二维码文件
├── runtime/                # 运行日志与缓存
├── docs/
│   ├── DEPLOY.md           # 部署文档（Nginx/PHP-FPM/MySQL）
│   └── sdk/                # Android/iOS 应用内更新 SDK 源码与 README
├── composer.json / vendor/
├── .env / .example.env     # 环境配置（模板见 .example.env）
└── tools/                  # 定时任务脚本（cleanup_versions.php / scheduled_release.php）
```

## 快速启动（开发调试）

环境要求：PHP 8.0+，PHP 需启用 `pdo_sqlite` 或 `pdo_mysql`、`fileinfo`、`openssl`、`mbstring` 扩展。

```bash
# 1. 安装依赖（首次）
composer install

# 2. 配置环境
cp .example.env .env   # 按需修改数据库驱动、APP_KEY

# 3. 使用内置服务器启动（开发调试，SQLite 可直接运行）
php -S 127.0.0.1:8000 -t public public/router.php

# 4. 访问
# 前台下载中心：http://127.0.0.1:8000/
# 管理后台：    http://127.0.0.1:8000/admin
# 默认账号：    admin / admin123（部署后请立即修改）
```

> 使用 MySQL 时：先执行 `database/schema.mysql.sql` 建表，再执行 `database/seed.sql` 导入种子数据，然后在 `.env` 中设置 `DB_DRIVER=mysql` 与连接信息。

## 核心接口

### 后台（管理端，需 Bearer Token）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | /api/v1/admin/login | 管理员登录 |
| GET | /api/v1/admin/dashboard | 控制台核心数据 |
| GET | /api/v1/admin/dashboard/realtime | 实时数据看板（窗口指标+分钟序列+事件流） |
| GET/POST | /api/v1/admin/apps | 应用列表 / 新建 |
| PUT/DELETE | /api/v1/admin/apps/:id | 更新 / 删除应用 |
| POST | /api/v1/admin/apps/:id/enable\|disable | 启用 / 停用应用 |
| GET | /api/v1/admin/versions | 版本列表 |
| POST | /api/v1/admin/apps/:id/upload | 上传安装包 |
| POST | /api/v1/admin/versions/:id/publish | 发布（release_type: full/gray/target_channel/target_user/target_device/target_group） |
| POST | /api/v1/admin/versions/:id/pause / rollback | 暂停 / 回滚版本 |
| GET | /api/v1/admin/releases | 发布任务列表 |
| GET/PUT | /api/v1/admin/feedbacks、feedback/:id | 反馈列表 / 详情 |
| GET/PUT | /api/v1/admin/tickets、tickets/:id | 工单列表 / 处理 |
| GET | /api/v1/admin/crashes / devices / channels / notices | 崩溃 / 设备 / 渠道 / 公告 |
| GET | /api/v1/admin/admins / roles / permissions | 管理员 / 角色 / 权限树 |
| GET | /api/v1/admin/downloads / updates / stats/* | 统计报表 |
| GET | /api/v1/admin/stats/retention | 留存分析 |
| GET | /api/v1/admin/stats/funnel | 漏斗分析 |
| GET | /api/v1/admin/operation-logs | 操作日志 |
| GET | /api/v1/admin/operation-logs/export | 合规报告导出（CSV） |
| GET | /api/v1/admin/stats/downloads/export | 下载日志导出（CSV） |

### APP 端（公开接口，供客户端集成）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | /api/v1/app/update/check?app_code=&version_code=&platform=&os_version=&architecture=&channel=&device_id= | 检查更新（决策引擎） |
| GET | /api/v1/app/version/latest?app_code=&platform= | 最新版本信息 |
| GET | /api/v1/app/version/history?app_code=&page=&page_size= | 历史版本列表 |
| GET | /api/v1/app/version/:id/download?channel= | 下载（302 / 流式输出，视 DOWNLOAD_MODE） |
| POST | /api/v1/feedback/create | 提交反馈（自动生成工单） |
| POST | /api/v1/feedback/upload | 上传反馈附件 |
| POST | /api/v1/crash/report | 崩溃上报 |
| POST | /api/v1/device/register | 设备注册 |
| POST | /api/v1/device/heartbeat | 设备心跳 |
| GET | /api/v1/notice/list?app_code= | 公告列表 |
| POST | /api/v1/perf/report | 性能指标上报 |
| GET | /api/v1/invite/check?token= | 邀请链接校验 |
| POST | /api/v1/invite/accept?token= | 邀请接受 |
| GET | /api/v1/sso/login?provider= | SSO 登录入口 |
| GET | /api/v1/sso/callback?code=&state= | SSO 授权回调 |

## 发布与更新决策说明

- **决策链路**：应用 → 版本号大于客户端 → 平台匹配 → 架构匹配（未指定即通用）→ 发布状态（published/gray）→ 发布任务放行（全量直接放行；灰度按 `crc32(设备|用户)` 稳定哈希与百分比比较；定向按渠道/用户/设备规则；实验组按 CRC32 分桶）→ 返回目标版本。
- **强制升级**：版本标记 `is_force_update=1`，或客户端 `version_code < minimum_version_code` 时 `force_update=true`。
- **版本状态机**：`draft → testing/pending → published / gray → paused / rollback`，发布全量时同平台其他版本自动暂停。

## 配置说明（.env 关键项）

| 配置 | 说明 |
| --- | --- |
| APP_KEY | Token 签名密钥，**生产环境必须改为随机长字符串**（`php -r "echo bin2hex(random_bytes(24));"`） |
| DB_DRIVER | `mysql`（生产）或 `sqlite`（快速体验） |
| DOWNLOAD_MODE | `redirect`（Nginx alias，推荐）/ `xaccel`（X-Accel-Redirect）/ `stream`（PHP 流式，开发用） |
| APP_DEBUG | 生产环境务必设为 `false` |

完整部署（Nginx + PHP-FPM + MySQL 配置、伪静态、Storage 目录权限、下载加速等）见 **[docs/DEPLOY.md](docs/DEPLOY.md)**。

## SDK 接入

平台提供 Android（Java）与 iOS（Swift）应用内更新 SDK，封装更新检测、下载、安装流程。详见 **[docs/sdk/README.md](docs/sdk/README.md)**。

## 说明

- 演示数据（应用「掌上快递助手」「企业办公套件」、示例版本、反馈、工单、渠道等）位于 SQLite 数据库与 `storage/apps/kuaidi/`，便于开箱体验；正式使用请切换 MySQL 并重新导入干净数据。
- 上传大小默认上限 1GB，可在 `config/app.php` 的 `upload_max_size` 调整，并同步修改 Nginx `client_max_body_size`。
- 后台管理界面使用 LayUI 全量版本地资源，离线可用；生成二维码依赖 `public/static/js/qrcode.min.js`（davidshimjs qrcodejs）。
- **开发者：沧州虎王科技**，企业级内部部署，操作全程留痕，支持 RBAC 权限管控与合规报告导出。
