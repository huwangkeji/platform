<?php
declare (strict_types = 1);

namespace app\controller\admin;

use app\BaseController;
use app\exception\BizException;
use app\service\ReleaseService;
use app\service\SignatureService;
use app\service\UploadService;
use think\facade\Config;
use think\facade\Db;
use think\response\Json;

/**
 * 版本管理：上传/编辑/发布/暂停/回滚
 */
class Versions extends BaseController
{
    private const PLATFORMS = ['android', 'ios', 'windows', 'macos', 'linux', 'harmonyos', 'other'];
    private const TYPES     = ['release', 'test', 'beta', 'rc', 'internal'];
    private const PLATFORM_EXT = [
        'android'  => ['apk'],
        'ios'      => ['ipa', 'zip'],
        'windows'  => ['exe', 'zip'],
        'macos'    => ['dmg', 'zip'],
        'linux'    => ['appimage', 'bin', 'gz', 'tar', 'zip'],
        'harmonyos'=> ['hap', 'app'],
        'other'    => ['zip', 'bin', 'apk', 'exe', 'dmg'],
    ];

    /**
     * 版本列表
     */
    public function index(): Json
    {
        $page     = max(1, (int) $this->request->get('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->get('page_size', 20)));
        $appId    = (int) $this->request->param('id', 0) ?: (int) $this->request->get('app_id', 0);
        $keyword  = trim((string) $this->request->get('keyword', ''));
        $status   = (string) $this->request->get('status', '');
        $platform = (string) $this->request->get('platform', '');

        $query = Db::name('app_versions');
        if ($appId > 0) {
            $query->where('app_id', $appId);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('version_name', '%' . $keyword . '%')
                    ->whereOr('release_title', 'like', '%' . $keyword . '%')
                    ->whereOr('release_note', 'like', '%' . $keyword . '%');
            });
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($platform !== '') {
            $query->where('platform', $platform);
        }

        $total = $query->count();
        $list  = $query->order('id', 'desc')->limit(($page - 1) * $pageSize, $pageSize)->select()->toArray();

        $appNames = Db::name('apps')->column('name', 'id');
        foreach ($list as &$v) {
            $v['app_name']     = $appNames[$v['app_id']] ?? '';
            $v['file_exists']  = $this->fileExists($v);
            $lastTask = Db::name('release_tasks')->where('version_id', (int) $v['id'])->order('id', 'desc')->find();
            $v['last_release'] = $lastTask ? ['id' => (int) $lastTask['id'], 'release_type' => $lastTask['release_type'], 'percent' => (int) $lastTask['rollout_percent'], 'status' => $lastTask['status']] : null;
        }
        unset($v);

        return success(page_result((array) $list, (int) $total, $page, $pageSize));
    }

    /**
     * 版本详情
     */
    public function read(): Json
    {
        $v = $this->versionRow();
        $app = Db::name('apps')->where('id', (int) $v['app_id'])->find();
        $v['app_name'] = $app ? $app['name'] : '';
        $v['file_exists'] = $this->fileExists($v);
        $v['release_tasks'] = Db::name('release_tasks')->where('version_id', (int) $v['id'])->order('id', 'desc')->select()->toArray();
        return success($v);
    }

    /**
     * 创建版本（JSON，无文件；随后可 upload 补包）
     * POST /api/v1/admin/versions
     */
    public function create(): Json
    {
        $appId     = (int) $this->request->post('app_id', 0);
        $verName   = trim((string) $this->request->post('version_name', ''));
        $verCode   = (int) $this->request->post('version_code', 0);
        $platform  = strtolower((string) $this->request->post('platform', 'android'));

        if ($appId <= 0 || $verName === '' || $verCode <= 0) {
            return fail(10003, 'app_id/version_name/version_code 不能为空');
        }
        if (!in_array($platform, self::PLATFORMS, true)) {
            return fail(10003, '平台类型不合法');
        }
        $app = Db::name('apps')->where('id', $appId)->find();
        if (!$app) {
            return fail(10004, '应用不存在');
        }
        if ($this->existsVersion($appId, $platform, $verCode)) {
            return fail(10005, '该应用在此平台已存在相同 version_code 的版本');
        }
        $type = (string) $this->request->post('version_type', 'release');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'release';
        }

        $now = datetime_now();
        $id  = Db::name('app_versions')->insertGetId([
            'app_id'               => $appId,
            'version_name'         => $verName,
            'version_code'         => $verCode,
            'version_type'         => $type,
            'release_title'        => (string) $this->request->post('release_title', ''),
            'release_note'         => (string) $this->request->post('release_note', ''),
            'platform'             => $platform,
            'os_version'           => (string) $this->request->post('os_version', ''),
            'architecture'         => (string) $this->request->post('architecture', ''),
            'file_name'            => '',
            'file_path'            => '',
            'file_size'            => 0,
            'md5'                  => '',
            'sha1'                 => '',
            'sha256'               => '',
            'status'               => 'draft',
            'is_force_update'      => (int) $this->request->post('is_force_update', 0) === 1 ? 1 : 0,
            'access_type'          => $this->validAccessType((string) $this->request->post('access_type', 'public')),
            'access_password'      => (string) $this->request->post('access_password', '') !== '' ? (string) $this->request->post('access_password') : null,
            'signer_fingerprint'   => (string) $this->request->post('signer_fingerprint', '') !== '' ? (string) $this->request->post('signer_fingerprint') : null,
            'signature_verified'   => (string) $this->request->post('signer_fingerprint', '') !== '' ? SignatureService::VERIFIED_MANUAL : SignatureService::VERIFIED_NONE,
            'minimum_version_code' => (int) $this->request->post('minimum_version_code', 0) ?: null,
            'created_by'           => $this->adminId(),
            'published_at'         => null,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        $this->opLog('version', 'create', $id, '创建版本 ' . $verName . ' (' . $platform . ')');
        return success(['id' => $id], '创建成功');
    }

    /**
     * 上传安装包并创建/更新版本（multipart: package_file + 版本元数据）
     * POST /api/v1/admin/apps/:id/upload
     */
    public function upload(): Json
    {
                $appId    = (int) $this->request->param('id');
        $app      = Db::name('apps')->where('id', $appId)->find();
        if (!$app) {
            throw BizException::notFound('应用不存在');
        }

        $verName  = trim((string) $this->request->post('version_name', ''));
        $verCode  = (int) $this->request->post('version_code', 0);
        $platform = strtolower((string) $this->request->post('platform', 'android'));
        if ($verName === '' || $verCode <= 0) {
            return fail(10003, 'version_name/version_code 不能为空');
        }
        if (!in_array($platform, self::PLATFORMS, true)) {
            return fail(10003, '平台类型不合法');
        }

        $file = $this->request->file('package_file');
        if (!$file || !$file->isValid()) {
            return fail(10003, '未接收到安装包文件');
        }

        $ext = strtolower((string) $file->extension());
        $allowed = self::PLATFORM_EXT[$platform] ?? self::PLATFORM_EXT['other'];
        if (!in_array($ext, $allowed, true)) {
            return fail(10003, '平台 ' . $platform . ' 不支持文件类型 .' . $ext . '（允许：' . implode('/', $allowed) . '）');
        }
        if (in_array($ext, ['php', 'php3', 'php5', 'phtml', 'pht', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh'], true)) {
            return fail(10003, '该文件类型不允许上传');
        }
        $maxSize = (int) Config::get('app.upload_max_size', 1073741824);
        if ($file->getSize() > $maxSize) {
            return fail(10003, '文件大小超过限制');
        }

        // 存储到 storage/apps/{code}/{verSafe}-{verCode}/app.{ext}（文档目录结构）
        // 目录名强制附加 version_code 保证唯一：中文版本名被清洗后可能为空或重名，
        // 若不唯一会导致不同版本的安装包互相覆盖、下载拿到错误文件。
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $app['code']);
        $verSafe = preg_replace('/[^\w.\-]/', '', $verName);
        $verDir  = (($verSafe !== '') ? $verSafe : ('v' . $verCode)) . '-' . $verCode;
        $dir  = $root . '/apps/' . $code . '/' . $verDir;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return fail(10006, '存储目录创建失败');
        }
        $file->move($dir, 'app.' . $ext);
        $abs = $dir . '/app.' . $ext;
        if (!is_file($abs)) {
            return fail(10006, '安装包保存失败');
        }
        $rel    = 'apps/' . $code . '/' . $verDir . '/app.' . $ext;
        $info   = UploadService::inspect($abs);
        $now    = datetime_now();

        // 签名验证（Phase 2-6）：提取证书指纹；declared 指纹可选，便于 v1 签名无工具时人工核验
        $sigDeclared = trim((string) $this->request->post('signer_fingerprint', ''));
        $sig = SignatureService::extract($abs, $platform, $sigDeclared);
        $signatureFields = [
            'signer_fingerprint'      => $sig['fingerprint'] ?: null,
            'signature_verified'      => $sig['verified'],
            'signature_verified_at'   => $sig['verified'] > 0 ? $now : null,
        ];

        // 若已存在同 code 版本则更新文件信息，否则创建
        $exist = Db::name('app_versions')
            ->where('app_id', $appId)
            ->where('platform', $platform)
            ->where('version_code', $verCode)
            ->find();

        $fields = [
            'version_name'         => $verName,
            'version_type'         => in_array((string) $this->request->post('version_type', 'release'), self::TYPES, true) ? (string) $this->request->post('version_type') : 'release',
            'release_title'        => (string) $this->request->post('release_title', ''),
            'release_note'         => (string) $this->request->post('release_note', ''),
            'os_version'           => (string) $this->request->post('os_version', ''),
            'architecture'         => (string) $this->request->post('architecture', ''),
            'file_name'            => (string) $file->getOriginalName(),
            'file_path'            => $rel,
            'file_size'            => (int) $info['size'],
            'md5'                  => $info['md5'],
            'sha1'                 => $info['sha1'],
            'sha256'               => $info['sha256'],
            'is_force_update'      => (int) $this->request->post('is_force_update', 0) === 1 ? 1 : 0,
            'minimum_version_code' => (int) $this->request->post('minimum_version_code', 0) ?: null,
            'updated_at'           => $now,
        ] + $signatureFields;

        if ($exist) {
            if (in_array($exist['status'], ['published', 'gray'], true)) {
                // 已发布版本不允许覆盖安装包
                return fail(10007, '版本已发布，不允许覆盖安装包，请创建新版本');
            }
            Db::name('app_versions')->where('id', (int) $exist['id'])->update($fields);
            $vid = (int) $exist['id'];
        } else {
            $fields['app_id']     = $appId;
            $fields['platform']   = $platform;
            $fields['version_code'] = $verCode;
            $fields['status']     = 'testing';
            $fields['created_by'] = $this->adminId();
            $fields['created_at'] = $now;
            $vid = (int) Db::name('app_versions')->insertGetId($fields);
        }

        $this->opLog('version', 'upload', $vid, '上传安装包：' . $verName . ' ' . $file->getOriginalName() . ' (' . number_format($info['size'] / 1024 / 1024, 2) . 'MB)');
        return success(['id' => $vid, 'file' => $info], '上传成功');
    }

    /**
     * 更新版本元信息
     */
    public function update(): Json
    {
        $v = $this->versionRow();
        if (in_array($v['status'], ['published', 'gray'], true)) {
            return fail(10007, '已发布/灰度版本不可编辑，请先暂停或回滚');
        }

        $data = [
            'release_title'        => (string) $this->request->put('release_title', $v['release_title'] ?? ''),
            'release_note'         => (string) $this->request->put('release_note', $v['release_note'] ?? ''),
            'os_version'           => (string) $this->request->put('os_version', $v['os_version'] ?? ''),
            'architecture'         => (string) $this->request->put('architecture', $v['architecture'] ?? ''),
            'is_force_update'      => (int) $this->request->put('is_force_update', $v['is_force_update'] ?? 0) === 1 ? 1 : 0,
            'minimum_version_code' => (int) $this->request->put('minimum_version_code', $v['minimum_version_code'] ?? 0) ?: null,
            'updated_at'           => datetime_now(),
        ];
        $type = (string) $this->request->put('version_type', '');
        if ($type !== '' && in_array($type, self::TYPES, true)) {
            $data['version_type'] = $type;
        }
        $status = (string) $this->request->put('status', '');
        if ($status !== '' && in_array($status, ['draft', 'testing', 'pending', 'offline'], true)) {
            if ($status === 'testing' && trim((string) $v['file_path']) === '') {
                return fail(10003, '测试状态需要先上传安装包');
            }
            $data['status'] = $status;
        }

        // 访问控制 + 签名指纹更新
        $accessType = (string) $this->request->put('access_type', '');
        if ($accessType !== '') {
            $data['access_type'] = $this->validAccessType($accessType);
            if ($data['access_type'] === 'password') {
                $pw = (string) $this->request->put('access_password', '');
                if ($pw === '') {
                    return fail(10003, 'password 模式必须提供访问密码');
                }
                $data['access_password'] = $pw;
            } else {
                $data['access_password'] = null;
            }
        }
        $fp = trim((string) $this->request->put('signer_fingerprint', ''));
        if ($fp !== '') {
            $data['signer_fingerprint'] = $fp;
            $data['signature_verified'] = SignatureService::VERIFIED_MANUAL;
        }

        Db::name('app_versions')->where('id', (int) $v['id'])->update($data);
        $this->opLog('version', 'update', (int) $v['id'], '更新版本 ' . $v['version_name']);
        return success(null, '更新成功');
    }

    /**
     * 删除版本
     */
    public function delete(): Json
    {
        $v = $this->versionRow();
        if (in_array($v['status'], ['published', 'gray'], true)) {
            return fail(10007, '已发布/灰度版本不可删除，请先回滚或暂停');
        }
        Db::transaction(function () use ($v) {
            $taskIds = Db::name('release_tasks')->where('version_id', (int) $v['id'])->column('id');
            if ($taskIds) {
                Db::name('release_rules')->whereIn('release_id', $taskIds)->delete();
            }
            Db::name('release_tasks')->where('version_id', (int) $v['id'])->delete();
            Db::name('app_versions')->where('id', (int) $v['id'])->delete();
        });
        $this->removePackageFile($v);
        $this->opLog('version', 'delete', (int) $v['id'], '删除版本 ' . $v['version_name']);
        return success(null, '删除成功');
    }

    /**
     * 发布版本（快速发布：默认全量；支持灰度/定向）
     * POST /api/v1/admin/versions/:id/publish
     * body: name, release_type, rollout_percent, rules[]
     */
    public function publish(): Json
    {
        $v = $this->versionRow();
        if (trim((string) $v['file_path']) === '') {
            return fail(10003, '请先上传安装包再发布');
        }
        if (!in_array($v['status'], ['testing', 'pending', 'paused', 'draft'], true)) {
            if ($v['status'] !== 'gray' && $v['status'] !== 'published') {
                return fail(10007, '当前状态不可发布：' . $v['status']);
            }
        }

        $releaseType = (string) $this->request->post('release_type', 'full');
        $rulesRaw    = $this->request->post('rules', '');
        $rules       = [];
        if (is_string($rulesRaw) && $rulesRaw !== '') {
            $decoded = json_decode($rulesRaw, true);
            if (is_array($decoded)) {
                $rules = $decoded;
            }
        } elseif (is_array($rulesRaw)) {
            $rules = $rulesRaw;
        }

        $rid = ReleaseService::create([
            'app_id'          => (int) $v['app_id'],
            'version_id'      => (int) $v['id'],
            'name'            => (string) $this->request->post('name', '发布 ' . $v['version_name']),
            'version_name'    => (string) $v['version_name'],
            'release_type'    => $releaseType,
            'rollout_percent' => (int) $this->request->post('rollout_percent', 100),
            'start_at'        => (string) $this->request->post('start_at', ''),
            'end_at'          => (string) $this->request->post('end_at', ''),
            'rules'           => $rules,
        ], $this->adminId());

        return success(['release_id' => $rid], '发布成功');
    }

    /**
     * 暂停发布
     */
    public function pause(): Json
    {
        $v = $this->versionRow();
        ReleaseService::pause((int) $v['id'], $this->adminId());
        return success(null, '已暂停发布');
    }

    /**
     * 回滚到上一稳定版本
     */
    public function rollback(): Json
    {
        $v   = $this->versionRow();
        $res = ReleaseService::rollback((int) $v['id'], $this->adminId());
        $msg = $res['rollback_to'] ? '已回滚到 ' . $res['rollback_to']['version_name'] : '已停止问题版本（无上一稳定版本）';
        return success($res, $msg);
    }

    // ---------- helpers ----------

    protected function versionRow(): array
    {
        $id = (int) $this->request->param('id');
        $v  = Db::name('app_versions')->where('id', $id)->find();
        if (!$v) {
            throw BizException::notFound('版本不存在');
        }
        return $v;
    }

    protected function existsVersion(int $appId, string $platform, int $code): bool
    {
        return (bool) Db::name('app_versions')
            ->where('app_id', $appId)
            ->where('platform', $platform)
            ->where('version_code', $code)
            ->find();
    }

    protected function validAccessType(string $type): string
    {
        return in_array($type, ['public', 'password', 'protected'], true) ? $type : 'public';
    }

    protected function fileExists(array $v): bool
    {
        $path = (string) ($v['file_path'] ?? '');
        if ($path === '') {
            return false;
        }
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        if (strpos($path, 'apps/') !== 0) {
            return false;
        }
        return is_file($root . '/' . $path);
    }

    protected function removePackageFile(array $v): void
    {
        $path = (string) ($v['file_path'] ?? '');
        if ($path === '') {
            return;
        }
        $root = rtrim((string) Config::get('app.storage_path'), '/');
        if (strpos($path, 'apps/') === 0) {
            $abs = $root . '/' . $path;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }
}