<?php defined('MAPI_ADMIN') or die('禁止直接访问');

// 仪表盘「最近调用」右上角的「调试」开关。
// 开 = 不再过滤本机/本地调试产生的调用记录（便于排查嵌入播放器时的本地请求）；
// 关 = 只显示真实访客的调用。这是一个全站设置，所以仅超级管理员可改，
// 与同卡片里「清空记录」按钮的可见性保持一致。
if ((($_SESSION['admin_is_admin'] ?? 99)) !== 0) {
    http_response_code(403);
    echo 'denied';
    exit;
}

csrf_require();

$on = !empty($_POST['debug']) ? '1' : '0';

// mapi_super_settings 上 setting_key 是唯一键：先删后插（MySQL / SQLite 通用，与 cover_cache.php 同法）。
$db->query("DELETE FROM mapi_super_settings WHERE setting_key='debug_mode'");
$db->query("INSERT INTO mapi_super_settings (setting_key, setting_value) VALUES ('debug_mode', '" . $db->real_escape_string($on) . "')");

// 前端按响应文本是否等于 ok 判成败（见 dashboard.js）。
echo 'ok';
exit;
