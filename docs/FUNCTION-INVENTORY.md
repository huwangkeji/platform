# 功能清单（实现位置与验证方式）

> 适用版本：PHP App发布/升级分发平台 V1.0（ThinkPHP 8.1）
> 本清单对应交付验收要求：**每个功能项标明实现位置与验证方式**。

## 1. 后台管理（管理端）

| 功能模块 | 实现位置 | 操作路径 | 验证方式 |
| --- | --- | --- | --- |
| 管理员登录/退出 | `app/controller/api/Admin.php`、`app/service/AdminAuthService.php`、`app/middleware/AuthAdmin.php` | `POST /api/v1/admin/login`、`POST /api/v1/admin/logout` | 用 `admin/admin123` 登录获取 Bearer Token；错误密码返回 10001/10002；限流 20 次/60 秒 |
| Dashboard 核心指标 | `app/controller/admin/Dashboard.php` | `GET /api/v1/admin/dashboard` | 返回应用数/版本数/累计下载/待办等；`dashboard/trend`、`dashboard/charts` 需 `stat.view` |
| 应用管理 | `app/controller/admin/Apps.php` | `GET/POST /api/v1/admin/apps`，`PUT/DELETE /apps/:id`，`POST /apps/:id/enable|disable` | 新建应用（App Code 格式校验：字母开头，允许字母/数字/下划线/中划线）；启停用、删除；未授权角色被 RbacCheck 拦截 |
| 版本管理（含上传） | `app/controller/admin/Versions.php`、`app/service/UploadService.php` | `GET /api/v1/admin/versions`，`POST /apps/:id/upload`，`PUT/DELETE /versions/:id` | 上传校验扩展名/MIME/大小/降级拒绝；返回 MD5/SHA1/SHA256 指纹；删除同步清理 storage 文件 |
| 发布管理 | `app/controller/admin/Releases.php`、`app/service/ReleaseService.php` | `POST /api/v1/admin/releases`（release_type: full/gray/target_channel/target_user/target_device），`versions/:id/publish|pause|rollback`，`releases/:id/stop` | 全量发布自动暂停同平台其他版本；灰度按 `crc32(device|user)` 稳定哈希放行 |
| 用户管理 | `app/controller/admin/Users.php` | `GET /api/v1/admin/users`，`POST /users/:id/enable|disable` | 列表/详情；启停用需 `user.edit`（user.view 不可越权写） |
| 设备管理 | `app/controller/admin/Devices.php` | `GET /api/v1/admin/devices` | 需 `device.view` |
| 反馈处理 | `app/controller/admin/Feedbacks.php` | `GET/PUT /api/v1/admin/feedbacks` | 需 `feedback.handle`；可更新处理状态 |
| 工单管理 | `app/controller/admin/Tickets.php` | `GET/POST/PUT /api/v1/admin/tickets` | 需 `ticket.handle`；指派/优先级/解决方案/关联修复版本 |
| 渠道管理 | `app/controller/admin/Channels.php` | `GET/POST /api/v1/admin/channels`，`PUT/DELETE /channels/:id` | 渠道编码格式校验（字母/数字/下划线/中划线）；二维码下载链路 |
| 崩溃日志 | `app/controller/admin/Crashes.php` | `GET /api/v1/admin/crashes` | 需 `device.view` |
| 统计报表 | `app/controller/admin/Stats.php` | `GET /api/v1/admin/downloads|updates|stats/*` | 各指标需 `stat.view`；返回分时段聚合数据 |
| 系统设置（管理员/角色/权限） | `app/controller/admin/System.php`、`app/service/RbacService.php` | `GET /api/v1/admin/admins|roles|permissions`，`PUT /roles/:id`，`GET /operation-logs` | 需 `system.setting`；权限树含 notices/notice.view/notice.edit（id 27-29） |
| 公告管理 | `app/controller/admin/Notices.php` | `GET/POST /api/v1/admin/notices`，`PUT/DELETE /notices/:id` | 列表（按 app/关键词筛选）；创建/更新/删除需 `notice.edit`；写操作记录操作日志 |
| 操作日志 | `app/service/OperationLogService.php` | `GET /api/v1/admin/operation-logs` | 登录/公告/发布等敏感操作自动落日志 |

## 2. APP 端公开接口

