<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\BizException;
use think\facade\Db;

/**
 * 邀请链接/邮件邀请服务：生成带 token 邀请，支持限量/过期/群组绑定
 */
class InviteService
{
    public const STATUS = ['active', 'disabled', 'expired', 'used'];

    /**
     * 创建邀请
     * @return array invite 记录（含 link）
     */
    public static function create(array $data, int $adminId): array
    {
        $appId  = (int) ($data['app_id'] ?? 0);
        $email  = trim((string) ($data['email'] ?? ''));
        $app    = Db::name('apps')->where('id', $appId)->find();
        if (!$app) {
            throw BizException::notFound('应用不存在');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw BizException::param('邮箱格式不正确');
        }

        $groupId = (int) ($data['group_id'] ?? 0);
        if ($groupId > 0 && !Db::name('tester_groups')->where('id', $groupId)->find()) {
            throw BizException::param('测试群组不存在');
        }

        $now       = datetime_now();
        $maxUses   = (int) ($data['max_uses'] ?? 1);
        $expireDays= max(1, (int) ($data['expire_days'] ?? 7));
        $token     = self::generateToken();

        $id = Db::name('invites')->insertGetId([
            'app_id'      => $appId,
            'group_id'    => $groupId > 0 ? $groupId : null,
            'email'       => $email !== '' ? $email : null,
            'token'       => $token,
            'invite_type' => in_array((string) ($data['invite_type'] ?? 'test'), ['test', 'alpha'], true) ? (string) $data['invite_type'] : 'test',
            'max_uses'    => $maxUses < 0 ? -1 : $maxUses,
            'used_count'  => 0,
            'expire_at'   => date('Y-m-d H:i:s', strtotime('+' . $expireDays . ' days')),
            'status'      => 'active',
            'created_by'  => $adminId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // 邮件邀请（异步：仅记录日志，邮件通道同通知服务）
        if ($email !== '') {
            NotificationService::trigger('invite', [
                'app_name'    => $app['name'] ?? '',
                'email'       => $email,
                'token'       => $token,
                'link'        => self::inviteLink($token),
                'expire_at'   => date('Y-m-d H:i:s', strtotime('+' . $expireDays . ' days')),
            ]);
        }

        $invite = Db::name('invites')->where('id', $id)->find();
        $invite['link'] = self::inviteLink($token);
        OperationLogService::write($adminId, 'invite', 'create', (int) $id, '创建邀请 ' . ($email !== '' ? $email : '通用链接') . '（' . $app['name'] . '）');
        return $invite;
    }

    /**
     * 消费邀请：校验 token，返回可用的 app/group 信息并递增 used_count
     */
    public static function consume(string $token): array
    {
        if ($token === '') {
            throw BizException::param('邀请码不能为空');
        }
        $invite = Db::name('invites')->where('token', $token)->find();
        if (!$invite) {
            throw BizException::notFound('邀请链接无效');
        }
        if ($invite['status'] !== 'active') {
            throw BizException::failed('邀请链接状态为：' . $invite['status']);
        }
        if ($invite['expire_at'] !== null && strtotime((string) $invite['expire_at']) < time()) {
            Db::name('invites')->where('id', (int) $invite['id'])->update(['status' => 'expired', 'updated_at' => datetime_now()]);
            throw BizException::failed('邀请链接已过期');
        }
        if ((int) $invite['max_uses'] >= 0 && (int) $invite['used_count'] >= (int) $invite['max_uses']) {
            throw BizException::failed('邀请链接使用次数已达上限');
        }
        Db::name('invites')->where('id', (int) $invite['id'])->inc('used_count')->update();
        if ((int) $invite['max_uses'] >= 0 && (int) $invite['used_count'] + 1 >= (int) $invite['max_uses']) {
            Db::name('invites')->where('id', (int) $invite['id'])->update(['status' => 'used', 'updated_at' => datetime_now()]);
        }
        return $invite;
    }

    /**
     * 生成邀请链接（前台测试安装页，由前端读取公开接口拉起）
     */
    public static function inviteLink(string $token): string
    {
        return '/invite/' . $token;
    }

    protected static function generateToken(): string
    {
        return 'inv_' . bin2hex(random_bytes(24));
    }
}