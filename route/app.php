<?php
// +----------------------------------------------------------------------
// | PHP App发布/升级分发平台 V1.0 路由定义
// +----------------------------------------------------------------------
use think\facade\Route;

use app\middleware\AuthAdmin;
use app\middleware\AuthUser;
use app\middleware\RbacCheck;
use app\middleware\Throttle;

// 健康检查
Route::get('ping', function () {
    return json(['code' => 0, 'message' => 'pong', 'data' => date('Y-m-d H:i:s')]);
});

// ---------------- 前台页面 ----------------
Route::get('/', 'Index/index')->completeMatch();
Route::get('developer', 'Index/developer')->completeMatch();
Route::get('about', 'Index/about')->completeMatch();
Route::get('app/:code', 'Index/download');
Route::get('app/:code/history', 'Index/history');
Route::get('download/:id', 'Index/downloadRedirect')->pattern(['id' => '\d+']);
Route::get('download/version/:id', 'Index/downloadRedirect')->pattern(['id' => '\d+']);
Route::get('download/delta/:id', 'Index/deltaRedirect')->pattern(['id' => '\d+']);
// 渠道二维码下载：/download/{app_code}?channel=agent_a → 最新可用版本
Route::get('download/:code', 'Index/downloadByCode');
Route::get('admin', 'Index/admin')->completeMatch();
Route::get('admin/:page', 'Index/admin');

