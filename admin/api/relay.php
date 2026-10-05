<?php

$RELAY = [

    'cdn' => [
        'bootstrap_css'    => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
        'bootstrap_js'     => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
        'bootstrap_icons'  => 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css',
        'aplayer_css'      => 'https://cdn.jsdelivr.net/npm/aplayer@1.10.1/dist/APlayer.min.css',
        'aplayer_js'       => 'https://cdn.jsdelivr.net/npm/aplayer@1.10.1/dist/APlayer.min.js',
    ],

    'sdk' => [
        'pusher_js'        => 'https://js.pusher.com/8.2.0/pusher.min.js',
        'geetest_js'       => 'https://static.geetest.com/v4/gt4.js',
    ],

    'api' => [
        'pusher_base'      => 'https://api-ap3.pusher.com',
        'geetest_validate' => 'https://gcaptcha4.geetest.com/validate',
    ],

    'avatar' => [
        'qq'               => 'https://q1.qlogo.cn/g',
    ],

    'music' => [
        'qq_referer'       => 'https://y.qq.com',
        'qq_cover'         => 'https://y.qq.com',
        'netease_referer'  => 'https://music.163.com/',
    ],

    'asset' => [
        'admin_css'        => '/admin/assets/css',
        'admin_js'         => '/admin/assets/js',
        'codemirror'       => '/admin/assets/codemirror',
        'front_css'        => '/assets/css',
        'front_js'         => '/assets/js',
        'embed_js'         => '/api.php',
        'widget_php'       => '/assets/lib/widget.php',
        'jwt_php'          => '/assets/lib/jwt.php',
    ],

    'page' => [
        'base_css'         => '/admin/assets/{device}/css/base.css',
        'base_js'          => '/admin/assets/{device}/js/base.js',
        'login_css'        => '/admin/assets/{device}/css/login.css',
        'login_js'         => '/admin/assets/{device}/js/login.js',
        'dashboard_css'    => '/admin/assets/{device}/css/dashboard.css',
        'keys_css'         => '/admin/assets/{device}/css/keys.css',
        'keys_js'          => '/admin/assets/{device}/js/keys.js',
        'users_css'        => '/admin/assets/{device}/css/users.css',
        'users_js'         => '/admin/assets/{device}/js/users.js',
        'config_css'       => '/admin/assets/{device}/css/config.css',
        'config_js'        => '/admin/assets/{device}/js/config.js',
        'profile_css'      => '/admin/assets/{device}/css/profile.css',
        'profile_js'       => '/admin/assets/{device}/js/profile.js',
        'settings_css'     => '/admin/assets/{device}/css/settings.css',
        'settings_js'      => '/admin/assets/{device}/js/settings.js',
        'settings-site_css'      => '/admin/assets/{device}/css/settings.css',
        'settings-site_js'       => '/admin/assets/{device}/js/settings.js',
        'settings-mail_css'      => '/admin/assets/{device}/css/settings.css',
        'settings-mail_js'       => '/admin/assets/{device}/js/settings.js',
        'settings-security_css'  => '/admin/assets/{device}/css/settings.css',
        'settings-security_js'   => '/admin/assets/{device}/js/settings.js',
        'settings-api_css'       => '/admin/assets/{device}/css/settings.css',
        'settings-api_js'        => '/admin/assets/{device}/js/settings.js',
        'settings-storage_css'   => '/admin/assets/{device}/css/settings.css',
        'settings-storage_js'    => '/admin/assets/{device}/js/settings.js',
        'domains_css'      => '/admin/assets/{device}/css/domains.css',
        'domains_js'       => '/admin/assets/{device}/js/domains.js',
        'playlist-detail_css' => '/admin/assets/{device}/css/playlist-detail.css',
        'playlist-detail_js'  => '/admin/assets/{device}/js/playlist-detail.js',
        'pc_css'           => '/assets/css/pc.css',
        'mobile_css'       => '/assets/css/mobile.css',
        'pc_js'            => '/assets/js/pc.js',
        'mobile_js'        => '/assets/js/mobile.js',
    ],

    'cm' => [
        'core_css'         => '/admin/assets/codemirror/codemirror.min.css',
        'theme_monokai'    => '/admin/assets/codemirror/theme/monokai.min.css',
        'core_js'          => '/admin/assets/codemirror/codemirror.min.js',
        'mode_xml'         => '/admin/assets/codemirror/mode/xml/xml.min.js',
        'mode_css'         => '/admin/assets/codemirror/mode/css/css.min.js',
        'mode_js'          => '/admin/assets/codemirror/mode/javascript/javascript.min.js',
        'mode_html'        => '/admin/assets/codemirror/mode/htmlmixed/htmlmixed.min.js',
        'addon_activeline' => '/admin/assets/codemirror/addon/selection/active-line.min.js',
    ],

];

return $RELAY;