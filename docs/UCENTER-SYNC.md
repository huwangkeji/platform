# UCenter 2.0 账号同步说明

本文档说明「PHP App发布/升级分发平台 V1.0」接入 UCenter 2.0 账号体系的实现细节，包含五种同步策略的触发条件、字段映射、接口定义、异常处理与部署配置。

## 1. 概述

接入后，本站（终端用户 `users` 表）与 UCenter 使用**同一套账号密码**登录：

- 本站注册的用户自动写入 UCenter；
- 本站登录触发同步登录广播，关联站点登录态一致；
- 本站改密、改邮箱实时同步至 UCenter；
- UCenter 已有用户可直接登录本站，首次登录自动建号并绑定。

UCenter 是**账号权威源**：注册时用户名/邮箱占用与否以 UCenter 判定为准；登录时密码以 UCenter 校验为准。

> 后台管理员（`admin_users` 表，JWT + RBAC）不参与本同步，仅终端用户（`users` 表）接入 UCenter。

## 2. 启用配置

新增配置文件 `config/ucenter.php`，全部端点与密钥从 `.env` 读取：

```ini
; ---------------- UCenter 2.0 账号同步 ----------------
; 启用 UCenter 同步：true / false（false 时站点走本地账号体系）
UC_ENABLED = false
; UCenter 服务器根地址（如 https://uc.example.com/uc_server）
UC_API =
; 通信密钥（UCenter 后台为本站分配，与 UC_APPID 配对）
UC_KEY =
; 本站应用 ID（UCenter 后台分配）
UC_APPID = 0
; UCenter 服务器 IP（可空；用于 DNS 解析失败时直连 + 来源校验）
UC_IP =
; 通信超时秒数
UC_TIMEOUT = 5
; 登录降级：local=UCenter 异常时用本地密码兜底；strict=一律以 UCenter 为准
UC_FALLBACK_LOGIN = local
; 写操作降级：local=注册/改密/改邮箱 UCenter 失败时本地继续并标记待同步；strict=失败即整体失败
UC_FALLBACK_WRITE = local
```

`UC_ENABLED=false` 时，所有同步逻辑自动跳过，本站退化为纯本地账号体系（与旧版行为一致），上述后期新增字段无副作用。

### UCenter 后台应用配置

在 UCenter 后台「应用管理」中为本站添加应用：

| 配置项 | 填写值 |
| --- | --- |
| 应用类型 | 其他（Custom） |
| 应用名称 | 本平台名称 |
| 应用的主 URL | `https://你的站点域名/` |
| 应用接口 URL | `https://你的站点域名/api/uc` |
| 通信密钥 | 与 `.env` 的 `UC_KEY` 保持一致 |
| 是否开启同步登录 | 开启 |

应用接口 URL 即 UCenter 服务器回调本站的通知入口（见第 5 节），UCenter 后台可用「测试」按钮验证连通性（预期返回 `1`）。

## 3. 字段映射

| 本站 users 表 | UCenter 2.0 | 说明 |
| --- | --- | --- |
| `username` | `username` | 登录名，两端一致 |
| `password` | （不存明文，UCenter 侧以自有加密保存） | 本站保存 `password_hash` 哈希，仅作 UCenter 不可达时的降级兜底；UCenter 校验通过时以 UCenter 密码为准 |
| `email` | `email` | 邮箱，双向同步 |
| `uc_uid` | `uid` | UCenter 用户 ID，**唯一绑定**（`UNIQUE KEY uk_users_uc_uid`），自动建号的关联键 |
| `uc_username` | `username` | UCenter 用户名镜像，便于反查与通知回调定位 |
| `uc_sync` | — | 同步状态：`0`=未接入/纯本地 `1`=已同步 `2`=同步失败待重试 |
| `uc_sync_at` | — | 最近一次 UCenter 同步时间 |

UCenter 侧字段（`uid`、`username`、`email`）由 UCenter 2.0 标准接口维护，本站不改动其结构。

## 4. 五种同步策略

### ① 本站注册 → 写 UCenter

**触发条件**：`POST /api/v1/user/register`（公开接口）。

