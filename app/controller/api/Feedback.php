<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\UploadService;
use think\facade\Db;
use think\response\Json;

/**
 * 用户反馈（APP 端公开）
 */
class Feedback extends BaseController
{
    private const TYPES = ['bug', 'feature', 'suggestion', 'complaint', 'other'];

    /**
     * 提交反馈（自动生成工单）
     * POST /api/v1/feedback/create
     */
    public function create(): Json
    {
        $appCode = trim((string) $this->request->post('app_code', ''));
        if ($appCode === '') {
            return fail(10002, 'app_code 不能为空');
        }
        $app = $this->ensureApp($appCode);

        $type    = (string) $this->request->post('type', 'other');
        $title   = trim((string) $this->request->post('title', ''));
        $content = trim((string) $this->request->post('content', ''));

        if (!in_array($type, self::TYPES, true)) {
            return fail(10003, '反馈类型不合法');
        }
        if ($title === '') {
            return fail(10003, '标题不能为空');
        }
        if ($content === '') {
            return fail(10003, '内容不能为空');
        }

        $deviceIdStr = (string) $this->request->post('device_id', '');
        $device      = null;
        if ($deviceIdStr !== '') {
            $device = Db::name('devices')->where('device_id', $deviceIdStr)->find();
        }

        $now = datetime_now();
        $fbUser = $device && (int) ($device['user_id'] ?? 0) > 0 ? (int) $device['user_id'] : null;
        $fbId = Db::name('feedbacks')->insertGetId([
            'app_id'        => (int) $app['id'],
            'user_id'       => $fbUser,
            'device_id'     => $device ? (int) $device['id'] : null,
            'type'          => $type,
            'title'         => $title,
            'content'       => $content,
            'contact'       => (string) $this->request->post('contact', ''),
            'images'        => (string) $this->request->post('images', ''),
            'attachments'   => (string) $this->request->post('attachments', ''),
            'app_version'   => (string) $this->request->post('app_version', ''),
            'os_version'    => (string) $this->request->post('os_version', ''),
            'device_model'  => (string) $this->request->post('device_model', ''),
            'channel_code'  => (string) $this->request->post('channel', ''),
            'status'        => 'pending',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // 自动生成工单
        $ticketNo = sprintf('BUG-%s-%03d', date('Ymd'), $fbId);
        Db::name('tickets')->insert([
            'ticket_no'    => $ticketNo,
            'app_id'       => (int) $app['id'],
            'feedback_id'  => $fbId,
            'title'        => $title,
            'content'      => $content,
            'priority'     => $type === 'bug' ? 'high' : 'normal',
            'status'       => 'pending',
            'assignee_id'  => null,
            'solution'     => '',
            'fixed_version_id' => null,
            'closed_at'    => null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return success(['feedback_id' => $fbId, 'ticket_no' => $ticketNo], '反馈提交成功');
    }

    /**
     * 上传反馈附件
     * POST /api/v1/feedback/upload  (multipart: file)
     */
    public function upload(): Json
    {
        $file = $this->request->file('file');
        $info = UploadService::save($file, 'feedback', 50 * 1024 * 1024, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'zip', 'rar', '7z', 'txt', 'log']);
        return success($info, '上传成功');
    }
}