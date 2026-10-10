<?php

// 无需登录的 handler（action => handler 文件）
$preLoginHandlers = [
    'js-log'      => 'handlers/js_log.php',
];

// 登录相关 action（未登录时可访问）
$loginActions = ['', 'register', 'get-avatar', 'pk-login-begin', 'pk-login-complete'];

// action 白名单
$allowed = [
    'dashboard','keys','keys-create','keys-delete','playlist-detail',
    'playlist-create','playlist-delete','playlist-update','playlist-update-cover','playlist-fetch-cover','playlist-reorder','playlist-sync',
    'song-add','song-remove','song-reorder',
    'users','user-delete','user-admin',
    'domains','domains-list','domains-save','domains-delete','domains-authorize','domains-auto-add',
    'profile','config','settings',
    'settings-site','settings-mail','settings-security','settings-api','settings-storage',
    'pk-begin','pk-complete','pk-delete',
    'bg-presign','bg-confirm','bg-url-save',
    'lrc-font-presign','lrc-font-confirm','lrc-font-clear',
    'cover-local-toggle','cover-cache-status','cover-cache-run','cover-migrate-run','cover-gc-run',
    'pusher-auth','pusher-online-users','clear-logs','debug-toggle',
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
    'pusher-auth'          => ['handlers/pusher_auth.php', false],
    'pusher-online-users'  => ['handlers/pusher_online.php', false],
    'pk-begin'             => ['handlers/passkeys.php', false],

    'keys-create'          => ['handlers/keys.php', true],
    'keys-delete'          => ['handlers/keys.php', true],

    'playlist-create'      => ['handlers/playlists.php', true],
    'playlist-delete'      => ['handlers/playlists.php', true],
    'playlist-update'      => ['handlers/playlists.php', true],
    'playlist-update-cover'=> ['handlers/playlists.php', true],
    'playlist-fetch-cover' => ['handlers/playlists.php', true],
    'playlist-reorder'     => ['handlers/playlists.php', true],
    'playlist-sync'        => ['handlers/playlists.php', true],

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

// 页面模板映射（action => 页面文件）
$pageMap = [
    'dashboard'       => 'pages/dashboard.php',
    'keys'            => 'pages/keys.php',
    'users'           => 'pages/users.php',
    'profile'         => 'pages/profile.php',
    'config'          => 'pages/config.php',
    'domains'         => 'pages/domains.php',
    'settings'        => 'pages/settings.php',
    'settings-site'        => 'pages/settings-site.php',
    'settings-mail'        => 'pages/settings-mail.php',
    'settings-security'    => 'pages/settings-security.php',
    'settings-api'         => 'pages/settings-api.php',
    'settings-storage'     => 'pages/settings-storage.php',
    'playlist-detail' => 'pages/playlist-detail.php',
];