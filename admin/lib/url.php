<?php defined('MAPI_ADMIN') or die('禁止直接访问');

/**
 * 后台路由 URL 助手 —— 后台地址的唯一拼装处。
 *
 * 路由形态（目录索引形态，2026-10-20 起）：
 *   页面 / 表单 / AJAX   →  /admin/<action>/         例如 /admin/dashboard/
 *   登录页（action=''）  →  /admin/dashboard/       （'' 归一到仪表盘，/admin/ 只做入口跳转）
 *   网关（非本文件职责） →  /admin/api/*.php?action=…（音乐 API 网关，形如 api.php?action=song）
 *
 * 两点必须知道：
 *  1) **页面文件在 admin/<action>/index.php（引导块 + 页面主体）**，对外地址
 *     /admin/<action>/ 由 admin/<action>/index.php 这层 4 行薄壳经**目录索引**接管
 *     （nginx 内置 `index index.php` + 目录补斜杠 301，**不需要任何 rewrite/try_files 配置**，
 *     面板重建站点 conf 也不会丢）；壳只做 INDEX_ACTION + require front.php，判定通过后
 *     front.php 会把实体再载入一趟执行主体，主体恰好执行一次。
 *     ⚠️ 之所以必须带尾斜杠：不带斜杠时 nginx 只会对**真实文件**做 fastcgi 直配，目录要
 *     靠 `try_files` 兜底才进 PHP —— 那就又回到"依赖手工 conf、面板一重建就 404"的老路。
 *  2) **一律用绝对路径**（以 / 开头）。切页是 history.pushState 改了地址栏的，相对路径
 *     （?action=keys 这种）会拼到当前地址上，从 /admin/dashboard/ 点一下就变成
 *     /admin/dashboard/?action=keys —— 所以旧的相对写法在路径路由下必须全部换成绝对路径。
 */

/** 后台基路径：/admin（子目录部署时是 /子目录/admin） */
function admin_base(): string
{
    static $base = null;
    if ($base !== null) return $base;

    // 以**本文件的实际位置**定位后台目录，而不是 SCRIPT_NAME —— 请求入口多样
    // （admin/index.php / admin/<action>.php / api 网关各不相同），SCRIPT_NAME 随入口
    // 变化，把基路径押在「入口恰好在 admin/ 下一层」上不可靠。用文件位置则不随请求
    // 入口变化：无论从哪个文件进来，结果都稳定是「url.php 所在目录中 /admin 那一段
    // 之前的部分 + /admin」。
    $guess = '/admin';
    $selfDir = str_replace('\\', '/', (string)realpath(__DIR__));
    $docRoot = str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')));
    if ($selfDir !== '' && $docRoot !== '' && strpos($selfDir, $docRoot) === 0) {
        $rel = trim(substr($selfDir, strlen($docRoot)), '/');   // 例如 admin/lib
        $parts = $rel === '' ? [] : explode('/', $rel);
        if (($i = array_search('admin', $parts, true)) !== false) {
            $guess = '/' . implode('/', array_slice($parts, 0, $i)) . '/admin';
            if (strpos($guess, '//') === 0) $guess = substr($guess, 1);
        }
    } elseif (!empty($_SERVER['SCRIPT_NAME']) && preg_match('#^(.*)/index\.php$#', (string)$_SERVER['SCRIPT_NAME'], $m)) {
        $guess = $m[1];
    }
    $base = rtrim($guess, '/');
    return $base;
}

/**
 * action → 可点/可提交的后台 URL。所有后台地址都在这里拼，改形态只改这一处。
 *   admin_url('dashboard')  →  /admin/dashboard/
 *   admin_url('keys', ['create' => 1]) → /admin/keys/?create=1
 *   admin_url('')           →  /admin/dashboard/  ← 后台根不再当页面用
 *
 * `admin_url('')` 一律指向仪表盘：`/admin/` 只是入口，命中后 302 出去
 * （未登录 → 登录页 /admin/login/，已登录 → 仪表盘）。这样"跳回后台"的所有调用点
 * 都不必再区分登录状态，也不必让任何页面停留在 /admin/。
 *
 * URL 后缀由 ADMIN_URL_SUFFIX 控制（当前为 '/' = 带尾斜杠的目录索引形态）：
 * 页面文件在 admin/<action>/index.php，admin/<action>/index.php 是薄壳，
 * nginx 目录索引直接命中 —— 生成的地址即最终规范地址，**零 rewrite 配置**。
 * ⚠️ 改后缀/形态时**必须**同步两端 admin/assets/{pc,mobile}/js/base.js 的 PAGE_SUFFIX
 * 与 actionOfUrl() 剥后缀逻辑（三方判定漂移的历史事故：换页后侧栏高亮全掉）。
 */
