<?php
declare (strict_types = 1);

namespace app\service;

use app\exception\UcenterException;
use think\facade\Config;
use think\facade\Log;

/**
 * UCenter 2.0 客户端封装
 *
 * 协议兼容 Discuz! UCenter 1.x/2.0（uc_api_post 协议）：
 *  - 本站 → UCenter：POST {api}/index.php，body=m/a/inajax/release/input/appid
 *    input = urlencode(authcode(明文参数串 &agent=&time=, ENCODE, key))，
 *    其中明文参数串的每个 value 再经 urlencode(authcode(v, ENCODE, key)) 双重加密；
 *  - UCenter → 本站（通知）：GET/POST {本站}/api/uc?code=xxx 或 input=xxx（见 UcenterNotify）
 *
 * 端点/密钥全部来自 config/ucenter.php（.env），禁止硬编码。
 */
class UcenterClient
{
    /** UCenter 返回码（注册） */
    public const ERR_BAD_USERNAME = -1;   // 用户名不合法
    public const ERR_BAD_WORD     = -2;   // 包含不允许注册的词语
    public const ERR_EXIST_NAME   = -3;   // 用户名已经存在
    public const ERR_BAD_EMAIL    = -4;   // Email 格式有误
    public const ERR_DENY_EMAIL   = -5;   // Email 不允许注册
    public const ERR_EXIST_EMAIL  = -6;   // 该 Email 已经被注册

    /** UCenter 返回码（登录） */
    public const ERR_LOGIN_NO_USER  = -1; // 用户不存在
    public const ERR_LOGIN_BAD_PASS = -2; // 密码错误
    public const ERR_LOGIN_BAD_SEC  = -3; // 安全提问错误

    /** UCenter 返回码（编辑资料） */
    public const ERR_EDIT_NO_USER = -1;   // 用户不存在
    public const ERR_EDIT_BAD_OLD = -2;   // 原密码错误
    public const ERR_EDIT_BAD_NEW = -3;   // 新密码不合法
    public const ERR_EDIT_BAD_EMAIL = -4; // Email 格式有误
    public const ERR_EDIT_DENY_EMAIL = -5;// Email 不允许注册
    public const ERR_EDIT_EXIST_EMAIL = -6;// 该 Email 已经被注册
    public const ERR_EDIT_NO_CHANGE = -7; // 没有做任何修改

    protected string $api;
    protected string $key;
    protected int $appid;
    protected string $ip;
    protected int $timeout;
    protected string $charset;
    protected string $release;
    protected bool $enabled;
    protected bool $sslVerify;

