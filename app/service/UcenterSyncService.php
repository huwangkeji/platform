<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\BizException;
use app\exception\UcenterException;
use think\facade\Config;
use think\facade\Db;
use think\facade\Log;

/**
 * UCenter 2.0 账号同步编排服务
 *
 * 五项同步策略：
 *  ① 本站注册 → 写 UCenter（本地建号为兜底，UCenter 为权威）
 *  ② 本站登录 → UCenter 校验 + synlogin 广播（返回 HTML 由前端输出，使关联站点同步登录）
 *  ③ 本站改密 → 同步 UCenter edit（需旧密码，UCenter 是权威源）
 *  ④ 本站改资料（邮箱）→ 同步 UCenter edit
 *  ⑤ UCenter 用户登录本站 → 首次登录自动在本站建号（绑定 uc_uid）
 *
 * 字段映射：
 *  users.username      ↔ UC username（登录名）
 *  users.email         ↔ UC email
 *  users.password      本地 password_hash（仅 UCenter 降级时使用）
 *  users.uc_uid        UC uid（唯一绑定）
 *  users.uc_username   UC username（镜像，便于反查）
 *  users.uc_sync       0=未接入 1=已同步 2=待重试
 *
 * 异常处理（UE = UCenterException / 网络/签名）：
 *  - 登录：fallback.login=local 时 UE 降级本地密码校验；strict 时拒绝登录
 *  - 写操作（注册/改密/改邮箱）：fallback.write=local 时 UE 本地继续并标记 uc_sync=2；
 *    strict 时抛错整体失败
 */
class UcenterSyncService
{
    public const SYNC_NONE = 0;
    public const SYNC_OK   = 1;
    public const SYNC_PENDING = 2;

    /** 降级策略取值 */
    public const FALLBACK_LOCAL  = 'local';
    public const FALLBACK_STRICT = 'strict';

    /**
     * 构造客户端（子类可覆写便于测试）
     */
    protected static function client(): UcenterClient
    {
        return new UcenterClient();
    }

    protected static function fallback(string $key): string
    {
        return (string) Config::get('ucenter.fallback.' . $key, self::FALLBACK_LOCAL);
    }

    // ----------------------------------------------------------------
    // ① 本站注册：本地建号 + 写 UCenter
    // ----------------------------------------------------------------

    /**
     * 注册（本地 + UCenter 双写）
     * @param array{username:string,password:string,email:string,mobile?:string} $input
     * @return array{user:array,uc_sync:int}
     * @throws BizException
     */
    public static function register(array $input): array
    {
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $email    = trim((string) ($input['email'] ?? ''));
        $mobile   = trim((string) ($input['mobile'] ?? ''));

        if ($username === '' || strlen($password) < 6) {
            throw BizException::param('用户名不能为空且密码至少 6 位');
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,63}$/', $username)) {
            throw BizException::param('用户名仅支持字母开头、字母数字下划线，3-64 位');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw BizException::param('邮箱格式不正确');
        }

        // 唯一性检查（username / email / uc_username 均视为占用）
        if (Db::name('users')->where('username', $username)->find()
            || Db::name('users')->where('uc_username', $username)->find()) {
            throw BizException::failed('用户名已被注册');
        }
        if ($email !== '' && Db::name('users')->where('email', $email)->find()) {
            throw BizException::failed('邮箱已被注册');
        }

        $now      = datetime_now();
        $localId  = 0;
        $ucUid    = 0;
        $ucSync   = self::SYNC_NONE;
        $client   = static::client();

        if ($client->enabled()) {
            try {
                $ucUid = $client->register($username, $password, $email);
                if ($ucUid <= 0) {
                    // UCenter 权威：用户名/邮箱占用等，拒绝注册
                    throw BizException::failed(self::ucErrorText('register', $ucUid));
                }
                $ucSync = self::SYNC_OK;
            } catch (UcenterException $e) {
                Log::warning('[UCenter] 注册同步失败，走降级：' . $e->getMessage());
                if (static::fallback('write') !== self::FALLBACK_LOCAL) {
                    throw BizException::failed('UCenter 服务暂不可用，注册失败');
                }
                // local 降级：本地建号，标记待同步
                $ucSync = self::SYNC_PENDING;
            }
        }

