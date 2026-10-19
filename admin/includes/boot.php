<?php defined('MAPI_ADMIN') or die('禁止直接访问');

/**
 * 后台请求公共引导：安装守卫 → 路由表 → 会话与依赖库。
 *
 * 顺序是有意的：routes.php 是纯数组、无副作用，**先于 bootstrap.php** 载入，
 * 这样 admin_url() 从第一次被调用起就知道某个 action 是「页面」还是「动作」
 * （见 routes.php 的 ADMIN_PAGE_ACTIONS 与 lib/url.php 的 admin_url()）。
 * guard.php 必须最先 —— 它定义 bootstrap.php 依赖的 $configFile。
 *
 * 使用者：includes/front.php（页面前端控制器）与 admin/api/index.php（动作前端控制器）。
 */
require __DIR__ . '/guard.php';       // $configFile（bootstrap 依赖）
require __DIR__ . '/routes.php';      // $pageMap / $allowed / $apiHandlers / ADMIN_PAGE_ACTIONS
require __DIR__ . '/bootstrap.php';   // session、$cfg、$db、lib/*