    public function __construct()
    {
        $cfg = Config::get('ucenter', []);
        $this->enabled   = (bool) ($cfg['enabled'] ?? false);
        $this->api       = (string) ($cfg['api'] ?? '');
        $this->key       = (string) ($cfg['key'] ?? '');
        $this->appid     = (int) ($cfg['appid'] ?? 0);
        $this->ip        = (string) ($cfg['ip'] ?? '');
        $this->timeout   = max(1, (int) ($cfg['timeout'] ?? 5));
        $this->charset   = strtolower((string) ($cfg['charset'] ?? 'utf-8'));
        $this->release   = (string) ($cfg['release'] ?? '20090201');
        // 默认校验 TLS 证书（防 MITM）；仅自签名内网环境通过 UC_SSL_VERIFY=false 关闭
        $this->sslVerify = (bool) ($cfg['ssl_verify'] ?? true);
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function configured(): bool
    {
        return $this->enabled && $this->api !== '' && $this->key !== '' && $this->appid > 0;
    }

    /**
     * 注册用户到 UCenter
     * @return int >0 为 uid；负数为 UCenter 错误码（见 ERR_*_*）
     * @throws UcenterException 网络/签名/协议异常
     */
    public function register(string $username, string $password, string $email): int
    {
        $res = $this->api('user', 'register', [
            'username' => $username,
            'password' => $password,
            'email'    => $email,
        ]);
        return (int) $res;
    }

    /**
     * UCenter 登录校验
     * @return array{uid:int,username:string,password:string,email:string} 成功
     * @throws UcenterException 网络/签名/协议异常
     */
    public function login(string $username, string $password): array
    {
        $res = $this->api('user', 'login', [
            'username' => $username,
            'password' => $password,
        ]);
        if (is_array($res)) {
            return $res;
        }
        // 兼容 uid\tusername\tpassword\temail 明文格式
        if (is_string($res) && str_contains($res, "\t")) {
            [$uid, $uname, $upass, $uemail] = array_pad(explode("\t", $res, 4), 4, '');
            return ['uid' => (int) $uid, 'username' => $uname, 'password' => $upass, 'email' => $uemail];
        }
        throw new UcenterException('UCenter 登录返回格式无法解析：' . var_export($res, true));
    }

    /**
     * 登录失败判定（UCenter 返回负错误码）
     * @param mixed $res
     */
    public function isLoginError(mixed $res): bool
    {
        return is_int($res) && $res < 0;
    }

    /**
     * 改密/改邮箱（edit）。
     * @param string $oldpw 原密码（必须，安全校验用）
     * @param string $newpw 新密码（留空表示不改）
     * @param string $email 新邮箱（留空表示不改）
     * @return int 1=成功；负数为 UCenter 错误码
     */
    public function edit(string $username, string $oldpw, string $newpw = '', string $email = ''): int
    {
        $res = $this->api('user', 'edit', [
            'username' => $username,
            'oldpw'    => $oldpw,
            'newpw'    => $newpw,
            'email'    => $email,
        ]);
        if (is_array($res)) {
            return (int) ($res['code'] ?? ($res[0] ?? -1));
        }
        return (int) $res;
    }

    /**
     * 获取同步登录广播代码（本站登录成功后调用，输出到页面让关联站点同步登录）
     * @return string HTML/JS 代码片段（可能为空字符串）
     */
    public function synlogin(int|string $uid): string
    {
        $res = $this->api('user', 'synlogin', ['uid' => (string) $uid]);
        if (is_array($res)) {
            return (string) ($res['code'] ?? '');
        }
        return (string) $res;
    }

    /**
     * 获取同步登出广播代码
     * @return string HTML/JS 代码片段
     */
    public function synlogout(): string
    {
        $res = $this->api('user', 'synlogout', []);
        if (is_array($res)) {
            return (string) ($res['code'] ?? '');
        }
        return (string) $res;
    }

    /**
     * 删除 UCenter 用户
     * @return int 1=成功 0=失败
     */
    public function delete(int|string $uid): int
    {
        $res = $this->api('user', 'deleteuser', ['uid' => (string) $uid]);
        if (is_array($res)) {
            return (int) ($res['code'] ?? 0);
        }
        return (int) $res;
    }

    /**
     * 通用 API 调用。
     * @param string $module 模块，如 user
     * @param string $action 动作，如 register/login/edit/synlogin
     * @param array $args 业务参数（明文，内部负责加密传输）
     * @return int|string|array 解析后的返回值
     */
    public function api(string $module, string $action, array $args = []): int|string|array
    {
        if (!$this->configured()) {
            throw new UcenterException('UCenter 未启用或配置不完整（UC_ENABLED/UC_API/UC_KEY/UC_APPID）');
        }

        // 1. 构造 input：每个 value 单独 authcode 加密 + urlencode
        $s   = '';
        $sep = '';
        foreach ($args as $k => $v) {
            $s .= $sep . urlencode((string) $k) . '=' . urlencode($this->authcode((string) $v, 'ENCODE'));
            $sep = '&';
        }
        // 2. 整体 authcode 加密 + 附加 agent/time + urlencode 作为 input
        $agent = md5('UC2-Client');
        $input = urlencode($this->authcode($s . '&agent=' . $agent . '&time=' . time(), 'ENCODE'));

        $post = 'm=' . $module
            . '&a=' . $action
            . '&inajax=2'
            . '&release=' . $this->release
            . '&input=' . $input
            . '&appid=' . $this->appid;

        // 真实 UCenter（含 ucenter.heicat.com 2.0）的 API 网关入口统一为根目录 index.php
        $body = $this->httpPost($this->api . '/index.php', $post);
        return $this->parseResponse($body);
    }

    /**
     * 解析 UCenter 响应（自适应多种格式）
     */
    protected function parseResponse(string $body): int|string|array
    {
        $body = trim($body);
        if ($body === '') {
            throw new UcenterException('UCenter 返回空响应');
        }

        // 格式 A：code=xxx（authcode 加密包裹），先解密
        if (str_starts_with($body, 'code=')) {
            $code = urldecode(substr($body, 5));
            $plain = $this->authcode($code, 'DECODE');
            if ($plain === '') {
                throw new UcenterException('UCenter 响应签名校验失败或已过期');
            }
            return $this->decodePayload($plain);
        }

        // 格式 B：XML <root>...</root>（uc_client 的 xml 序列化）
        if (preg_match('/^<root>(.*)<\/root>$/s', $body, $m)) {
            $xml = @simplexml_load_string($body);
            if ($xml !== false) {
                $items = [];
                foreach ($xml->children() as $item) {
                    $text = (string) $item;
                    $decoded = $this->authcode(urldecode($text), 'DECODE');
                    $items[] = $decoded !== '' ? $decoded : $text;
                }
                if (count($items) === 1) {
                    return $this->decodePayload($items[0]);
                }
                return $items;
            }
            return $this->decodePayload(html_entity_decode(trim(strip_tags($m[1]))));
        }

        // 格式 C：直接明文（数字错误码 / tab 分隔串 / serialize）
        return $this->decodePayload($body);
    }

    protected function decodePayload(string $plain): int|string|array
    {
        $plain = trim($plain);
        if (is_numeric($plain)) {
            return (int) $plain;
        }
        if (str_starts_with($plain, 'a:') || str_starts_with($plain, 's:') || str_starts_with($plain, 'i:')) {
            $arr = @unserialize($plain);
            if ($arr !== false) {
                return $arr;
            }
        }
        return $plain;
    }

    protected function httpPost(string $url, string $post): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new UcenterException('无法初始化 cURL');
        }
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: */*',
            'User-Agent: UC2-Client/' . $this->release,
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
        ]);
        if ($this->ip !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':443:' . $this->ip, $host . ':80:' . $this->ip]);
            }
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            Log::error('[UCenter] 请求失败 url=' . $url . ' errno=' . $errno . ' error=' . $error);
            throw new UcenterException('UCenter 服务不可达：' . ($error ?: 'connection error'));
        }
        return (string) $body;
    }

    /**
     * Discuz/UCenter 标准 authcode 加解密（协议兼容）
     * @param string $string
     * @param string $operation ENCODE | DECODE
     * @param int $expiry 有效期秒（默认 0 不过期）
     */
    public function authcode(string $string, string $operation = 'DECODE', int $expiry = 0): string
    {
        $ckeyLength = 4;
        $key        = md5($this->key ?: 'uc');
        $keya       = md5(substr($key, 0, 16));
        $keyb       = md5(substr($key, 16, 16));

        $keyc = $ckeyLength ? ($operation === 'DECODE'
            ? substr($string, 0, $ckeyLength)
            : substr(md5(microtime()), -$ckeyLength)) : '';

        $cryptkey  = $keya . md5($keya . $keyc);
        $keyLength = strlen($cryptkey);

        $string = $operation === 'DECODE'
            ? base64_decode(substr($string, $ckeyLength))
            : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;

        $stringLength = strlen($string);
        $result       = '';
        $box          = range(0, 255);
        $rndkey       = [];
        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $keyLength]);
        }
        for ($j = $i = 0; $i < 256; $i++) {
            $j       = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $stringLength; $i++) {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }

        if ($operation === 'DECODE') {
            if (
                (int) substr($result, 0, 10) === 0
                || (int) substr($result, 0, 10) - time() > 0
            ) {
                if (substr($result, 10, 16) === substr(md5(substr($result, 26) . $keyb), 0, 16)) {
                    return substr($result, 26);
                }
            }
            return '';
        }
        return $keyc . str_replace('=', '', base64_encode($result));
    }
}