const ADMIN_URL_SUFFIX = '/';

/**
 * 页面文件的物理目录名（相对 admin/）。
 *
 *   文件：admin/pages/<action>/index.php      ← 收在一起，admin/ 顶层保持整洁
 *   地址：/admin/<action>/                    ← 对外**不带** pages 段
 *
 * ⚠️ 这两个不是一回事：`$pageMap` 用 ADMIN_PAGES_DIR 拼文件路径，
 *    `admin_url()` **不**用它拼地址。改它只改文件位置，不改 URL。
 *    地址形态由 admin_url() 决定，见那个函数上方的说明。
 */
const ADMIN_PAGES_DIR = 'pages';

/**
 * 「页面 action」名单的读取口 —— 分类**唯一来源**是 includes/routes.php 定义的
 * ADMIN_PAGE_ACTIONS 常量（由 $pageMap 派生）。改那张表 = 改全局 URL 生成行为。
 */
function admin_page_actions(): array
{
    static $pages = null;
    if ($pages !== null) return $pages;
    $pages = defined('ADMIN_PAGE_ACTIONS') ? (array)constant('ADMIN_PAGE_ACTIONS') : [];
    return $pages;
}

/** 这个 action 是「页面」吗（决定 URL 形态：页面走路径、动作走单一入口） */
function admin_is_page_action(string $action): bool
{
    $action = trim($action, '/');
    if ($action === '') return true;                 // 后台根 → 归一到仪表盘（/admin/ 只做跳转）
    $pages = admin_page_actions();
    if (!$pages) return true;                        // 路由表未载入时按旧行为（页面形态）处理
    return in_array($action, $pages, true);
}

/**
 * 动作 action → 单一入口地址。
 *   admin_api_url('keys-create')            → /admin/api/?action=keys-create
 *   admin_api_url('get-avatar', ['username' => 'x']) → /admin/api/?action=get-avatar&username=x
 *
 * 注意：这是**后台动作入口**（admin/api/index.php，按白名单分发到 admin/handlers/*），
 * 与同目录下的播放器网关（admin/api/api.php?action=…）是两回事，别混。
 */
function admin_api_url(string $action, array $query = []): string
{
    $query['action'] = trim($action, '/');
    return admin_base() . '/api/?' . http_build_query($query);
}

function admin_url(string $action = '', array $query = []): string
{
    $action = trim($action, '/');
    if ($action === '') $action = 'dashboard';          // 后台根 = 仪表盘（/admin/ 本身只做跳转）

    // ⚠️ 隐式分类（2026-10-16 起）：动作 action 收口到单一入口 /admin/api/?action=<动作>，
    //    页面 action 保持路径形态 /admin/pages/<page>/。分类的**唯一来源**是 includes/routes.php 的
    //    ADMIN_PAGE_ACTIONS（由 $pageMap 派生）—— 也就是说：**改那张表 = 改全局 URL 生成行为**。
    //    之所以在这里做隐式分类：后台地址只有这一个拼装口，30+ 处调用点（表单 action、回跳 Location）
    //    因此零改动即正确，也不会有人漏改某一处。
    //    路由表未载入时（admin_page_actions() 返回空）按旧行为走路径形态，保持向后兼容。
    if (!admin_is_page_action($action)) return admin_api_url($action, $query);

    // ★ 地址里带 pages 段：/admin/pages/keys/
    //   为什么要带：这条地址必须**物理存在**（admin/pages/keys/ 是真实目录），
    //   nginx 才能靠自带的 `index index.php` 直接命中 —— 零 rewrite 配置，面板重建也不丢。
    //   代价只是地址多一段；好处是永远不需要手工维护 nginx 块（历史上海手工块被面板重建冲掉过）。
    $url = admin_base() . '/' . ADMIN_PAGES_DIR . '/' . $action . ADMIN_URL_SUFFIX;
    if ($query) $url .= '?' . http_build_query($query);
    return $url;
}

