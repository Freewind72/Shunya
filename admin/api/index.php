<?php
/**
 * 后台「动作」统一入口 —— /admin/api/?action=<动作>
 *
 * ⚠️ 这是**后台动作入口**（按白名单分发到 admin/handlers/*），**不是**同目录下的播放器网关。
 *    网关是 api.php / qq_api.php / wy_api.php / relay.php，地址形如 /admin/api/api.php?action=…，
 *    与本文件毫无关系 —— 别混、别顺手改它们。
 *
 * 分工（分类唯一来源 = includes/routes.php 的 ADMIN_PAGE_ACTIONS）：
 *   · 页面 action → 路径形态 /admin/<action>/（薄壳在 admin/<action>/index.php，经目录索引命中；
 *     真身在 admin/<action>/index.php。零 nginx 配置）
 *   · 动作 action → 本文件，URL 形态 /admin/api/?action=<动作>
 *   URL 拼装统一走 lib/url.php 的 admin_url()/admin_api_url()。
 *
 * 安全校验与页面入口 includes/front.php **同源、同顺序**（guard / 白名单 / 登录 / 管理员兜底 / POST 语义），
 * 这样"同一个 action 从哪进来"不改变它的安全语义：
 *   ① 无需登录的 handler（$preLoginHandlers，如 js-log）
 *   ② 登录类动作（$loginActions 里**非页面**的那些：get-avatar / pk-login-begin / pk-login-complete / register）
 *      —— 实现写在登录页里，这里把登录页当页面模块载入（登录页顶部按 $action 直接输出 JSON；
 *      register 出错时照旧把注册面板渲染回去，前端/浏览器行为与旧地址一致）
 *   ③ 白名单：只有 $apiHandlers 里的 action 能从这里执行；其余 404（旧地址是 nginx 404，语义一致）
 *   ④ 未登录 → 302 登录页（与旧入口一致：前端拿到重定向/登录页 HTML 就知道登录态失效）
 *   ⑤ 管理员兜底（$adminOnlyActions，与页面入口同源）
 *   ⑥ 仅 POST 的动作被 GET 命中 → 302 到仪表盘（旧行为是渲染仪表盘 200；这里收敛成一次 302）
 *   ⑦ POST 时让歌单配置缓存失效（与页面入口一致，避免"改了不生效"）
 *   ⑧ 分发到 handler：$action 与 $action_key **两个都设**（handlers/keys.php 读 $action，其余读 $action_key）
 */
define('MAPI_ADMIN', true);

require __DIR__ . '/../includes/boot.php';   // 安装守卫 → 路由表 → 会话/$cfg/$db/lib

$__adminDir = dirname(__DIR__);              // <admin>
$action     = trim((string)($_GET['action'] ?? ''), '/');
$method     = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ① 无需登录的 handler
if ($action !== '' && isset($preLoginHandlers[$action])) {
    $action_key = $action;
    require $__adminDir . '/' . $preLoginHandlers[$action];
    exit;
}

// ② 登录类动作（排除页面 action：'login' 是页面，走 /admin/login/，不从这里出）
if ($action !== '' && in_array($action, $loginActions, true) && !admin_is_page_action($action)) {
    // 登录页里的这些分支要按设备选 CSS/JS，$isMobile 必须先就绪
    $isMobile = is_mobile();
    // 载入登录页真身。这里走 $pageMap 而不是写死路径：
    // 页面文件已合并到 admin/<action>/index.php（一页一文件夹，无 pages/ 中间层），
    // 写死 'pages/login.php' 会指向已删除的文件。
    require $__adminDir . '/' . $pageMap['login'];
    exit;
}

// ③ 白名单
if ($action === '' || !isset($apiHandlers[$action])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '未知的后台动作', 'action' => $action], JSON_UNESCAPED_UNICODE);
    exit;
}

// ④ 未登录
if (empty($_SESSION['admin_id'])) {
    header('Location: ' . admin_url('login'));
    exit;
}

// ⑤ 管理员兜底（名单与页面入口同源；当前动作里没有 adminOnly 的，留着以防将来加）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1) && in_array($action, $adminOnlyActions, true)) {
    flash_set('err', '无权限访问该页面');
    header('Location: ' . admin_url('dashboard'));
    exit;
}

// ⑥ POST 语义
[$handlerFile, $requiresPost] = $apiHandlers[$action];
if ($requiresPost && $method !== 'POST') {
    header('Location: ' . admin_url('dashboard'), true, 302);
    exit;
}

// ⑦ 写操作 → 歌单配置缓存失效
if ($method === 'POST' && function_exists('cfg_cache_invalidate')) {
    cfg_cache_invalidate();
}

// ⑧ 分发
$action_key = $action;
require $__adminDir . '/' . $handlerFile;
exit;