// ---------------- API v1 ----------------
Route::group('api/v1', function () {

    // 管理员登录（公开，带 IP 限流防爆破）
    Route::post('admin/login', 'api.Admin/login')->middleware(Throttle::class, 'admin_login:20:60')->completeMatch();

    // 管理端接口（需登录 + 权限）
    Route::group('admin', function () {
        // 认证
        Route::post('logout', 'api.Admin/logout')->completeMatch();

        // Dashboard
        Route::get('dashboard', 'admin.Dashboard/index')->completeMatch();
        Route::get('dashboard/trend', 'admin.Dashboard/trend')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('dashboard/charts', 'admin.Dashboard/charts')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('dashboard/realtime', 'admin.Dashboard/realtime')->middleware(RbacCheck::class, 'stat.view')->completeMatch();

        // 应用管理
        Route::get('apps', 'admin.Apps/index')->middleware(RbacCheck::class, 'app.view')->completeMatch();
        Route::post('apps', 'admin.Apps/create')->middleware(RbacCheck::class, 'app.edit')->completeMatch();
        Route::get('apps/:id', 'admin.Apps/read')->middleware(RbacCheck::class, 'app.view')->pattern(['id' => '\d+']);
        Route::put('apps/:id', 'admin.Apps/update')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::delete('apps/:id', 'admin.Apps/delete')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::post('apps/:id/enable', 'admin.Apps/enable')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::post('apps/:id/disable', 'admin.Apps/disable')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::get('apps/:id/versions', 'admin.Versions/index')->middleware(RbacCheck::class, 'version.view')->pattern(['id' => '\d+']);

        // 版本管理
        Route::get('versions', 'admin.Versions/index')->middleware(RbacCheck::class, 'version.view')->completeMatch();
        Route::get('versions/:id', 'admin.Versions/read')->middleware(RbacCheck::class, 'version.view')->pattern(['id' => '\d+']);
        Route::post('versions', 'admin.Versions/create')->middleware(RbacCheck::class, 'version.upload')->completeMatch();
        Route::post('apps/:id/upload', 'admin.Versions/upload')->middleware(RbacCheck::class, 'version.upload')->pattern(['id' => '\d+']);
        Route::put('versions/:id', 'admin.Versions/update')->middleware(RbacCheck::class, 'version.upload')->pattern(['id' => '\d+']);
        Route::delete('versions/:id', 'admin.Versions/delete')->middleware(RbacCheck::class, 'version.upload')->pattern(['id' => '\d+']);
        Route::post('versions/:id/publish', 'admin.Versions/publish')->middleware(RbacCheck::class, 'version.publish')->pattern(['id' => '\d+']);
        Route::post('versions/:id/pause', 'admin.Versions/pause')->middleware(RbacCheck::class, 'version.publish')->pattern(['id' => '\d+']);
        Route::post('versions/:id/rollback', 'admin.Versions/rollback')->middleware(RbacCheck::class, 'version.rollback')->pattern(['id' => '\d+']);

        // 发布任务
        Route::get('releases', 'admin.Releases/index')->middleware(RbacCheck::class, 'version.view')->completeMatch();
        Route::post('releases', 'admin.Releases/create')->middleware(RbacCheck::class, 'version.publish')->completeMatch();
        Route::put('releases/:id', 'admin.Releases/update')->middleware(RbacCheck::class, 'version.publish')->pattern(['id' => '\d+']);
        Route::post('releases/:id/stop', 'admin.Releases/stop')->middleware(RbacCheck::class, 'version.publish')->pattern(['id' => '\d+']);

        // 用户
        Route::get('users', 'admin.Users/index')->middleware(RbacCheck::class, 'user.view')->completeMatch();
        Route::get('users/:id', 'admin.Users/read')->middleware(RbacCheck::class, 'user.view')->pattern(['id' => '\d+']);
        // 启用/禁用属于写操作，必须 user.edit 权限；不能挂在只读的 user.view 上，防止仅可查看用户的角色越权操作
        Route::post('users/:id/disable', 'admin.Users/disable')->middleware(RbacCheck::class, 'user.edit')->pattern(['id' => '\d+']);
        Route::post('users/:id/enable', 'admin.Users/enable')->middleware(RbacCheck::class, 'user.edit')->pattern(['id' => '\d+']);

        // 设备
        Route::get('devices', 'admin.Devices/index')->middleware(RbacCheck::class, 'device.view')->completeMatch();
        Route::get('devices/:id', 'admin.Devices/read')->middleware(RbacCheck::class, 'device.view')->pattern(['id' => '\d+']);

        // 反馈
        Route::get('feedbacks', 'admin.Feedbacks/index')->middleware(RbacCheck::class, 'feedback.handle')->completeMatch();
        Route::get('feedbacks/:id', 'admin.Feedbacks/read')->middleware(RbacCheck::class, 'feedback.handle')->pattern(['id' => '\d+']);
        Route::put('feedbacks/:id', 'admin.Feedbacks/update')->middleware(RbacCheck::class, 'feedback.handle')->pattern(['id' => '\d+']);

        // 工单
        Route::get('tickets', 'admin.Tickets/index')->middleware(RbacCheck::class, 'ticket.handle')->completeMatch();
        Route::post('tickets', 'admin.Tickets/create')->middleware(RbacCheck::class, 'ticket.handle')->completeMatch();
        Route::get('tickets/:id', 'admin.Tickets/read')->middleware(RbacCheck::class, 'ticket.handle')->pattern(['id' => '\d+']);
        Route::put('tickets/:id', 'admin.Tickets/update')->middleware(RbacCheck::class, 'ticket.handle')->pattern(['id' => '\d+']);

        // 渠道
        Route::get('channels', 'admin.Channels/index')->middleware(RbacCheck::class, 'app.view')->completeMatch();
        Route::post('channels', 'admin.Channels/create')->middleware(RbacCheck::class, 'app.edit')->completeMatch();
        Route::put('channels/:id', 'admin.Channels/update')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::delete('channels/:id', 'admin.Channels/delete')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);

        // 崩溃日志
        Route::get('crashes', 'admin.Crashes/index')->middleware(RbacCheck::class, 'device.view')->completeMatch();

        // 统计
        Route::get('downloads', 'admin.Stats/downloads')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('updates', 'admin.Stats/updates')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('stats/versions', 'admin.Stats/versions')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('stats/platforms', 'admin.Stats/platforms')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('stats/channels', 'admin.Stats/channels')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('stats/feedbacks', 'admin.Stats/feedbacks')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('stats/downloads/export', 'admin.Stats/exportDownloads')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('stats/retention', 'admin.Stats/retention')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('stats/funnel', 'admin.Stats/funnel')->middleware(RbacCheck::class, 'stat.view')->completeMatch();

        // 崩溃列表 + 详情（含符号化结果）
        Route::get('crashes', 'admin.Crashes/index')->middleware(RbacCheck::class, 'device.view')->completeMatch();
        Route::get('crashes/:id', 'admin.Crashes/read')->middleware(RbacCheck::class, 'device.view')->pattern(['id' => '\d+']);

        // 符号文件管理（崩溃堆栈符号化）
        Route::get('symbols', 'admin.Symbols/index')->middleware(RbacCheck::class, 'device.view')->completeMatch();
        Route::post('symbols', 'admin.Symbols/create')->middleware(RbacCheck::class, 'device.view')->completeMatch();
        Route::delete('symbols/:id', 'admin.Symbols/delete')->middleware(RbacCheck::class, 'device.view')->pattern(['id' => '\d+']);
        Route::post('symbols/symbolize', 'admin.Symbols/symbolize')->middleware(RbacCheck::class, 'device.view')->completeMatch();

        // 版本保留策略（自动归档/清理）
        Route::get('retentions', 'admin.Retention/index')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::get('retentions/effective', 'admin.Retention/read')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::post('retentions', 'admin.Retention/create')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::delete('retentions/:id', 'admin.Retention/delete')->middleware(RbacCheck::class, 'system.setting')->pattern(['id' => '\d+']);
        Route::post('retentions/run', 'admin.Retention/run')->middleware(RbacCheck::class, 'system.setting')->completeMatch();

        // 测试人员群组管理
        Route::get('tester-groups', 'admin.TesterGroups/index')->middleware(RbacCheck::class, 'app.view')->completeMatch();
        Route::post('tester-groups', 'admin.TesterGroups/create')->middleware(RbacCheck::class, 'app.edit')->completeMatch();
        Route::put('tester-groups/:id', 'admin.TesterGroups/update')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::delete('tester-groups/:id', 'admin.TesterGroups/delete')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::get('tester-groups/:id/members', 'admin.TesterGroups/members')->middleware(RbacCheck::class, 'app.view')->pattern(['id' => '\d+']);
        Route::post('tester-groups/:id/members', 'admin.TesterGroups/addMember')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::delete('tester-group-members/:id', 'admin.TesterGroups/removeMember')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);

        // 邀请链接/邮件邀请管理
        Route::get('invites', 'admin.Invites/index')->middleware(RbacCheck::class, 'app.view')->completeMatch();
        Route::post('invites', 'admin.Invites/create')->middleware(RbacCheck::class, 'app.edit')->completeMatch();
        Route::post('invites/:id/toggle', 'admin.Invites/toggle')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::delete('invites/:id', 'admin.Invites/delete')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);

        // CDN 节点管理（海外加速/多节点分发）
        Route::get('cdns', 'admin.Cdns/index')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::post('cdns', 'admin.Cdns/create')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::put('cdns/:id', 'admin.Cdns/update')->middleware(RbacCheck::class, 'system.setting')->pattern(['id' => '\d+']);
        Route::delete('cdns/:id', 'admin.Cdns/delete')->middleware(RbacCheck::class, 'system.setting')->pattern(['id' => '\d+']);
        Route::get('cdns/test', 'admin.Cdns/test')->middleware(RbacCheck::class, 'system.setting')->completeMatch();

        // A/B 实验管理
        Route::get('experiments', 'admin.Experiments/index')->middleware(RbacCheck::class, 'app.view')->completeMatch();
        Route::post('experiments', 'admin.Experiments/create')->middleware(RbacCheck::class, 'app.edit')->completeMatch();
        Route::put('experiments/:id', 'admin.Experiments/update')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::post('experiments/:id/assign', 'admin.Experiments/assign')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);
        Route::delete('experiments/:id', 'admin.Experiments/delete')->middleware(RbacCheck::class, 'app.edit')->pattern(['id' => '\d+']);

        // 增量差分包管理
        Route::get('deltas', 'admin.Deltas/index')->middleware(RbacCheck::class, 'version.view')->completeMatch();
        Route::post('deltas', 'admin.Deltas/create')->middleware(RbacCheck::class, 'version.upload')->completeMatch();
        Route::post('deltas/generate', 'admin.Deltas/generate')->middleware(RbacCheck::class, 'version.upload')->completeMatch();
        Route::delete('deltas/:id', 'admin.Deltas/delete')->middleware(RbacCheck::class, 'version.upload')->pattern(['id' => '\d+']);

        // SSO 提供商配置
        Route::get('sso-providers', 'admin.SsoProviders/index')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::post('sso-providers', 'admin.SsoProviders/create')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::post('sso-providers/:id/toggle', 'admin.SsoProviders/toggle')->middleware(RbacCheck::class, 'system.setting')->pattern(['id' => '\d+']);
        Route::delete('sso-providers/:id', 'admin.SsoProviders/delete')->middleware(RbacCheck::class, 'system.setting')->pattern(['id' => '\d+']);

        // 性能监控聚合
        Route::get('perf', 'admin.Perf/index')->middleware(RbacCheck::class, 'stat.view')->completeMatch();
        Route::get('perf/anrs', 'admin.Perf/anrs')->middleware(RbacCheck::class, 'stat.view')->completeMatch();

        // 系统设置
        Route::get('admins', 'admin.System/admins')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::post('admins', 'admin.System/createAdmin')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::put('admins/:id', 'admin.System/updateAdmin')->middleware(RbacCheck::class, 'system.setting')->pattern(['id' => '\d+']);
        Route::get('roles', 'admin.System/roles')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::get('permissions', 'admin.System/permissions')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::put('roles/:id', 'admin.System/updateRole')->middleware(RbacCheck::class, 'system.setting')->pattern(['id' => '\d+']);
        Route::get('operation-logs', 'admin.System/operationLogs')->middleware(RbacCheck::class, 'system.setting')->completeMatch();
        Route::get('operation-logs/export', 'admin.System/exportCompliance')->middleware(RbacCheck::class, 'system.setting')->completeMatch();

        // 公告
        Route::get('notices', 'admin.Notices/index')->middleware(RbacCheck::class, 'notice.view')->completeMatch();
        Route::post('notices', 'admin.Notices/create')->middleware(RbacCheck::class, 'notice.edit')->completeMatch();
        Route::put('notices/:id', 'admin.Notices/update')->middleware(RbacCheck::class, 'notice.edit')->pattern(['id' => '\d+']);
        Route::delete('notices/:id', 'admin.Notices/delete')->middleware(RbacCheck::class, 'notice.edit')->pattern(['id' => '\d+']);
    })->middleware(AuthAdmin::class);

    // ---------------- APP 端公开接口 ----------------
    // 检查更新（核心接口）
    Route::get('app/update/check', 'api.Update/check')->completeMatch();
    // 性能上报（客户端 SDK 采集，IP 限流）
    Route::post('perf/report', 'api.Perf/report')->middleware(Throttle::class, 'perf:60:60')->completeMatch();
    // SSO 登录入口/回调（公开）
    Route::get('sso/login', 'api.Sso/login')->completeMatch();
    Route::get('sso/callback', 'api.Sso/callback')->completeMatch();
    // 启用应用列表（官方首页）
    Route::get('app/list', 'api.App/list')->completeMatch();
    // 应用信息
    Route::get('app/info', 'api.App/info')->completeMatch();
    // 最新版本
    Route::get('app/version/latest', 'api.Version/latest')->completeMatch();
    // 历史版本
    Route::get('app/version/history', 'api.Version/history')->completeMatch();
    // 提交反馈（IP 限流，防刷库）
    Route::post('feedback/create', 'api.Feedback/create')->middleware(Throttle::class, 'feedback:20:60')->completeMatch();
    // 上传反馈附件（IP 限流，防灌盘）
    Route::post('feedback/upload', 'api.Feedback/upload')->middleware(Throttle::class, 'upload:10:60')->completeMatch();
    // Crash 上报（IP 限流）
    Route::post('crash/report', 'api.Crash/report')->middleware(Throttle::class, 'crash:30:60')->completeMatch();
    // 设备注册（IP 限流）
    Route::post('device/register', 'api.Device/register')->middleware(Throttle::class, 'device_reg:20:60')->completeMatch();
    // 设备心跳（IP 限流）
    Route::post('device/heartbeat', 'api.Device/heartbeat')->middleware(Throttle::class, 'device_hb:30:60')->completeMatch();
    // 公告列表
    Route::get('notice/list', 'api.Notice/list')->completeMatch();
    // 邀请链接校验/接受（公开，token 即凭证）
    Route::get('invite/check', 'api.Invite/check')->completeMatch();
    Route::post('invite/accept', 'api.Invite/accept')->completeMatch();

    // ---------------- 前台用户（UCenter 账号同步） ----------------
    // 公开：注册 / 登录（带 IP 限流）
    Route::post('user/register', 'api.User/register')->middleware(Throttle::class, 'user_reg:10:60')->completeMatch();
    Route::post('user/login', 'api.User/login')->middleware(Throttle::class, 'user_login:20:60')->completeMatch();
    // 需登录
    Route::group('user', function () {
        Route::post('logout', 'api.User/logout')->completeMatch();
        Route::put('password', 'api.User/changePassword')->completeMatch();
        Route::put('email', 'api.User/changeEmail')->completeMatch();
        Route::get('me', 'api.User/me')->completeMatch();
        Route::post('sync/retry', 'api.User/retrySync')->completeMatch();
    })->middleware(AuthUser::class);
});

// ---------------- UCenter 服务器回调（前台公开，签名校验在控制器内） ----------------
Route::any('api/uc', 'api.UcenterNotify/index')->completeMatch();