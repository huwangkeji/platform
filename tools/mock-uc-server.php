<?php
/**
 * 模拟 UCenter 2.0 服务器（仅用于本地联调验证，勿用于生产）
 *
 * 启动：php.sh -S 127.0.0.1:8101 tools/mock-uc-server.php
 * 协议：与 UcenterClient 相同（authcode + input 解密 + code=xxx 响应）
 * 支持动作：register / login / edit / synlogin / synlogout / deleteuser
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$MOCK_KEY = getenv('MOCK_UC_KEY') ?: 'test-uc-key-123456';
$MOCK_APPID = (int) (getenv('MOCK_UC_APPID') ?: 1);
$STORE = __DIR__ . '/mock-uc-users.json';

function uc_store(): array
{
    global $STORE;
    if (!file_exists($STORE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($STORE), true);
    return is_array($data) ? $data : [];
}

function uc_save(array $users): void
{
    global $STORE;
    file_put_contents($STORE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function mock_authcode(string $string, string $operation = 'DECODE', string $key = '', int $expiry = 0): string
{
    $ckeyLength = 4;
    $key   = md5($key ?: 'uc');
    $keya  = md5(substr($key, 0, 16));
    $keyb  = md5(substr($key, 16, 16));

    $keyc = $ckeyLength ? ($operation === 'DECODE'
        ? substr($string, 0, $ckeyLength)
        : substr(md5(microtime()), -$ckeyLength)) : '';

    $cryptkey  = $keya . md5($keya . $keyc);
    $keyLength = strlen($cryptkey);

    $string = $operation === 'DECODE'
        ? base64_decode(substr($string, $ckeyLength))
        : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;

    $stringLength = strlen($string);
    $result = '';
    $box = range(0, 255);
    $rndkey = [];
    for ($i = 0; $i <= 255; $i++) {
        $rndkey[$i] = ord($cryptkey[$i % $keyLength]);
    }
    for ($j = $i = 0; $i < 256; $i++) {
        $j = ($j + $box[$i] + $rndkey[$i]) % 256;
        $tmp = $box[$i];
        $box[$i] = $box[$j];
        $box[$j] = $tmp;
    }
    for ($a = $j = $i = 0; $i < $stringLength; $i++) {
        $a = ($a + 1) % 256;
        $j = ($j + $box[$a]) % 256;
        $tmp = $box[$a];
        $box[$a] = $box[$j];
        $box[$j] = $tmp;
        $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
    }

    if ($operation === 'DECODE') {
        if ((int) substr($result, 0, 10) === 0 || (int) substr($result, 0, 10) - time() > 0) {
            if (substr($result, 10, 16) === substr(md5(substr($result, 26) . $keyb), 0, 16)) {
                return substr($result, 26);
            }
        }
        return '';
    }
    return $keyc . str_replace('=', '', base64_encode($result));
}

function mock_response($data): void
{
    global $MOCK_KEY;
    echo 'code=' . urlencode(mock_authcode(serialize($data), 'ENCODE', $MOCK_KEY));
}

// ---- dump 接口：查 mock 服务器内的用户（测试辅助） ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (($_GET['m'] ?? '') === 'dump')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(uc_store(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 正常 API 处理 ----
$input = (string) ($_POST['input'] ?? ($_GET['input'] ?? ''));
$appid = (int) ($_POST['appid'] ?? ($_GET['appid'] ?? 0));
$m = (string) ($_POST['m'] ?? ($_GET['m'] ?? ''));
$a = (string) ($_POST['a'] ?? ($_GET['a'] ?? ''));

if ($appid !== $MOCK_APPID) {
    mock_response(-99);
    exit;
}
if ($m !== 'user') {
    mock_response(-98);
    exit;
}

// 解密 input
$plain = mock_authcode($input, 'DECODE', $MOCK_KEY);
if ($plain === '') {
    mock_response(-97);
    exit;
}
parse_str($plain, $get);
foreach ($get as $k => $v) {
    if (in_array($k, ['agent', 'time'], true)) {
        continue;
    }
    $get[$k] = mock_authcode((string) $v, 'DECODE', $MOCK_KEY);
}

$users = uc_store();
$nextId = 1001;
foreach ($users as $u) {
    if ((int) $u['uid'] >= $nextId) {
        $nextId = (int) $u['uid'] + 1;
    }
}

switch ($a) {
    case 'register':
        $username = (string) ($get['username'] ?? '');
        $password = (string) ($get['password'] ?? '');
        $email    = (string) ($get['email'] ?? '');
        foreach ($users as $u) {
            if ($u['username'] === $username) {
                mock_response(-3); // 用户名已存在
                exit;
            }
            if ($email !== '' && $u['email'] === $email) {
                mock_response(-6); // email 已注册
                exit;
            }
        }
        $users[] = [
            'uid'      => $nextId,
            'username' => $username,
            'password' => md5($password), // 模拟 UC 存储
            'email'    => $email,
        ];
        uc_save($users);
        mock_response($nextId);
        break;

    case 'login':
        $username = (string) ($get['username'] ?? '');
        $password = (string) ($get['password'] ?? '');
        $found = null;
        foreach ($users as $u) {
            if ($u['username'] === $username) {
                $found = $u;
                break;
            }
        }
        if ($found === null) {
            mock_response(-1);
            break;
        }
        if ($found['password'] !== md5($password)) {
            mock_response(-2);
            break;
        }
        // uid\tusername\tauthhash\temail
        mock_response($found['uid'] . "\t" . $found['username'] . "\t" . $found['password'] . "\t" . $found['email']);
        break;

    case 'edit':
        $username = (string) ($get['username'] ?? '');
        $oldpw    = (string) ($get['oldpw'] ?? '');
        $newpw    = (string) ($get['newpw'] ?? '');
        $email    = (string) ($get['email'] ?? '');
        $idx = null;
        foreach ($users as $i => $u) {
            if ($u['username'] === $username) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            mock_response(-1);
            break;
        }
        if ($users[$idx]['password'] !== md5($oldpw)) {
            mock_response(-2);
            break;
        }
        if ($newpw === '' && $email === '') {
            mock_response(-7);
            break;
        }
        if ($newpw !== '') {
            $users[$idx]['password'] = md5($newpw);
        }
        if ($email !== '') {
            $users[$idx]['email'] = $email;
        }
        uc_save($users);
        mock_response(1);
        break;

    case 'synlogin':
        $uid = (int) ($get['uid'] ?? 0);
        mock_response('<script>// mock ucenter synlogin for uid ' . $uid . '</script>');
        break;

    case 'synlogout':
        mock_response('<script>// mock ucenter synlogout</script>');
        break;

    case 'deleteuser':
        $uid = (int) ($get['uid'] ?? 0);
        foreach ($users as $i => $u) {
            if ((int) $u['uid'] === $uid) {
                array_splice($users, $i, 1);
                break;
            }
        }
        uc_save($users);
        mock_response(1);
        break;

    default:
        mock_response(-96);
}