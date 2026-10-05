<?php

// 在线用户列表仅管理员（is_admin<=1）可读：「人员」页本身就是管理员专属，
// 这里补一道门槛，避免用户组直接请求接口拿到在线名单。
if ((($_SESSION['admin_is_admin'] ?? 99)) > 1) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => '无权限']);
    exit;
}

header('Content-Type: application/json');
echo json_encode(pusher_online_users());
exit;
