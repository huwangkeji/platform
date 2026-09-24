<?php
declare (strict_types = 1);

namespace app\service;

use think\facade\Config;

/**
 * 安装包签名验证服务：提取签名证书指纹（SHA256）
 * 优先使用系统工具 apksigner/keytool/openssl；不可用时降级为声明值并标记待人工核验
 */
class SignatureService
{
    public const VERIFIED_NONE  = 0; // 未验证
    public const VERIFIED_TOOL  = 1; // 工具自动验证通过
    public const VERIFIED_MANUAL = 2; // 声明值待人工核验
    public const VERIFIED_MISMATCH = 3; // 校验不一致

    /**
     * 提取安装包签名指纹
     * @return array{fingerprint:string,verified:int,detail:string}
     */
    public static function extract(string $absPath, string $platform, string $declared = ''): array
    {
        if (!is_file($absPath)) {
            return ['fingerprint' => '', 'verified' => self::VERIFIED_NONE, 'detail' => '文件不存在'];
        }
        if ($platform === 'android') {
            $res = self::extractApk($absPath);
        } else {
            $res = self::extractIos($absPath);
        }
        if ($res['verified'] === self::VERIFIED_TOOL && $res['fingerprint'] !== '') {
            return $res;
        }

        // 工具不可用：回退到申报指纹，标记待人工核验
        if ($declared !== '') {
            return [
                'fingerprint' => self::normalize($declared),
                'verified'    => self::VERIFIED_MANUAL,
                'detail'      => '未检测到本地签名工具，使用上传申报指纹，需人工核验',
            ];
        }
        return $res;
    }

    protected static function extractApk(string $abs): array
    {
        // 1. apksigner
        $out = [];
        $code = 1;
        @exec('command -v apksigner 2>/dev/null', $out, $code);
        if ($code === 0) {
            $lines = [];
            @exec('apksigner verify --print-certs ' . escapeshellarg($abs) . ' 2>&1', $lines);
            foreach ($lines as $line) {
                if (preg_match('/SHA-256\s+digest:\s*([0-9a-fA-F:]{32,})/', $line, $m)) {
                    return ['fingerprint' => self::normalize($m[1]), 'verified' => self::VERIFIED_TOOL, 'detail' => 'apksigner 提取'];
                }
            }
        }

        // 2. keytool
        $out = [];
        $code = 1;
        @exec('command -v keytool 2>/dev/null', $out, $code);
        if ($code === 0) {
            $lines = [];
            @exec('keytool -printcert -jarfile ' . escapeshellarg($abs) . ' 2>&1', $lines);
            foreach ($lines as $line) {
                if (preg_match('/SHA256:\s*([0-9A-F:]{32,})/', $line, $m)) {
                    return ['fingerprint' => self::normalize($m[1]), 'verified' => self::VERIFIED_TOOL, 'detail' => 'keytool 提取'];
                }
            }
        }

        // 3. 解压 META-INF 证书 + openssl 提取（v1 签名）
        if (class_exists('\ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($abs) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (preg_match('#^META-INF/.*\.(RSA|DSA|EC)$#i', $name)) {
                        $cert = $zip->getFromIndex($i);
                        if ($cert) {
                            $tmp = tempnam(sys_get_temp_dir(), 'cert') . '.der';
                            @file_put_contents($tmp, $cert);
                            $lines = [];
                            @exec('openssl pkcs7 -inform DER -in ' . escapeshellarg($tmp) . ' -print_certs -out ' . escapeshellarg($tmp . '.pem') . ' 2>/dev/null');
                            @exec('openssl x509 -in ' . escapeshellarg($tmp . '.pem') . ' -noout -fingerprint -sha256 2>&1', $lines);
                            @unlink($tmp);
                            @unlink($tmp . '.pem');
                            foreach ($lines as $line) {
                                if (preg_match('/SHA256 Fingerprint=([0-9A-F:]{32,})/i', $line, $m)) {
                                    $zip->close();
                                    return ['fingerprint' => self::normalize($m[1]), 'verified' => self::VERIFIED_TOOL, 'detail' => 'META-INF 证书提取'];
                                }
                            }
                        }
                    }
                }
                $zip->close();
            }
        }

        return ['fingerprint' => '', 'verified' => self::VERIFIED_NONE, 'detail' => '未提取到 Android 签名证书'];
    }

    protected static function extractIos(string $abs): array
    {
        // IPA：读取 embedded.mobileprovision 内的证书，用 openssl 提取指纹
        if (!class_exists('\ZipArchive') || !is_file($abs)) {
            return ['fingerprint' => '', 'verified' => self::VERIFIED_NONE, 'detail' => '无法解析 IPA'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($abs) !== true) {
            return ['fingerprint' => '', 'verified' => self::VERIFIED_NONE, 'detail' => '无法打开 IPA'];
        }
        $target = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^Payload/.*\.app/embedded.mobileprovision$#', $name)) {
                $target = $i;
                break;
            }
        }
        if ($target === null) {
            $zip->close();
            return ['fingerprint' => '', 'verified' => self::VERIFIED_NONE, 'detail' => 'IPA 中未找到 embedded.mobileprovision'];
        }
        $content = (string) $zip->getFromIndex($target);
        $zip->close();
        if (trim($content) === '') {
            return ['fingerprint' => '', 'verified' => self::VERIFIED_NONE, 'detail' => 'mobileprovision 内容为空'];
        }
        // 提取 plist 中的 DER 证书块（<data> base64）
        if (preg_match_all('#<data>\s*([A-Za-z0-9+/=]+)\s*</data>#', $content, $matches)) {
            $last = null;
            foreach ($matches[1] as $b64) {
                $der = base64_decode($b64, true);
                if ($der === false) {
                    continue;
                }
                $last = $der;
            }
            if ($last !== null) {
                $tmp = tempnam(sys_get_temp_dir(), 'crt') . '.der';
                @file_put_contents($tmp, $last);
                $lines = [];
                @exec('openssl x509 -inform DER -in ' . escapeshellarg($tmp) . ' -noout -fingerprint -sha256 2>&1', $lines);
                @unlink($tmp);
                foreach ($lines as $line) {
                    if (preg_match('/SHA256 Fingerprint=([0-9A-F:]{32,})/i', $line, $m)) {
                        return ['fingerprint' => self::normalize($m[1]), 'verified' => self::VERIFIED_TOOL, 'detail' => 'mobileprovision 证书提取'];
                    }
                }
            }
        }
        return ['fingerprint' => '', 'verified' => self::VERIFIED_NONE, 'detail' => '未提取到 iOS 签名证书'];
    }

    /**
     * 指纹归一化：转大写、去冒号/空格
     */
    public static function normalize(string $fp): string
    {
        return strtoupper(str_replace([':', ' ', '-'], '', trim($fp)));
    }

    /**
     * 比较指纹是否一致（归一化后）
     */
    public static function match(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        return self::normalize($a) === self::normalize($b);
    }
}