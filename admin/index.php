<?php
/**
 * 后台裸入口：/admin/（以及直连 /admin/index.php）。
 *
 * 这里只剩两件事：声明自己在后台上下文里，然后交给页面前端控制器。
 *   · 分发逻辑（守卫/路由/权限/动作）→ includes/front.php
 *   · 页面文件外壳 admin/<page>.php 走的是同一个控制器（文件顶部只多一层「文件外壳」）
 *   · 动作统一入口是 admin/api/index.php?action=<动作>（与播放器网关 admin/api/api.php 无关）
 */
define('MAPI_ADMIN', true);
require __DIR__ . '/includes/front.php';