**流程**：
1. 本地校验：用户名格式（字母开头、字母数字下划线、3-64 位）、密码 ≥ 6 位、邮箱格式；
2. 本地占用检查（`username` / `uc_username` / `email`）；
3. 调用 UCenter `register(username, password, email)`：
   - 返回正 `uid` → 视为 UC 注册成功，本地建号并写入 `uc_uid / uc_username / uc_sync=1`；
   - 返回负错误码 → **以 UCenter 为准拒绝注册**（如用户名已存在、邮箱已被注册），本地不建号；
   - 网络/签名异常（`UcenterException`）→ 按 `UC_FALLBACK_WRITE`：`local` 时本地建号并标记 `uc_sync=2`（待补同步），`strict` 时整体失败。

**结果响应**：`{ user, uc_sync }`，`uc_sync` 供前端提示"同步完成 / 待恢复后同步"。

### ② 本站登录 → UCenter 校验 + 同步登录广播

**触发条件**：`POST /api/v1/user/login`（公开接口）。

**流程**：
1. 调用 UCenter `login(username, password)`；
2. **UCenter 校验成功**：
   - 本地已有账号 → 校验 `status=1`，如未绑定 `uc_uid` 则补绑定（用户名一致的账号自动绑定 UC uid）；
   - 本地无账号 → **自动建号**（策略⑤），`password` 写入随机不可登录哈希（密码权威在 UCenter），`email` 取 UC 返回值；
   - 调用 UCenter `synlogin(uid)` 获取同步登录脚本；
3. **UCenter 校验失败（返回负错误码）**：
   - `-2 密码错误` → 直接拒绝；
   - `-1 用户不存在`：若本地有账号且本地密码匹配（此前降级注册遗留，`uc_sync=2`），允许**本地密码兜底登录**；否则拒绝，提示用户不存在；
4. **UCenter 服务不可达（网络/签名异常）**：按 `UC_FALLBACK_LOGIN`：`local` 时用本地 `password` 哈希校验兜底（需本地已存密码哈希，且账号未被 UC 侧改密清空）；`strict` 时拒绝登录并提示服务暂不可用；
5. 登录成功签发 JWT（`scope=user`），响应携带 `sync_html`（UCenter 生成的 `<script>` 同步登录代码），**前端需原样输出到页面**，实现关联站点同时登录。

**登录决策矩阵**：

| 场景 | UC 可达 | UC 校验结果 | 本地账号 | 结果 |
| --- | --- | --- | --- | --- |
| 正常登录 | ✅ | 成功 | 存在 | 登录 + 广播 + 已绑定则跳过绑定 |
| UC 用户首登 | ✅ | 成功 | 不存在 | **自动建号** + 登录 + 广播 |
| 密码错误 | ✅ | -2 | 任意 | 拒绝（密码错误） |
| 降级遗留登录 | ✅ | -1 用户不存在 | 存在且本地密码匹配 | 本地兜底登录（不广播） |
| UC 宕机 | ❌ | — | 存在且本地有密码哈希 | `fallback=local`：本地密码登录（不广播） |
| UC 宕机 | ❌ | — | 不存在/无本地哈希 | 拒绝 |

> `POST /api/v1/user/login` 支持 `uc=1` 参数强制走 UCenter 校验（`forceUc`），供前端在需要"仅 UC 账号"场景使用。

### ③ 本站改密 → 同步 UCenter

**触发条件**：`PUT /api/v1/user/password`（需登录，JWT `scope=user`）。

**流程**：
1. 校验原密码（本地哈希，防止误改/撞库）；
2. 调用 UCenter `edit(username, old_password, new_password, email)` —— UCenter 校验原密码并更新其密码；
3. 成功 → `uc_sync=1`；失败（返回负错误码如原密码错误 `-2`）→ 按 `UC_FALLBACK_WRITE`：`local` 时本地改密并标记 `uc_sync=2`，`strict` 时整体失败；
4. 本地更新 `password` 哈希与 `uc_sync/uc_sync_at`。

### ④ 本站改资料（邮箱）→ 同步 UCenter

**触发条件**：`PUT /api/v1/user/email`（需登录，请求体携带当前密码做确认）。

