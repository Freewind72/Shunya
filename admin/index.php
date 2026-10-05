<?php
define('MAPI_ADMIN', true);

require __DIR__ . '/includes/guard.php';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/routes.php';

$action = $_GET['action'] ?? '';
$isMobile = is_mobile();

// 无需登录的 handler 分发
if (isset($preLoginHandlers[$action])) {
    require __DIR__ . '/' . $preLoginHandlers[$action];
    exit;
}

// 未登录：登录页面或跳转
if (empty($_SESSION['admin_id'])) {
    if (in_array($action, $loginActions)) {
        require __DIR__ . '/pages/login.php';
        exit;
    }
    header('Location: /admin/');
    exit;
}

// action 校验
$action = in_array($action, $allowed) ? $action : 'dashboard';

// 非管理员禁止访问用户管理和设置页（名单见 includes/routes.php 的 $adminOnlyActions）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1) && in_array($action, $adminOnlyActions, true)) {
    $action = 'dashboard';
}

// 已登录 API handler 分发
if (isset($apiHandlers[$action])) {
    [$handlerFile, $requiresPost] = $apiHandlers[$action];
    if (!$requiresPost || $_SERVER['REQUEST_METHOD'] === 'POST') {
        $action_key = $action;
        require __DIR__ . '/' . $handlerFile;
        exit;
    }
}

// 页面渲染
require __DIR__ . '/includes/user_init.php';

$tpl = $pageMap[$action] ?? 'pages/dashboard.php';

require __DIR__ . '/layout/header.php';
require __DIR__ . '/' . $tpl;
require __DIR__ . '/layout/footer.php';