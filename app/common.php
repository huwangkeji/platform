<?php
// 应用公共文件

use think\response\Json;

if (!function_exists('success')) {
    /**
     * 统一成功响应
     */
    function success($data = null, string $message = 'success'): Json
    {
        return json(['code' => 0, 'message' => $message, 'data' => $data]);
    }
}

if (!function_exists('fail')) {
    /**
     * 统一失败响应
     */
    function fail(int $code = 1, string $message = 'error', $data = null): Json
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data]);
    }
}

if (!function_exists('page_result')) {
    /**
     * 分页结果统一定义
     * @return array{list:array,total:int,page:int,page_size:int}
     */
    function page_result(array $list, int $total, int $page, int $pageSize): array
    {
        return ['list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }
}

if (!function_exists('datetime_now')) {
    function datetime_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}