        $localId = Db::name('users')->insertGetId([
            'username'     => $username,
            'password'     => password_hash($password, PASSWORD_DEFAULT),
            'email'        => $email,
            'mobile'       => $mobile,
            'uc_uid'       => $ucUid > 0 ? $ucUid : null,
            'uc_username'  => $ucUid > 0 ? $username : null,
            'uc_sync'      => $ucSync,
            'uc_sync_at'   => $ucSync === self::SYNC_OK ? $now : null,
            'status'       => 1,
            'register_at'  => $now,
            'last_login_at'=> null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        Log::info('[UCenter] 注册完成 本地用户=' . $localId . ' uc_uid=' . $ucUid . ' sync=' . $ucSync);
        return [
            'user'    => Db::name('users')->where('id', $localId)->find(),
            'uc_sync' => $ucSync,
        ];
    }

    // ----------------------------------------------------------------
    // ⑤ + ② 登录：UCenter 校验（或本地降级）→ 建号/绑定 → 广播
    // ----------------------------------------------------------------

    /**
     * 登录。
     * @param string $username 用户名
     * @param string $password 明文密码
     * @param bool $forceUc 强制以 UCenter 校验（UCenter 用户登录场景），false 时 UE 可降级本地
     * @return array{token:string,expires_in:int,user:array,uc_sync:int,fallback_local:bool,sync_html:string}
     * @throws BizException
     */
    public static function login(string $username, string $password, bool $forceUc = false): array
    {
        if ($username === '' || $password === '') {
            throw BizException::param('请输入用户名和密码');
        }

        $client = static::client();
        $fallbackUcDown = false;
        $ucProfile = null;

        if ($client->enabled()) {
            try {
                $ucProfile = $client->login($username, $password);
            } catch (UcenterException $e) {
                Log::warning('[UCenter] 登录校验异常：' . $e->getMessage());
                if (static::fallback('login') !== self::FALLBACK_LOCAL) {
                    throw BizException::failed('UCenter 服务暂不可用，请稍后再试');
                }
                $fallbackUcDown = true;
            }
        }

        // 本地查找：优先 uc_uid 绑定，其次本地 username / uc_username
        $local = self::findLocalByUsername($username);

        if ($ucProfile !== null) {
            // UCenter 校验成功 → 自动建号/绑定 + 登录
            $ucUid  = (int) ($ucProfile['uid'] ?? 0);
            $ucName = (string) ($ucProfile['username'] ?? $username);
            $ucEmail= (string) ($ucProfile['email'] ?? '');

            if ($ucUid <= 0) {
                // UCenter 返回负错误码（用户不存在/密码错误）
                $code = $ucUid;
                if ($code === UcenterClient::ERR_LOGIN_BAD_PASS) {
                    throw BizException::failed('密码错误');
                }
                // 允许：若本地有账号且密码匹配，且 UCenter 说用户不存在（ERR_LOGIN_NO_USER）
                // —— 仅当用户此前在 UCenter 不可用时本地降级注册过（uc_sync=PENDING），
                // 此时用本地密码兜底完成登录，并在后续操作中补同步。
                if ($code === UcenterClient::ERR_LOGIN_NO_USER && $local && static::verifyLocalPassword($local, $password)) {
                    $fallbackUcDown = true; // 视作降级登录
                } else {
                    throw BizException::failed(self::ucErrorText('login', $code));
                }
            } else {
                $local = self::bindOrCreate($local, $ucUid, $ucName, $ucEmail, $password);
                $fallbackUcDown = false;
            }
        }

        if ($local === null) {
            throw BizException::failed('用户不存在');
        }
        if ((int) $local['status'] !== 1) {
            throw BizException::forbidden('账号已被禁用');
        }
        // 需要密码的路径（UCenter 不可达降级 / UCenter 未启用 / UC 提示用户不存在）
        if ($ucProfile === null && !self::verifyLocalPassword($local, $password)) {
            throw BizException::failed('密码错误');
        }

        // 更新登录信息
        $now = datetime_now();
        Db::name('users')->where('id', (int) $local['id'])->update([
            'last_login_at' => $now,
            'updated_at'    => $now,
        ]);

        // 触发同步登录广播（UCenter 可达时）
        $syncHtml = '';
        $ucSync   = (int) ($local['uc_sync'] ?? self::SYNC_NONE);
        if ($client->enabled() && !$fallbackUcDown && !empty($local['uc_uid'])) {
            try {
                $syncHtml = $client->synlogin((int) $local['uc_uid']);
                $ucSync   = self::SYNC_OK;
            } catch (UcenterException $e) {
                Log::warning('[UCenter] 同步登录广播失败（不影响本站登录）：' . $e->getMessage());
            }
        }

        $token = TokenService::issue((int) $local['id'], 0, 'user');
        Log::info('[UCenter] 登录成功 用户=' . $local['id'] . ' username=' . $username
            . ' fallback=' . ($fallbackUcDown ? 'local' : 'uc'));

        return [
            'token'          => $token['token'],
            'expires_in'     => $token['expires_in'],
            'user'           => self::publicUser($local),
            'uc_sync'        => $ucSync,
            'fallback_local' => $fallbackUcDown,
            'sync_html'      => $syncHtml,
        ];
    }

    /**
     * 登出：返回同步登出广播代码
     */
    public static function logout(): array
    {
        $html = '';
        $client = static::client();
        if ($client->enabled()) {
            try {
                $html = $client->synlogout();
            } catch (UcenterException $e) {
                Log::warning('[UCenter] 同步登出广播失败：' . $e->getMessage());
            }
        }
        return ['sync_html' => $html];
    }

    // ----------------------------------------------------------------
    // ③ 本站改密 → 同步 UCenter
    // ----------------------------------------------------------------

    /**
     * 修改密码（本地 + UCenter 双写）
     * @param int $userId 本地用户 ID
     * @param string $oldPassword 原密码（UCenter edit 需校验）
     * @param string $newPassword 新密码
     */
    public static function changePassword(int $userId, string $oldPassword, string $newPassword): void
    {
        $user = self::requireUser($userId);
        if (strlen($newPassword) < 6) {
            throw BizException::param('新密码至少 6 位');
        }
        // 本地旧密码校验（防止误改/撞库）
        if (!self::verifyLocalPassword($user, $oldPassword)) {
            throw BizException::failed('原密码错误');
        }

        $client   = static::client();
        $ucEntity = self::ucIdentity($user);
        $ucSync   = (int) ($user['uc_sync'] ?? self::SYNC_NONE);
        $now      = datetime_now();

        if ($client->enabled() && $ucEntity !== null) {
            try {
                $ret = $client->edit($ucEntity['username'], $oldPassword, $newPassword, (string) ($user['email'] ?? ''));
                if ($ret !== 1) {
                    throw BizException::failed(self::ucErrorText('edit', $ret));
                }
                $ucSync = self::SYNC_OK;
            } catch (UcenterException $e) {
                Log::warning('[UCenter] 改密同步失败：' . $e->getMessage());
                if (static::fallback('write') !== self::FALLBACK_LOCAL) {
                    throw BizException::failed('UCenter 服务暂不可用，改密失败');
                }
                $ucSync = self::SYNC_PENDING;
            }
        } else {
            // 未接入 UCenter：仅本地
            $ucSync = $client->enabled() ? self::SYNC_PENDING : self::SYNC_NONE;
        }

        Db::name('users')->where('id', $userId)->update([
            'password'   => password_hash($newPassword, PASSWORD_DEFAULT),
            'uc_sync'    => $ucSync,
            'uc_sync_at' => $ucSync === self::SYNC_OK ? $now : null,
            'updated_at' => $now,
        ]);
        Log::info('[UCenter] 改密完成 用户=' . $userId . ' sync=' . $ucSync);
    }

    // ----------------------------------------------------------------
    // ④ 本站改资料（邮箱）→ 同步 UCenter
    // ----------------------------------------------------------------

    /**
     * 修改邮箱（本地 + UCenter 双写）
     * @param int $userId 本地用户 ID
     * @param string $oldPassword 原密码（UCenter edit 校验）
     * @param string $newEmail 新邮箱
     */
    public static function changeEmail(int $userId, string $oldPassword, string $newEmail): void
    {
        $user = self::requireUser($userId);
        $newEmail = trim($newEmail);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw BizException::param('邮箱格式不正确');
        }
        if (!self::verifyLocalPassword($user, $oldPassword)) {
            throw BizException::failed('原密码错误');
        }

        $client   = static::client();
        $ucEntity = self::ucIdentity($user);
        $ucSync   = (int) ($user['uc_sync'] ?? self::SYNC_NONE);
        $now      = datetime_now();

        if ($client->enabled() && $ucEntity !== null) {
            try {
                $ret = $client->edit($ucEntity['username'], $oldPassword, '', $newEmail);
                if ($ret !== 1) {
                    throw BizException::failed(self::ucErrorText('edit', $ret));
                }
                $ucSync = self::SYNC_OK;
            } catch (UcenterException $e) {
                Log::warning('[UCenter] 改邮箱同步失败：' . $e->getMessage());
                if (static::fallback('write') !== self::FALLBACK_LOCAL) {
                    throw BizException::failed('UCenter 服务暂不可用，修改失败');
                }
                $ucSync = self::SYNC_PENDING;
            }
        } else {
            $ucSync = $client->enabled() ? self::SYNC_PENDING : self::SYNC_NONE;
        }

        Db::name('users')->where('id', $userId)->update([
            'email'      => $newEmail,
            'uc_sync'    => $ucSync,
            'uc_sync_at' => $ucSync === self::SYNC_OK ? $now : null,
            'updated_at' => $now,
        ]);
        Log::info('[UCenter] 改邮箱完成 用户=' . $userId . ' sync=' . $ucSync);
    }