**流程**：
1. 校验当前密码（本地哈希）；
2. 调用 UCenter `edit(username, password, '', new_email)` —— 仅更新邮箱（新密码传空）；
3. 成功 → `uc_sync=1`；失败 → 按 `UC_FALLBACK_WRITE` 降级或整体失败；
4. 本地更新 `email` 与 `uc_sync/uc_sync_at`。

### ⑤ UCenter 用户登录本站 → 首次自动建号

**触发条件**：UCenter 已有用户通过本站登录接口登录（本地无该账号）。

**实现**：见策略②第 2 步 —— UCenter 校验成功后，`UcenterSyncService::bindOrCreate()` 自动 `INSERT users`：

- `username` / `email` 取自 UCenter 返回的 `username / email`；
- `password` = `password_hash(bin2hex(random_bytes(16)))`（随机不可登录，**密码权威永远在 UCenter**，本地降级路径不会误通过）；
- `uc_uid` = UC uid（唯一键防重），`uc_username` = UC username，`uc_sync=1`。

同名但不同 uid 的本地账号会被补绑定（先查 `uc_username` → `username`），保证不产生重复账号。

## 5. 本站接收 UCenter 通知（反向回调）

**入口**：`POST /api/uc`（`Route::any('api/uc', 'api.UcenterNotify/index')`，公开路由，签名在控制器内校验）。

UCenter 后台将本站应用接口 URL 填为 `.../api/uc` 后，UCenter 侧发生的操作会携带 `code`（authcode 加密后的 `action=xxx&...`）回调本站，控制器解密验签（`UcenterClient::authcode` + 密钥）后处理。

| action | 触发时机（UCenter 侧） | 本站处理 |
| --- | --- | --- |
| `test` | UCenter 后台测试应用 | 返回 `1`（连通性验证） |
| `synlogin` | 其他应用登录 | 写 60s 有效登录标记缓存，供 `/user/login` 衔接（无本地账号则忽略，等下次登录自动建号） |
| `synlogout` | 其他应用登出 | 清除本站登录标记缓存 |
| `updatepw` | UCenter 侧改密 | 清空本地 `password`（本地不可再直接校验，一律以 UC 为准），`uc_sync=1` |
| `renameuser` | UCenter 侧改名 | 同步本地 `username / uc_username` |
| `deleteuser` | UCenter 侧删除用户 | 本地账号置 `status=0`（禁用，不物理删除） |

约定：处理成功返回 `1`，失败返回 `0`（UCenter 协议约定）。任何动作都会先验签，验签失败直接返回 `0`。

## 6. 异常处理

### 6.1 UCenter 错误码映射（`ucErrorText`）

| 操作 | 错误码 | 含义 | 本站表现 |
| --- | --- | --- | --- |
| register | -1 | 用户名不合法 | 拒绝注册 |
| register | -2 | 包含不允许注册的词语 | 拒绝注册 |
| register | -3 | 用户名已存在 | 拒绝注册（以 UC 为准） |
| register | -4/-5 | Email 格式有误 / 不允许注册 | 拒绝注册 |
| register | -6 | 该 Email 已被注册 | 拒绝注册（以 UC 为准） |
| login | -1 | 用户不存在 | 有本地降级账号且本地密码匹配时兜底，否则拒绝 |
| login | -2 | 密码错误 | 拒绝登录 |
| login | -3 | 安全提问错误 | 拒绝登录 |
| edit | -1 | 用户不存在 | 改密/改邮箱失败（按降级策略处理） |
| edit | -2 | 原密码错误 | 失败（UC 校验原密码失败） |
| edit | -3 | 新密码不合法 | 失败 |
| edit | -4/-5/-6 | Email 格式/禁用/已被注册 | 失败 |
| edit | -7 | 没有做任何修改 | 视为成功旁路（本地正常更新） |

### 6.2 网络/签名异常（`UcenterException`）

- **登录**：`UC_FALLBACK_LOGIN=local` 降级本地密码校验；`strict` 拒绝并返回"UCenter 服务暂不可用"。
- **写操作（注册/改密/改邮箱）**：`UC_FALLBACK_WRITE=local` 本地继续并标记 `uc_sync=2`；`strict` 整体失败。
- **同步登录广播失败**：只记日志，**不影响本站登录**（广播是"尽力而为"）。
- **通知验签失败**：丢弃并返回 `0`，不影响本地数据。

