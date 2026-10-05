<?php

// $action_key 由路由层传入，值为实际的action名

// 全局系统操作仅超级管理员（is_admin=0）：与本页仪表盘的「清空记录」按钮可见性一致，
// 避免用户组直接 POST action 绕过界面。
if ((($_SESSION['admin_is_admin'] ?? 99)) !== 0) {
    flash_set('err', '无权限');
    header('Location: ?action=dashboard'); exit;
}

// 清空调用记录（仅超管）
if ($action_key === 'clear-logs') {
    csrf_require();
    $db->query("DELETE FROM mapi_logs");
    flash_set('msg', '调用记录已清空');
    header('Location: ?action=dashboard'); exit;
}
