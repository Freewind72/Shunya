<?php

// CSRF 与闪存消息
csrf_token();
$msg = flash_get('msg') ?? '';
$err = flash_get('err') ?? '';

// 用户配置
$autoTheme = (int)($_SESSION['admin_auto_theme'] ?? 1);
// 播放器主题模式（音乐配置页使用）
$themeMode = $_SESSION['admin_theme_mode'] ?? 'light';
$lyricsDefault = (int)($_SESSION['admin_lyrics_default'] ?? 1);
$autoplayDefault = (int)($_SESSION['admin_autoplay_default'] ?? 0);
// 播放器首次加载位置（side:pct，如 right:88）；空值用内置默认（右侧 88% 高度）
$playerPos = trim((string)($_SESSION['admin_player_pos'] ?? ''));
if (!preg_match('/^(left|right):\d{1,3}$/', $playerPos)) $playerPos = 'right:88';
list($playerPosSide, $playerPosY) = explode(':', $playerPos);
// 播放器皮肤（modules/<skin>/skin.json 的目录名）；空值回落默认皮肤
$playerSkin = trim((string)($_SESSION['admin_player_skin'] ?? ''));
if ($playerSkin === '') $playerSkin = 'router';
// 皮肤级配置：{皮肤名: {pos: "left:80"}}。位置从全局设置搬进了皮肤里，各皮肤互不影响。
$playerSkinCfg = [];
$rawSkinCfg = (string)($_SESSION['admin_player_skin_cfg'] ?? '');
if ($rawSkinCfg !== '') {
    $decoded = json_decode($rawSkinCfg, true);
    if (is_array($decoded)) $playerSkinCfg = $decoded;
}
/** 取某个皮肤的初始位置；没单独配过就回落到全局设置（老数据平滑过渡） */
function skin_pos_of(array $cfg, string $skin, string $fallback): string {
    $p = trim((string)($cfg[$skin]['pos'] ?? ''));
    return preg_match('/^(left|right):\d{1,3}$/', $p) ? $p : $fallback;
}
$isAdmin = $_SESSION['admin_is_admin'] ?? 99;

// Pusher 上线通知已于 2026-10-16 整体移除（含 admin/lib/pusher.php、
// layout/footer.php 的上报、以及保存端 pusher 段的读写）。

// 初始化数据版本
$r = $db->query("SELECT COUNT(*) as cnt FROM mapi_config WHERE config_key='data_version'");
if ($r) {
    $row = $r->fetch_assoc();
    if ((int)$row['cnt'] === 0) {
        $db->query("INSERT INTO mapi_config (config_key, config_value) VALUES ('data_version', '1.0.0')");
    }
}