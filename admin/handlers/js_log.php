<?php

// 播放器前端 JS 报错上报（登录前也可访问，所以这里必须自己把住口子）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

// 限流：同一 IP 每分钟最多 30 条，防刷（依赖 bootstrap 已 require 的 assets/lib/helpers.php）
rate_limit_check('jslog', 30, 60);

// 清洗：去掉换行/控制字符（否则可在日志里注入伪造行），并限长
function js_log_clean($value, int $max): string
{
    if (!is_scalar($value)) return '';
    $value = str_replace(["\r", "\n", "\t", "\0"], ' ', (string)$value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    if (function_exists('mb_substr')) $value = mb_substr($value, 0, $max, 'UTF-8');
    else $value = substr($value, 0, $max);
    return trim($value);
}

$name = js_log_clean($_POST['name'] ?? '', 60);
$msg  = js_log_clean($_POST['message'] ?? '', 1000);
$ref  = js_log_clean($_SERVER['HTTP_REFERER'] ?? '', 200);
$ua   = js_log_clean($_SERVER['HTTP_USER_AGENT'] ?? '', 200);

// 写服务器端错误日志（不在网站目录里，不可被下载）；
// 旧实现是 append 到 admin/assets/js-error.log —— 那个路径在 web 根下、可被直接下载。
error_log('[player-js] ' . $name . ': ' . $msg . ' | ref:' . $ref . ' | ua:' . $ua);
exit;