### 6.3 待同步账号补偿（`uc_sync=2`）

UCenter 恢复后可通过 `POST /api/v1/user/sync/retry`（需登录）触发补同步：

- 本地有 `uc_uid` → 刷新为 `uc_sync=1`（密码无法回填，密码类待同步依赖 UC 侧最终权威，用户下次登录自愈）；
- 本地无 `uc_uid`（纯降级注册）→ 用 `register` 补写 UCenter，成功后回填 `uc_uid/uc_sync=1`；
- UC 不可达或 UC 拒绝（如用户名被占）→ 保持 `uc_sync=2` 并返回原因。

## 7. 新增/修改文件清单

| 文件 | 说明 |
| --- | --- |
| `config/ucenter.php` | UCenter 配置读取（全部来自 `.env`） |
| `app/service/UcenterClient.php` | UCenter 2.0 客户端：authcode 加解密、`api()` 协议封装、register/login/edit/synlogin/synlogout/delete、响应解析（自适应加密/XML/明文/序列化） |
| `app/exception/UcenterException.php` | UCenter 专用异常（connection/signature/unconfigured） |
| `app/service/UcenterSyncService.php` | 同步编排：五种策略 + 补同步 + 错误码映射 |
| `app/controller/api/User.php` | 前台用户接口：register/login/logout/password/email/me/sync/retry |
| `app/controller/api/UcenterNotify.php` | UC 通知回调入口（验签 + 6 种 action） |
| `app/common/UserContext.php` | 前台用户上下文（JWT `scope=user`） |
| `app/middleware/AuthUser.php` | 前台登录中间件（校验 `scope=user` + 查 `users` 表） |
| `route/app.php` | 新增 `api/v1/user/*` 与 `api/uc` 路由 |
| `app/service/TokenService.php` | `issue()` 增加 `scope` 参数（admin/user 区分，向后兼容） |
| `database/schema.mysql.sql` + SQLite 演示库 | `users` 表新增 `password/uc_uid/uc_username/uc_sync/uc_sync_at`（含 `uk_users_uc_uid` 唯一键） |
| `tools/mock-uc-server.php` | 本地联调用模拟 UCenter 服务器（见第 8 节） |

## 8. 本地联调（可选）

仓库内提供 `tools/mock-uc-server.php`（模拟 UCenter：register/login/edit/synlogin/synlogout/deleteuser + authcode 加解密，用户数据存储于 `tools/mock-uc-users.json`）：

```bash
php -S 127.0.0.1:8101 tools/mock-uc-server.php &
# .env 指向 mock：
#   UC_ENABLED=true  UC_API=http://127.0.0.1:8101  UC_KEY=test-uc-key-123456  UC_APPID=1
php -S 127.0.0.1:8000 -t public public/router.php &
# 完成验证后务必恢复 UC_ENABLED=false
```

已用该 mock 完成全链路验证：注册双写、登录广播、改密/改邮箱同步、UC 用户自动建号、通知回调（test/synlogin/updatepw/renameuser/deleteuser 均返回 1）、UC 宕机降级（注册 `uc_sync=2`、登录本地兜底、改密本地继续）全部按预期工作。

## 9. 注意事项

1. **UCenter 是密码权威源**：UC 校验成功后本地 `password` 仅作降级缓存；从 UC 侧改密会反向清空本地（`updatepw` 回调），本地再无法用旧密码登录。
2. **`/api/uc` 必须可被 UCenter 服务器访问**（勿放内网/加 IP 白名单时注意放行 UC 服务器 IP），否则 UC 侧操作无法回调。
3. 前端登录后必须把响应里的 `sync_html` 输出到页面（例如 `document.write(sync_html)`），否则关联站点同步登录不生效。
4. `UC_KEY` 与 UCenter 后台应用配置必须一致，否则回调验签失败、UC 间请求被拒。
5. 启用同步后迁移存量用户：本地已有账号首次登录时若用户名与 UC 一致会自动补绑定 `uc_uid`，无需手工迁移。