/** 当前请求的路径（不含查询串） */
function admin_path(): string
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return is_string($path) ? $path : '';
}

/**
 * 路径 → action。纯函数，便于单测。
 *   /admin/dashboard/         → dashboard   （★ 规范地址：不带 pages 段，由 front.php 内部转发）
 *   /admin/pages/dashboard/   → dashboard   （物理直达：剥 ADMIN_PAGES_DIR 段，同样归一）
 *   /admin/dashboard.php      → dashboard   （段级剥 .php 后缀，大小写不敏感，兼容更早的地址）
 *   /admin/                   → ''          （登录页，与未登录时 $loginActions 里的 '' 同义）
 *   /admin/index.php          → ''          （直接访问入口脚本，等价裸目录；特判优先于剥后缀）
 */
function admin_action_from_path(string $path, string $base = '/admin'): string
{
    $base = rtrim($base, '/');
    // 前缀必须在**路径段边界**上：否则 /administrator 会被当成 /admin + "istrator"
    if ($base !== '' && (strpos($path, $base . '/') === 0 || $path === $base)) {
        $rest = substr($path, strlen($base));
    } else {
        $rest = $path;
    }
    $rest = trim((string)$rest, '/');
    if ($rest === '' || strcasecmp($rest, 'index.php') === 0) return '';

    $seg = explode('/', $rest);

    // ★ 物理目录段：/admin/pages/keys/… → 真正的 action 是下一段（大小写不敏感）。
    //   规范地址不带这一段，但物理直达/旧链接会带，两种都必须解析成同一个 action。
    if (defined('ADMIN_PAGES_DIR')
        && isset($seg[0]) && strcasecmp((string)$seg[0], ADMIN_PAGES_DIR) === 0) {
        array_shift($seg);
        if (!$seg) return '';                       // 只有 /admin/pages/ → 当作后台根
    }

    $first = (string)$seg[0];
    if (strlen($first) > 4 && strcasecmp(substr($first, -4), '.php') === 0) {
        $first = substr($first, 0, -4);
    }
    // 剥完 pages 段后可能剩下 'index.php' 之类
    if (strcasecmp($first, 'index') === 0) return '';
    return $first;
}

/**
 * 解析本请求要执行的 action。顺序：
 *   1) INDEX_ACTION —— 请求命中的是 admin/<action>/index.php 这个页面文件
 *      （对外地址经根路由器引导或物理直达），由其引导块把 action 直接告诉入口，最可靠（不依赖任何服务器变量）。
 *   2) PATH_INFO —— 直接访问 /admin/<file>.php/foo 时 fix_pathinfo 给出 PATH_INFO
 *      （常规页面请求被 ① 吸收，读不到）。
 *   3) 按 admin_base() 从 REQUEST_URI 剥前缀（含剥 .php 后缀）—— 直接访问 /admin/index.php 等情形。
 * 返回的是**未做白名单过滤**的原始 action。
 */
function admin_route_action(): string
{
    if (defined('INDEX_ACTION')) {
        $a = trim((string)constant('INDEX_ACTION'), '/');
        if ($a !== '') return $a;
    }

    $pi = (string)($_SERVER['PATH_INFO'] ?? '');
    if ($pi !== '') return admin_action_from_path($pi, '');   // /admin/index.php/keys → keys

    $path = admin_path();
    if ($path === '') return '';
    $base = admin_base();
    if ($base !== '' && (strpos($path, $base . '/') === 0 || $path === $base)) {
        return admin_action_from_path($path, $base);
    }
    return admin_action_from_path($path, '');
}

/** 把相对/无前缀路径补成包含后台基路径的绝对 URL */
function admin_path_url(string $path, array $query = []): string
{
    $url = admin_base() . '/' . ltrim($path, '/');
    if ($query) $url .= '?' . http_build_query($query);
    return $url;
}

/** 「回到本页」的地址：按当前 action 重新拼一遍（handler 里替代旧的 Location: ?action=… 回跳） */
function admin_current_url(array $dropQuery = []): string
{
    $q = $_GET;
    foreach ($dropQuery as $k) unset($q[$k]);
    unset($q['action']);            // 旧形态带进来的 action 不再回传
    return admin_url(admin_route_action(), $q);
}
