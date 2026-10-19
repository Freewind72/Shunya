<?php

// 路由表。两类 action 的 URL 形态不同（分类的唯一来源是本文件末尾的 ADMIN_PAGE_ACTIONS）：
//   · 页面 action → /admin/<action>/         薄壳 admin/<action>/index.php 经**目录索引**命中
//                                            （nginx 内置 `index index.php` + 补斜杠，零配置）；薄壳只声明
//                                            MAPI_ADMIN + INDEX_ACTION 后交 front.php 判定，通过后
//                                            front.php 再把真身 admin/<action>/index.php
//                                            载入一趟执行主体（恰好执行一次）
//   · 动作 action → /admin/api/?action=<动作> 统一收口到单一动作入口 admin/api/index.php
// 页面由 includes/front.php 套 layout（页头/页脚）载入；动作由 admin/api/index.php 分发到 handlers/。

// 无需登录的 handler（action => handler 文件）
$preLoginHandlers = [
    'js-log'      => 'handlers/js_log.php',
];

// 登录相关 action（未登录时可访问）。'' 只作为「旧地址 /admin/」的解析结果出现 ——
// 它本身不再是页面：/admin/ 命中后被 302 出去（未登录 → login，已登录 → dashboard），
// 见 admin/index.php 顶部。'login' 与 'register' 指向同一个登录页模板
// （登录面板是默认显示的那个），'login' 是地址栏里更好看的那个规范地址。
$loginActions = ['', 'login', 'register', 'get-avatar', 'pk-login-begin', 'pk-login-complete'];

// action 白名单
$allowed = [
    'dashboard','keys','keys-create','keys-delete','keys-export','keys-import','playlist-detail',
    'playlist-create','playlist-delete','playlist-update','playlist-update-cover','playlist-fetch-cover','playlist-reorder','playlist-sync','playlist-sync-queue',
    'song-add','song-remove','song-reorder',
    'users','user-delete','user-admin',
    'domains','domains-list','domains-save','domains-delete','domains-authorize','domains-auto-add',
    'profile','config','settings',
    'settings-site','settings-mail','settings-security','settings-api','settings-storage',
    'pk-begin','pk-complete','pk-delete',
    'bg-presign','bg-confirm','bg-url-save',
    'lrc-font-presign','lrc-font-confirm','lrc-font-clear',
    'cover-local-toggle','cover-cache-status','cover-cache-run','cover-migrate-run','cover-gc-run','cover-gc-purge',
    'clear-logs','debug-toggle',
    'logout',
];

// 仅管理员（is_admin<=1）可访问的页面 action：页面级兜底（admin/index.php）
// 与导航可见性（admin/layout/header.php）共用这一份，新增管理员专属页面只改这里。
// 级别：超管 is_admin=0，管理员=1，用户组>=2。
$adminOnlyActions = [
    'users',
    'settings','settings-site','settings-mail','settings-security','settings-api','settings-storage',
];