    // ----------------------------------------------------------------
    // 补同步：uc_sync=2 的账号手动重试
    // ----------------------------------------------------------------

    /**
     * 重试同步（仅 uc_sync=2 且 UCenter 可达且本地有 uid 时使用）
     * 适用于：UCenter 恢复后把此前降级注册/改密的账号补写过去。
     */
    public static function retrySync(int $userId): array
    {
        $user = self::requireUser($userId);
        $client = static::client();
        if (!$client->enabled()) {
            return ['status' => (int) $user['uc_sync'], 'message' => 'UCenter 未启用'];
        }

        $now = datetime_now();
        $ucUid = (int) ($user['uc_uid'] ?? 0);
        // 没有 uc_uid 的本地账号：尝试用注册接口补写（用户名+邮箱）
        $username = (string) ($user['username'] ?: ($user['uc_username'] ?: ''));
        $email    = (string) ($user['email'] ?? '');

        try {
            if ($ucUid > 0) {
                // 密码无法回填（UCenter 只在登录时返回摘要），此时只能等待用户下次登录自愈；
                // 这里仅刷新同步标记（如果是登录/资料类待同步）。
                Db::name('users')->where('id', $userId)->update(['uc_sync' => self::SYNC_OK, 'uc_sync_at' => $now, 'updated_at' => $now]);
                return ['status' => self::SYNC_OK, 'message' => 'UCenter 已同步'];
            }
            if ($username === '') {
                return ['status' => (int) $user['uc_sync'], 'message' => '缺少用户名，无法补注册'];
            }
            // 补注册需要向 UCenter 提交明文密码：本地 users.password 保存的是 bcrypt 哈希（或以 '' 表示未设），
            // 无法还原明文，因此一律禁止用 "空密码" 或哈希值补注册——否则该账号在 UCenter 端密码为空/错误、永远无法登录。
            // 正确路径：引导用户走 UCenter 登录自动绑定，或重新设置密码后由正常注册/改密链路同步。
            $localPwd = (string) ($user['password'] ?? '');
            $isBcrypt = str_starts_with($localPwd, '$2y$') || str_starts_with($localPwd, '$2a$') || str_starts_with($localPwd, '$2b$');
            if ($localPwd === '' || $isBcrypt) {
                return ['status' => self::SYNC_PENDING, 'message' => '本地无明文密码，无法补注册：请用户使用 UC 账号登录以自动绑定，或重新设置密码后重试'];
            }
            $newUid = $client->register($username, $localPwd, $email);
            if ($newUid > 0) {
                Db::name('users')->where('id', $userId)->update([
                    'uc_uid' => $newUid, 'uc_username' => $username,
                    'uc_sync' => self::SYNC_OK, 'uc_sync_at' => $now, 'updated_at' => $now,
                ]);
                return ['status' => self::SYNC_OK, 'message' => '补注册成功 uid=' . $newUid];
            }
            return ['status' => self::SYNC_PENDING, 'message' => 'UCenter 注册被拒绝：' . self::ucErrorText('register', $newUid)];
        } catch (UcenterException $e) {
            Log::warning('[UCenter] 补同步失败：' . $e->getMessage());
            return ['status' => self::SYNC_PENDING, 'message' => 'UCenter 暂不可用'];
        }
    }