| 功能 | 实现位置 | 路径 | 验证方式 |
| --- | --- | --- | --- |
| 检查更新（决策引擎） | `app/controller/api/Update.php`、`app/service/VersionDecisionService.php` | `GET /api/v1/app/update/check` | 按 应用→版本号→平台/架构→渠道→最低版本→灰度规则→设备 决策；返回版本+下载地址+是否强制升级 |
| 应用列表/信息 | `app/controller/api/App.php` | `GET /api/v1/app/list`、`/api/v1/app/info` | 仅返回启用中的应用 |
| 版本查询 | `app/controller/api/Version.php` | `GET /api/v1/app/version/latest|history` | 最新版本按平台过滤；历史版本分页 |
| 反馈提交 | `app/controller/api/Feedback.php` | `POST /api/v1/feedback/create`、`/upload` | 自动生成工单号；附件上传限流 10 次/60 秒 |
| 崩溃上报 | `app/controller/api/Crash.php` | `POST /api/v1/crash/report` | 限流 30 次/60 秒 |
| 设备注册/心跳 | `app/controller/api/Device.php` | `POST /api/v1/device/register|heartbeat` | 注册+心跳更新在线状态 |
| 公告列表（前台） | `app/controller/api/Notice.php` | `GET /api/v1/notice/list` | 返回启用且时间范围内的公告 |
| 用户注册/登录/资料 | `app/controller/api/User.php`、`app/service/UcenterClient.php`、`UcenterSyncService.php` | `POST /api/v1/user/register|login`，`user/me`、`user/password`、`user/email`、`user/sync/retry` | 与 UCenter 2.0 账号同步（UC_API=ucenter.heicat.com, APPID=4）；降级策略 `UC_FALLBACK_LOGIN/WRITE=local` |
| UCenter 回调 | `app/controller/api/UcenterNotify.php` | `ANY /api/v1/uc` | 签名校验 + 时间戳防重放 + 处理用户同步 |

## 3. 前台页面

| 功能 | 实现位置 | 路径 | 验证方式 |
| --- | --- | --- | --- |
| 下载中心官方页 | `public/page/index.html`、`app/controller/Index.php` | `GET /` | 多平台识别展示；下载/历史/二维码入口可用 |
| 管理后台页面 | `public/page/index.html` + `public/static/js/admin.js` | `GET /admin`、`GET /admin/:page` | 单页应用；`page` 参数白名单 `^[a-zA-Z0-9_\-]{1,32}$`；菜单直达路由 `__ADMIN_PAGE__` |
| 下载重定向 | `app/controller/Index.php` | `GET /download/:id`、`/download/:code` | 按 `DOWNLOAD_MODE`：redirect / xaccel / stream |
| 渠道二维码 | `app/controller/Index.php` | `GET /download/:code?channel=` | 二维码按渠道生成并落盘 storage |

## 4. 安全与健壮性

| 能力 | 实现位置 | 说明与验证 |
| --- | --- | --- |
| RBAC 权限控制 | `app/middleware/AuthAdmin.php`、`app/middleware/RbacCheck.php`、`app/service/RbacService.php` | 每个后台接口按权限点放行；公告权限点 27-29 由 seed.sql 导入 |
| 令牌鉴权 | `app/service/TokenService.php` | JWT HS256；scope=admin 校验防止 user 令牌越权 |
| 接口限流 | `app/middleware/Throttle.php` | login 20/60s、feedback 20/60s、upload 10/60s、crash 30/60s、register 10/60s、device 20/30/60s |
| 上传安全 | `app/service/UploadService.php` | 扩展名 + MIME 白名单；1GB 上限；storage 隔离 |
| 异常统一处理 | `app/exception/ExceptionHandle.php`、`app/exception/BizException.php` | 统一 JSON、敏感信息脱敏 |
| 发布/回滚事务 | `app/service/ReleaseService.php` | 发布状态变更事务化，失败自动回滚 |
| 下载/更新日志 | `app/service/OperationLogService.php` | try-catch 包裹，不影响主流程 |

## 5. 初始化与部署

| 项 | 位置 | 说明 |
| --- | --- | --- |
| MySQL 建表 | `database/schema.mysql.sql` | 19 张表 |
| 种子数据 | `database/seed.sql` | 初始管理员 admin/admin123、29 权限、8 组角色 79 条授权、5 渠道；**部署后须改密** |
| SQLite 演示库 | `database/app_release.sqlite` | 与 seed.sql 逐条比对一致（29/79），开箱即用 |
| 环境模板 | `.example.env`（含 DEFAULT_LANG、UC_SSL_VERIFY） | 复制为 `.env` 后按生产填写 |
| 部署文档 | `docs/DEPLOY.md`（Nginx + PHP-FPM + MySQL + 伪静态 + storage 权限） | 服务器 121.40.43.101 已按 production 配置部署 |
| UCenter 同步文档 | `docs/UCENTER-SYNC.md` | 账号同步架构与故障排查 |