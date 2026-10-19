<?php

set_time_limit(30);

$cfg = require $configFile;
date_default_timezone_set('Asia/Shanghai');
require __DIR__ . '/../../assets/lib/jwt.php';
if (empty($cfg['db']['type'])) {
    header('Location: ../install/');
    exit;
}
session_start();

// 会话超时控制
if (!empty($_SESSION['admin_id'])) {
    $sessionTimeout = 1800;
    $lastActivity = $_SESSION['_last_activity'] ?? 0;
    if ($lastActivity > 0 && time() - $lastActivity > $sessionTimeout) {
        session_destroy();
        header('Location: ' . admin_url('login'));
        exit;
    }
    $_SESSION['_last_activity'] = time();
}

// 加载核心库文件
require __DIR__ . '/../lib/cover_cache.php';
require __DIR__ . '/../lib/cover_store.php';       // 封面本地化（内容寻址 → S3）
require __DIR__ . '/../../assets/lib/api_config.php';
require __DIR__ . '/../../assets/lib/helpers.php';
require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/url.php';   // admin_url()：后台地址拼装（auth.php 也会 require_once，重复加载无害）
require __DIR__ . '/../lib/mail.php';
require __DIR__ . '/../lib/webauthn.php';
require __DIR__ . '/../lib/s3.php';
require __DIR__ . '/../lib/redis.php';               // 播放器数据缓存（纯 PHP RESP；未启用/连不上时全部退化为未命中）
require __DIR__ . '/../lib/upstream_api.php';
require __DIR__ . '/../lib/upstream_snapshot.php';

// 从数据库读取 API 配置
if (!empty($db) && empty($db->connect_error) && empty($db->_error)) {
    // ★ 唯一读取收口点：把 `site`/`admin`/`api`/`api_secrets`/`websocket` 这些段
    //   用数据库里的值覆盖到文件配置上（`db` 段**永不覆盖**；库不可达 ⇒ 原样就是文件配置）。
    //   放在这里的原因：必须**已有可用连接**，且在任何配置消费方之前。
    require_once __DIR__ . '/../../assets/lib/app_config.php';
    $cfg = mapi_app_config($cfg, $db);

    // `api` 段里 base_url / qq_* 另有 `mapi_api` 这条既有链路，保持原样继续合并
    $apiCfg = read_mapi_api_config($db, $cfg);
    $cfg['api'] = array_merge($cfg['api'] ?? [], $apiCfg);
}

// 数据库连接失败则显示错误页面
if (empty($db) || !empty($db->connect_error) || !empty($db->_error)) {
    require __DIR__ . '/../handlers/db_error.php';
}

// 封面存储初始化：首次运行自动建列/建表，并把旧的 JSON 封面缓存迁移进数据库（幂等）
cover_store_ensure($db);

// 加载当前用户角色
if (!empty($_SESSION['admin_id'])) {
    $r = $db->query("SELECT is_admin FROM mapi_users WHERE id=" . (int)$_SESSION['admin_id']);
    if ($r && $row = $r->fetch_assoc()) {
        $_SESSION['admin_is_admin'] = (int)$row['is_admin'];
    }
}