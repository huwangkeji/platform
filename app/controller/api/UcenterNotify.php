<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\UcenterClient;
use app\service\UcenterSyncService;
use think\facade\Db;
use think\facade\Log;
use think\Response;

/**
 * UCenter 服务器 → 本站 通知入口
 *
 * 这是 UCenter 后台"应用接口 URL"填写的地址（形如 /api/uc），
 * 当 UCenter 侧发生登录/登出/改密/改名/删除等操作时，UCenter 服务器
 * 会携带 code（authcode 加密）回调本站，使两端账号状态保持一致。
 *
 * 支持动作（action）：
 *  - test       连通性测试（UCenter 后台测试应用）
 *  - synlogin   在其他应用登录 → 标记本站用户登录态
 *  - synlogout  退出登录 → 清除本站登录态
 *  - updatepw   UCenter 侧改密 → 本站改为依赖 UCenter 校验（清空本地可登录口令）
 *  - renameuser UCenter 侧改名 → 同步本地 username/uc_username
 *  - deleteuser UCenter 侧删除 → 本地账号禁用
 *
 * 约定：任何动作处理成功返回 1，失败返回 0（UCenter 协议）。
 */
class UcenterNotify extends BaseController
{
    public function index(): Response
    {
        try {
            $client = new UcenterClient();
            if (!$client->configured()) {
                Log::warning('[UCenter] 收到通知但 UCenter 未配置，拒绝处理');
                return response('0');
            }

            $code = (string) ($this->request->param('code', ''));
            if ($code === '') {
                return response('0');
            }

            // 兼容：部分 UCenter 版本对 code 进行 urlencode（PHP 已自动解码一次，
            // 仅在仍含 % 时才做二次解码，避免把 + 错误还原为空格导致验签失败）
            if (str_contains($code, '%')) {
                $code = urldecode($code);
            }
            // 解密并强制过期校验（86400s）：通知动作含改密/改名/删除等敏感操作，
            // 若不过期，被截获的 code 可被无限重放，导致账号被反复改名/禁用（重放攻击）。
            // 传 86400 兼容 UCenter 1.x 默认 60s 有效期，同时拒绝超过一天的历史通知。
            $plain = $client->authcode($code, 'DECODE', 86400);
            if ($plain === '') {
                Log::warning('[UCenter] 通知签名校验失败');
                return response('0');
            }

            parse_str($plain, $get);
            $action = (string) ($get['action'] ?? '');
            Log::info('[UCenter] 收到通知 action=' . $action . ' params=' . json_encode($get, JSON_UNESCAPED_UNICODE));

            $ok = match ($action) {
                'test'        => true,
                'synlogin'    => $this->handleSynlogin($get),
                'synlogout'   => $this->handleSynlogout($get),
                'updatepw'    => $this->handleUpdatePw($get),
                'renameuser'  => $this->handleRenameUser($get),
                'deleteuser'  => $this->handleDeleteUser($get),
                default       => false,
            };

            return response($ok ? '1' : '0');
        } catch (\Throwable $e) {
            Log::error('[UCenter] 通知处理异常：' . $e->getMessage());
            return response('0');
        }
    }

    /**
     * 同步登录：UCenter 在其他应用登录后广播，本站标记登录态（写缓存，供 login 校验参考）
     * UCenter 参数：uid、username（可能有 password/email）
     */
    protected function handleSynlogin(array $get): bool
    {
        $uid = (int) ($get['uid'] ?? 0);
        $username = (string) ($get['username'] ?? '');
        if ($uid <= 0 && $username === '') {
            return false;
        }
        // 按 uid/用户名找到本地账号，记录 UC 登录标记（60s 有效，供 /user/login?uc=1 衔接）
        $local = $uid > 0
            ? Db::name('users')->where('uc_uid', $uid)->find()
            : Db::name('users')->where('uc_username', $username)->find();
        if (!$local) {
            // 首次在 UCenter 登录但本站还没有账号：等待下一次登录接口自动建号即可
            return true;
        }
        cache('uc_synlogin_' . (int) $local['id'], datetime_now(), 60);
        return true;
    }

    /**
     * 同步登出：清除本站登录态标记
     */
    protected function handleSynlogout(array $get): bool
    {
        $uid = (int) ($get['uid'] ?? 0);
        $username = (string) ($get['username'] ?? '');
        $local = null;
        if ($uid > 0) {
            $local = Db::name('users')->where('uc_uid', $uid)->find();
        } elseif ($username !== '') {
            $local = Db::name('users')->where('uc_username', $username)->find();
        }
        if ($local) {
            cache('uc_synlogin_' . (int) $local['id'], null);
        }
        return true;
    }

    /**
     * UCenter 侧改密：本地口令不可再直接校验（置空本地哈希），
     * 后续登录一律以 UCenter 为准；uc_sync 标记为已同步。
     */
    protected function handleUpdatePw(array $get): bool
    {
        $username = (string) ($get['username'] ?? '');
        if ($username === '') {
            return false;
        }
        $local = Db::name('users')->where('uc_username', $username)->find()
            ?: Db::name('users')->where('username', $username)->find();
        if (!$local) {
            return true; // 本站无此用户则无操作，视为成功
        }
        Db::name('users')->where('id', (int) $local['id'])->update([
            'password'   => null,
            'uc_sync'    => UcenterSyncService::SYNC_OK,
            'uc_sync_at' => datetime_now(),
            'updated_at' => datetime_now(),
        ]);
        return true;
    }

    /**
     * UCenter 侧改名：同步本地 username/uc_username
     * UCenter 参数：uid、oldusername、newusername
     */
    protected function handleRenameUser(array $get): bool
    {
        $uid = (int) ($get['uid'] ?? 0);
        $newUsername = (string) ($get['newusername'] ?? '');
        if ($uid <= 0 || $newUsername === '') {
            return false;
        }
        $local = Db::name('users')->where('uc_uid', $uid)->find();
        if (!$local) {
            return true;
        }
        Db::name('users')->where('id', (int) $local['id'])->update([
            'uc_username' => $newUsername,
            'username'    => $newUsername,
            'updated_at'  => datetime_now(),
        ]);
        return true;
    }

    /**
     * UCenter 侧删除用户：本地禁用
     * UCenter 参数：ids（逗号分隔的 uid 列表）
     */
    protected function handleDeleteUser(array $get): bool
    {
        $ids = (string) ($get['ids'] ?? '');
        if ($ids === '') {
            return false;
        }
        $uidList = array_filter(array_map('intval', explode(',', $ids)));
        if ($uidList) {
            Db::name('users')->whereIn('uc_uid', $uidList)->update([
                'status'     => 0,
                'updated_at' => datetime_now(),
            ]);
        }
        return true;
    }
}