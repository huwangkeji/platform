<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\BizException;
use think\facade\Config;
use think\file\UploadedFile;

/**
 * 文件上传：扩展名白名单 + MIME 校验 + 大小限制 + 随机文件名 + 目录隔离
 */
class UploadService
{
    private const EXT_MIME = [
        'jpg'      => ['image/jpeg'],
        'jpeg'     => ['image/jpeg'],
        'png'      => ['image/png'],
        'gif'      => ['image/gif'],
        'webp'     => ['image/webp'],
        'bmp'      => ['image/bmp'],
        'mp4'      => ['video/mp4'],
        'zip'      => ['application/zip', 'application/x-zip-compressed'],
        'rar'      => ['application/vnd.rar', 'application/x-rar-compressed'],
        '7z'       => ['application/x-7z-compressed'],
        'txt'      => ['text/plain'],
        'log'      => ['text/plain'],
        'apk'      => ['application/vnd.android.package-archive'],
        'exe'      => ['application/x-msdownload'],
        'dmg'      => ['application/x-apple-diskimage'],
        'bin'      => ['application/octet-stream'],
        'pem'      => ['application/octet-stream'],
        // 注意：不允许 html/md 等浏览器可直接渲染的类型——
        // storage 与站点同源，攻击者可上传带脚本的 html 诱导他人访问窃取后台 token（存储型 XSS）。
    ];

    /**
     * 保存上传文件到 storage/{group}/{Ym}/xxx.ext
     * @return array{name:string,ext:string,mime:string,size:int,path:string,url:string,md5:string,sha1:string,sha256:string}
     */
    public static function save(?UploadedFile $file, string $group, int $maxSize = 0, array $allowedExts = []): array
    {
        if (!$file) {
            throw BizException::param('未接收到上传文件');
        }
        if (!$file->isValid()) {
            throw BizException::param('文件上传失败，请重试');
        }

        $maxSize = $maxSize > 0 ? $maxSize : (int) Config::get('app.upload_max_size', 1073741824);
        if ($file->getSize() > $maxSize) {
            throw BizException::param('文件大小超过限制');
        }

        $ext     = strtolower((string) $file->extension());
        $allowed = $allowedExts ? array_map('strtolower', $allowedExts) : array_keys(self::EXT_MIME);
        if (!in_array($ext, $allowed, true)) {
            throw BizException::param('不支持的文件类型：' . $ext);
        }

        $mime     = strtolower((string) $file->getMime());
        $expected = $allowedExts ? null : (self::EXT_MIME[$ext] ?? null);
        if ($expected !== null && !in_array($mime, $expected, true) && $mime !== 'application/octet-stream') {
            throw BizException::param('文件MIME类型不合法');
        }

        // 防止可执行/脚本文件落入 web 可执行目录：剥离潜在危险扩展名
        if (in_array($ext, ['php', 'php3', 'php5', 'phtml', 'pht', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh'], true)) {
            throw BizException::param('该文件类型不允许上传');
        }

        $group = preg_replace('/[^a-z0-9_\-]/', '', strtolower($group)) ?: 'files';
        $root  = rtrim((string) Config::get('app.storage_path'), '/');
        $dir   = $root . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . date('Ym');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw BizException::failed('上传目录创建失败');
        }

        $filename = date('His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $file->move($dir, $filename);
        $abs = $dir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($abs)) {
            throw BizException::failed('文件保存失败');
        }

        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $abs);
        $rel = str_replace('\\', '/', $rel);

        return [
            'name'   => (string) $file->getOriginalName(),
            'ext'    => $ext,
            'mime'   => $mime,
            'size'   => (int) $file->getSize(),
            'path'   => $rel,
            'url'    => rtrim((string) Config::get('app.storage_url'), '/') . '/' . $rel,
            'md5'    => md5_file($abs),
            'sha1'   => sha1_file($abs),
            'sha256' => hash_file('sha256', $abs),
        ];
    }

    /**
     * 校验已存在的本地文件（安装包场景：服务端直接移动文件）
     */
    public static function inspect(string $absPath): array
    {
        if (!is_file($absPath)) {
            throw BizException::param('目标文件不存在');
        }
        return [
            'size'   => (int) filesize($absPath),
            'md5'    => md5_file($absPath),
            'sha1'   => sha1_file($absPath),
            'sha256' => hash_file('sha256', $absPath),
        ];
    }
}