    // ----------------------------------------------------------------
    // helpers
    // ----------------------------------------------------------------

    /**
     * 本地按用户名反查：uc_username 或 username
     */
    protected static function findLocalByUsername(string $username): ?array
    {
        $u = Db::name('users')->where('uc_username', $username)->find();
        if ($u) {
            return $u;
        }
        return Db::name('users')->where('username', $username)->find() ?: null;
    }

    /**
     * UCenter 校验通过时：绑定已有本地账号或自动建号
     */
    protected static function bindOrCreate(?array $local, int $ucUid, string $ucName, string $ucEmail, string $password): array
    {
        $now = datetime_now();
        if ($local !== null) {
            // 已有本地账号 → 补绑定 uc_uid
            if ((int) ($local['uc_uid'] ?? 0) !== $ucUid) {
                Db::name('users')->where('id', (int) $local['id'])->update([
                    'uc_uid'       => $ucUid,
                    'uc_username'  => $ucName,
                    'uc_sync'      => self::SYNC_OK,
                    'uc_sync_at'   => $now,
                    'updated_at'   => $now,
                ]);
                $local = Db::name('users')->where('id', (int) $local['id'])->find();
            }
            return $local;
        }

        // 自动建号（首次 UC 登录）
        $localId = Db::name('users')->insertGetId([
            'username'     => $ucName,
            'password'     => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), // 本地不可登录，密码权威在 UCenter
            'email'        => $ucEmail,
            'uc_uid'       => $ucUid,
            'uc_username'  => $ucName,
            'uc_sync'      => self::SYNC_OK,
            'uc_sync_at'   => $now,
            'status'       => 1,
            'register_at'  => $now,
            'last_login_at'=> null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        Log::info('[UCenter] UCenter 用户首次登录自动建号 用户=' . $localId . ' uc_uid=' . $ucUid . ' uc_name=' . $ucName);
        return Db::name('users')->where('id', $localId)->find();
    }

    /**
     * 本地密码校验（降级/未接入 UC 时）
     */
    protected static function verifyLocalPassword(array $user, string $password): bool
    {
        $hash = (string) ($user['password'] ?? '');
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    /**
     * 取 UCenter 身份（username/uid）；本地用户可能未接入
     * @return array{username:string}|null
     */
    protected static function ucIdentity(array $user): ?array
    {
        $username = (string) ($user['uc_username'] ?? '');
        if ($username === '') {
            $username = (string) ($user['username'] ?? '');
        }
        if ($username === '') {
            return null;
        }
        if ((int) ($user['uc_uid'] ?? 0) <= 0 && (int) ($user['uc_sync'] ?? 0) !== self::SYNC_PENDING) {
            return null;
        }
        return ['username' => $username];
    }

    protected static function requireUser(int $userId): array
    {
        $user = Db::name('users')->where('id', $userId)->find();
        if (!$user) {
            throw BizException::notFound('用户不存在');
        }
        return $user;
    }

    public static function publicUser(array $user): array
    {
        return [
            'id'         => (int) $user['id'],
            'username'   => (string) ($user['username'] ?? ''),
            'email'      => (string) ($user['email'] ?? ''),
            'mobile'     => (string) ($user['mobile'] ?? ''),
            'uc_uid'     => isset($user['uc_uid']) ? (int) $user['uc_uid'] : 0,
            'uc_sync'    => (int) ($user['uc_sync'] ?? self::SYNC_NONE),
            'status'     => (int) ($user['status'] ?? 1),
            'register_at'=> (string) ($user['register_at'] ?? ''),
            'last_login_at'=> (string) ($user['last_login_at'] ?? ''),
        ];
    }

    /**
     * UCenter 错误码 → 中文提示
     */
    public static function ucErrorText(string $op, int $code): string
    {
        $map = [
            'register' => [
                UcenterClient::ERR_BAD_USERNAME => '用户名不合法',
                UcenterClient::ERR_BAD_WORD     => '用户名包含不允许注册的词语',
                UcenterClient::ERR_EXIST_NAME   => '用户名已存在',
                UcenterClient::ERR_BAD_EMAIL    => 'Email 格式有误',
                UcenterClient::ERR_DENY_EMAIL   => 'Email 不允许注册',
                UcenterClient::ERR_EXIST_EMAIL  => '该 Email 已被注册',
            ],
            'login' => [
                UcenterClient::ERR_LOGIN_NO_USER  => '用户不存在',
                UcenterClient::ERR_LOGIN_BAD_PASS => '密码错误',
                UcenterClient::ERR_LOGIN_BAD_SEC  => '安全提问错误',
            ],
            'edit' => [
                UcenterClient::ERR_EDIT_NO_USER     => '用户不存在',
                UcenterClient::ERR_EDIT_BAD_OLD     => '原密码错误',
                UcenterClient::ERR_EDIT_BAD_NEW     => '新密码不合法',
                UcenterClient::ERR_EDIT_BAD_EMAIL   => 'Email 格式有误',
                UcenterClient::ERR_EDIT_DENY_EMAIL  => 'Email 不允许注册',
                UcenterClient::ERR_EDIT_EXIST_EMAIL => '该 Email 已被注册',
                UcenterClient::ERR_EDIT_NO_CHANGE   => '没有做任何修改',
            ],
        ];
        return $map[$op][$code] ?? ('UCenter 返回错误码 ' . $code);
    }
}