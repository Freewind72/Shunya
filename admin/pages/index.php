<?php
/**
 * admin/pages/ 目录入口 —— 只做归一跳转，不渲染任何页面。
 *
 * 为什么需要它：
 *   ① 没有它时，直接访问 /admin/pages/ 会落到「目录无索引」的分支——
 *      nginx 返回 403（目录列表禁用）、php -S 返回 404，形态因服务器而异；
 *   ② 有它之后，/admin/pages/ 一律 302 到后台根判定（未登录 → login，已登录 → dashboard），
 *      与 /admin/ 的行为完全一致，不暴露「这里有一堆页面目录」这一事实。
 *
 * 注意：本文件**不是**页面（不定义 INDEX_ACTION），只是入口壳。
 *      action 为空的 GET 会由 front.php 自己 302 走掉，所以这里同样交给它。
 */
define('MAPI_ADMIN', true);
require __DIR__ . '/../includes/front.php';