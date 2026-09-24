<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],

    // 异常页面的模板文件
    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => false,

    // ---------------- 平台业务配置 ----------------
    // Token 签名密钥（生产环境务必修改）
    'app_key'        => env('APP_KEY', 'change-me-to-a-random-string'),
    // Token 有效期（秒）
    'jwt_ttl'        => 7200,
    // 存储根目录（项目根/storage）
    'storage_path'   => app()->getRootPath() . 'storage',
    // 存储对外访问前缀（Nginx alias 映射）
    'storage_url'    => '/storage',
    // 下载模式：redirect | xaccel | stream
    'download_mode'  => env('DOWNLOAD_MODE', 'redirect'),
    // 单文件上传大小上限（字节），默认 1GB（App 安装包）
    'upload_max_size' => 1073741824,
];
