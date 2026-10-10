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

// 后台的写操作一律让「歌单配置缓存」失效：改站点设置/歌单/歌曲/封面都会影响播放器拿到的配置。
// 放在唯一入口处是故意的 —— 逐个 handler 挂钩子容易漏（改了 A 忘了 B 就会出现"改了不生效"）。
// 代价只是下次播放器请求重建一次配置（毫秒级），且 Redis 不可用时这个函数什么都不做。
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && function_exists('cfg_cache_invalidate')) {
    cfg_cache_invalidate();
}

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