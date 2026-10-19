<?php defined('MAPI_ADMIN') or die('禁止直接访问');

/**
 * 后台「页面」前端控制器 —— 分发逻辑的唯一实现。
 *
 * 谁用它：
 *   ① admin/<action>/index.php  ：目录索引薄壳（对外地址 /admin/<action>/，零 nginx 配置）。壳只声明
 *      MAPI_ADMIN + INDEX_ACTION 后 require 本文件；判定通过后本文件把真身
 *      admin/<action>/index.php 再载入一趟执行主体——真身顶部的防重入块因
 *      MAPI_ADMIN 已定义而跳过，主体恰好执行一次。
 *   ② admin/index.php          ：裸入口 /admin/ 与 /admin/index.php（只做 302 到 login/ 或 dashboard/）
 *   ③ 动作入口 admin/api/index.php 只共用 boot.php，不经过本文件（它不发页面）
 *
 * 文件末尾是页面渲染三件套：页头（layout/header.php）→ 页面模块（$pageMap）→ 页脚。
 * 页面主体在 admin/<action>/index.php 引导块之后，由本文件在权限判定后 include 载入
 * （第二趟，主体恰好执行一次）。
 */
require __DIR__ . '/boot.php';

$__adminDir = dirname(__DIR__);   // <admin>

$action = $_GET['action'] ?? '';
$isGet  = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';

// 旧的查询串形态（/admin/?action=x）301 到规范地址（归一目标一律是 admin_url() 的输出：
// 页面 → /admin/<action>，动作 → /admin/api/?action=x）
// —— 书签、外部链接、老前端缓存下来的 href 都能继续用。只对 GET 做：POST 的 301 会让部分客户端把请求
// 降级成 GET、把 POST 体丢掉（老前端同页提交正是往 ?action=x 发的），所以 POST 不跳转、直接照旧分发。
// 页面目录入口已在文件顶部定义 INDEX_ACTION（说明这次不是"裸入口 + 查询串"），不走这一支。
if ($action !== '' && $isGet && !defined('INDEX_ACTION')) {
    header('Location: ' . admin_url((string)$action), true, 301);
    exit;
}

$action   = admin_route_action();
$isMobile = is_mobile();

// 后台根 /admin/ **不是页面**：GET 命中后立刻跳走，不允许停在这里。
//   未登录 → /admin/login（登录页）
//   已登录 → /admin/dashboard
// 302 而不是 301：这是"入口过渡动作"，不该被浏览器长期缓存成永久跳转。
// 空 action 的 POST 必须放行 —— 那正是管理员登录表单的提交目标（login.php 内部处理）。
if ($action === '' && $isGet) {
    header('Location: ' . (empty($_SESSION['admin_id']) ? admin_url('login') : admin_url('dashboard')), true, 302);
    exit;
}

// 无需登录的 handler 分发
if (isset($preLoginHandlers[$action])) {
    require $__adminDir . '/' . $preLoginHandlers[$action];
    exit;
}

// 登录相关 action（$loginActions）允许未登录与已登录两种情形访问。不在 $loginActions / $allowed
// 两个名单里的收敛为 dashboard。'register' 与 'login' 是同一个模板（登录面板默认显示）。
$isLoginAction = in_array($action, $loginActions, true);

// 大小写变体预查（/admin/KEYS 或 /admin/KEYS.php）：物理命中与否随文件系统而变
// （Windows 大小写不敏感、Linux 敏感），这里统一补齐：小写化后命中页面名单则采用小写形式，
// 后续地址归一段会 301 到规范地址（/admin/keys/）。两平台行为因此统一为 301。
if ($action !== '' && !$isLoginAction && !in_array($action, $allowed, true)) {
    $lower = strtolower($action);
    if ($lower !== $action && in_array($lower, ADMIN_PAGE_ACTIONS, true)) $action = $lower;
}

if ($action !== '' && !$isLoginAction && !in_array($action, $allowed, true)) $action = 'dashboard';