// 已登录 API handler 路由表（action => [文件, 是否需要POST]）
$apiHandlers = [
    'logout'               => ['handlers/logout.php', false],

    'domains-list'         => ['handlers/domains.php', false],
    'domains-save'         => ['handlers/domains.php', true],
    'domains-delete'       => ['handlers/domains.php', true],
    'domains-authorize'    => ['handlers/domains.php', true],
    'domains-auto-add'     => ['handlers/domains.php', true],
    // 实时通道「测试」按钮的**只读**自检（签发短令牌 + 回已解析地址；一个字节都不写库）
    'pk-begin'             => ['handlers/passkeys.php', false],

    'keys-create'          => ['handlers/keys.php', true],
    'keys-delete'          => ['handlers/keys.php', true],
    // 歌单配置导出/导入：导出是 GET（浏览器直接下载文件），导入是 POST（上传 JSON）
    'keys-export'          => ['handlers/keys.php', false],
    'keys-import'          => ['handlers/keys.php', true],

    'playlist-create'      => ['handlers/playlists.php', true],
    'playlist-delete'      => ['handlers/playlists.php', true],
    'playlist-update'      => ['handlers/playlists.php', true],
    'playlist-update-cover'=> ['handlers/playlists.php', true],
    'playlist-fetch-cover' => ['handlers/playlists.php', true],
    'playlist-reorder'     => ['handlers/playlists.php', true],
    'playlist-sync'        => ['handlers/playlists.php', true],
    'playlist-sync-queue'  => ['handlers/playlists.php', true],

    'song-add'             => ['handlers/songs.php', true],
    'song-remove'          => ['handlers/songs.php', true],
    'song-reorder'         => ['handlers/songs.php', true],

    'user-delete'          => ['handlers/users_mgmt.php', true],
    'user-admin'           => ['handlers/users_mgmt.php', true],

    'bg-presign'            => ['handlers/background.php', true],
    'bg-confirm'            => ['handlers/background.php', true],
    'bg-url-save'           => ['handlers/background.php', true],

    'lrc-font-presign'      => ['handlers/lrc_font.php', true],
    'lrc-font-confirm'      => ['handlers/lrc_font.php', true],
    'lrc-font-clear'        => ['handlers/lrc_font.php', true],

    'cover-local-toggle'    => ['handlers/covers.php', true],
    'cover-cache-status'    => ['handlers/covers.php', true],
    'cover-cache-run'       => ['handlers/covers.php', true],
    'cover-migrate-run'     => ['handlers/covers.php', true],
    'cover-gc-run'          => ['handlers/covers.php', true],
    // 「清空回收池」：把孤儿对象**立刻**真删（不等默认 7 天保留期）。破坏性 ⇒
    // 支持 dry_run=1 先报清单、真删前逐对象复查 refs（见 handlers/covers.php）。
    'cover-gc-purge'        => ['handlers/covers.php', true],

    'profile'              => ['handlers/profile.php', true],
    'config'               => ['handlers/config_user.php', true],
    'settings'             => ['handlers/settings.php', true],
    'settings-site'        => ['handlers/settings.php', true],
    'settings-mail'        => ['handlers/settings.php', true],
    'settings-security'    => ['handlers/settings.php', true],
    'settings-api'         => ['handlers/settings.php', true],
    'settings-storage'     => ['handlers/settings.php', true],

    'pk-complete'          => ['handlers/passkeys.php', true],
    'pk-delete'            => ['handlers/passkeys.php', true],

    'clear-logs'           => ['handlers/system.php', true],
    'debug-toggle'         => ['handlers/debug_toggle.php', true],
];

// 页面模板映射（action => 页面真身文件）。
// 值一律指向 admin/pages/<action>/index.php（一页一文件夹，全部收在 pages/ 下，
// admin/ 顶层只留 api / assets / cli / handlers / includes / layout / lib / pages / service）。
// 对外地址 /admin/pages/<action>/ 由**目录索引**直接命中该文件，零 nginx 配置、零中间转发层。
// ⚠️ 这里的 'pages' 段与 lib/url.php 的 ADMIN_PAGES_DIR 必须一致（同一个物理目录）。
// 'login' 是登录页：它自带完整 HTML（不套 layout 页头页脚），但同样属于「页面 action」，
// 网址是 /admin/pages/login/。
$pageMap = [
    'dashboard'       => 'pages/dashboard/index.php',
    'keys'            => 'pages/keys/index.php',
    'users'           => 'pages/users/index.php',
    'profile'         => 'pages/profile/index.php',
    'config'          => 'pages/config/index.php',
    'domains'         => 'pages/domains/index.php',
    'settings'        => 'pages/settings/index.php',
    'settings-site'        => 'pages/settings-site/index.php',
    'settings-mail'        => 'pages/settings-mail/index.php',
    'settings-security'    => 'pages/settings-security/index.php',
    'settings-api'         => 'pages/settings-api/index.php',
    'settings-storage'     => 'pages/settings-storage/index.php',
    'playlist-detail' => 'pages/playlist-detail/index.php',
    'login'           => 'pages/login/index.php',
];

// 「页面 action」名单 —— 后台 URL 形态的唯一分类来源（lib/url.php 的 admin_url() 依它决定地址形态）：
//   在名单里   → 路径形态 /admin/<action>（无后缀；页面文件在 admin/<action>/index.php，
//               对外地址经根 index.php 路由器分发）
//   不在名单里 → 动作形态 /admin/api/?action=<action>（单一动作入口）
// 新增页面只改 $pageMap，这里自动跟着变；改这张表 = 改全局 URL 生成行为，动手前先想清楚。
if (!defined('ADMIN_PAGE_ACTIONS')) {
    define('ADMIN_PAGE_ACTIONS', array_values(array_unique(array_merge(array_keys($pageMap), ['login']))));
}
