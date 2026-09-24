<?php
// +----------------------------------------------------------------------
// | UCenter 2.0 账号同步配置
// | 说明：端点/密钥均从 .env 读取，禁止硬编码
// +----------------------------------------------------------------------
return [
    // 是否启用 UCenter 同步（false 时全部走本地账号体系，同步逻辑自动跳过）
    'enabled' => (bool) env('UC_ENABLED', false),

    // UCenter 服务器地址（根地址，如 https://uc.example.com/uc_server）
    'api'     => rtrim((string) env('UC_API', ''), '/'),

    // 通信密钥（UCenter 后台为本站分配，与 UC_APPID 配对）
    'key'     => (string) env('UC_KEY', ''),

    // 应用 ID（UCenter 后台分配给本站的应用编号）
    'appid'   => (int) env('UC_APPID', 0),

    // UCenter 服务器 IP（可空，用于 DNS 失败时直接直连）
    'ip'      => (string) env('UC_IP', ''),

    // 通信超时（秒）
    'timeout' => (int) env('UC_TIMEOUT', 5),

    // 是否校验 TLS 证书（默认 true；仅自签名内网部署时设 false，公网必须保持校验）
    'ssl_verify' => (bool) env('UC_SSL_VERIFY', true),

    // 编码（与 UCenter 服务器保持一致）
    'charset' => strtolower((string) env('UC_CHARSET', 'utf-8')),

    // 客户端版本标识（协议兼容 UC_CLIENT_RELEASE）
    'release' => (string) env('UC_RELEASE', '20090201'),

    // 降级策略：UCenter 不可达/校验失败时
    //  login  = local   本地密码校验兜底（本地已绑定 UCenter 账号且存有密码哈希时）
    //  login  = strict  严格模式：一律以 UCenter 校验结果为准
    //  write  = local   写操作（注册/改密/改邮箱）UCenter 失败时本地继续并标记待同步
    //  write  = strict  写操作失败时整体失败（保证两端一致）
    'fallback' => [
        'login' => (string) env('UC_FALLBACK_LOGIN', 'local'),
        'write' => (string) env('UC_FALLBACK_WRITE', 'local'),
    ],
];