// 当前地址归一（只对 GET 做 —— 301 会丢 POST 体），放在登录判断**之前**，这样未登录也能得到
// 一致的地址形态（目标一律是 admin_url() 的输出，不写死形态）：
//   · 旧 .php 地址、尾段、无尾斜杠、旧的无 pages 段形态、物理直达 admin/pages/<action>/index.php
//     → 全部 301 到规范的 /admin/pages/<action>/
//   · 登录页的规范地址是 /admin/pages/login/，'register'（同一模板）也归一到它
$loginPageActions = ['login', 'register', ''];
if ($isGet && in_array($action, $loginPageActions, true)) {
    $reqPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($reqPath !== '' && $reqPath !== admin_url('login')) {
        header('Location: ' . admin_url('login'), true, 301);
        exit;
    }
} elseif ($isGet) {
    $reqPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($reqPath !== '' && $reqPath !== admin_url($action)) {
        $q = $_GET;
        unset($q['action']);
        header('Location: ' . admin_url($action, $q), true, 301);
        exit;
    }
}

// 未登录：登录页面或跳转
if (empty($_SESSION['admin_id'])) {
    if (in_array($action, $loginActions, true)) {
        require $__adminDir . '/' . $pageMap['login'];
        exit;
    }
    header('Location: ' . admin_url('login'));
    exit;
}

// 已登录时登录页/注册页不再有意义：保持既有行为 —— 就地渲染仪表盘（地址不变，不额外跳转）。
// ⚠️ $action **保持原值**，只换模板：旧实现里 'login' 不在 $pageMap，于是走到
//    `$pageMap[$action]` 取不到值、再落到那个已删除的旧模板兜底 —— 侧栏不高亮、
//    也不加载该动作的页面 JS。（那两个兜底已于 2026-10-16 随该目录一并去掉，见下方 $tpl 处。）
//    这里逐字保留那个观感，避免"顺手改成规范仪表盘"带来计划外的行为变化。
$forceDashboardTpl = $isLoginAction;

// 后台的写操作一律让「歌单配置缓存」失效：改站点设置/歌单/歌曲/封面都会影响播放器拿到的配置。
// 放在唯一入口处是故意的 —— 逐个 handler 挂钩子容易漏（改了 A 忘了 B 就会出现"改了不生效"）。
// 代价只是下次播放器请求重建一次配置（毫秒级），且 Redis 不可用时这个函数什么都不做。
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && function_exists('cfg_cache_invalidate')) {
    cfg_cache_invalidate();
}

// 非管理员禁止访问用户管理和设置页（名单见 includes/routes.php 的 $adminOnlyActions）。
// 这里给 302 而不是静默降级：路径路由下地址栏就是 action，只降级不跳会让 URL 与实际页面不一致。
if ((($_SESSION['admin_is_admin'] ?? 99) > 1) && in_array($action, $adminOnlyActions, true)) {
    flash_set('err', '无权限访问该页面');
    header('Location: ' . admin_url('dashboard'));
    exit;
}

// 已登录 API handler 分发（页面型动作：profile / config / settings-* 的 POST 也走这里）
if (isset($apiHandlers[$action])) {
    [$handlerFile, $requiresPost] = $apiHandlers[$action];
    if (!$requiresPost || $_SERVER['REQUEST_METHOD'] === 'POST') {
        $action_key = $action;
        require $__adminDir . '/' . $handlerFile;
        exit;
    }
}

// 页面渲染
require __DIR__ . '/user_init.php';

$tpl = !empty($forceDashboardTpl) ? $pageMap['dashboard'] : $pageMap[$action];
// `$pageMap`（includes/routes.php）是同文件内无条件定义的 16 元素字面量，
// 'login' / 'dashboard' 必然存在 ⇒ 不需要任何文本兜底，也不留"引用已不存在路径"的代码。

require $__adminDir . '/layout/header.php';

// ══ 页面主体载入 ══════════════════════════════════════════════════════════
// 目录索引形态（2026-10-20 定稿）：规范地址 /admin/pages/<action>/ 指向的是**真实目录**，
// 由 nginx 自带的 `index index.php` 直接命中 admin/pages/<action>/index.php。
//
// 于是页面文件永远**由它自己的引导块先跑**：
//   引导块（if (!defined('MAPI_ADMIN'))）→ 定义 MAPI_ADMIN + INDEX_ACTION → require 本文件
//   → 判定通过 → 转回这里 → 本行 require 同一个文件（此时 MAPI_ADMIN 已定义，顶部 if 被跳过）
//   → 只执行页面主体，恰好一次。
//
// ⚠️ 所以**不需要**在这里补 INDEX_ACTION：命中本文件时它必然已由引导块定义。
//    也正因为如此，`$action` 来自 admin_route_action() 的第一优先级（INDEX_ACTION），
//    路径匹配只是兜底 —— 这比依赖 REQUEST_URI 更可靠。
require $__adminDir . '/' . $tpl;

require $__adminDir . '/layout/footer.php';
