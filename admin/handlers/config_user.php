<?php

// $action_key 由路由层传入，值为实际的action名

if ($action_key === 'config') {
    csrf_require();
    $autoTheme = isset($_POST['auto_theme']) ? (int)$_POST['auto_theme'] : 0;
    $themeMode = isset($_POST['theme_mode']) && in_array($_POST['theme_mode'], ['light','dark']) ? $_POST['theme_mode'] : 'light';
    $lyricsDefault = isset($_POST['lyrics_default']) ? (int)$_POST['lyrics_default'] : 1;
    $autoplayDefault = isset($_POST['autoplay_default']) ? (int)$_POST['autoplay_default'] : 0;
    // 播放器皮肤：服务端按清单白名单校验（拒绝伪造值），隐藏的旧皮肤不可被选中
    require_once dirname(__DIR__, 2) . '/modules/registry.php';
    $skins = msapi_skins();
    $playerSkin = (string)($_POST['player_skin'] ?? '');
    if (!isset($skins[$playerSkin]) || !empty($skins[$playerSkin]['hidden'])) {
        $playerSkin = msapi_default_skin();
    }

    // 初始位置：从「全局一份」改为「每个皮肤各一份」（pos_side_<皮肤> / pos_y_<皮肤>）。
    // 只认清单里存在的皮肤，左右枚举 + 0~100 范围都在服务端校验。
    $oldCfgRaw = '';
    try {
        $cr = $db->query('SELECT player_skin_cfg FROM mapi_users WHERE id=' . (int)$_SESSION['admin_id']);
        if ($cr && $crow = $cr->fetch_assoc()) $oldCfgRaw = (string)($crow['player_skin_cfg'] ?? '');
    } catch (Throwable $e) { /* 列不存在：当作空配置 */ }
    $skinCfg = [];
    $decodedCfg = json_decode($oldCfgRaw, true);
    if (is_array($decodedCfg)) $skinCfg = $decodedCfg;

    foreach ($skins as $skName => $skDef) {
        if (!empty($skDef['hidden'])) continue;
        $side = (isset($_POST['pos_side_' . $skName]) && $_POST['pos_side_' . $skName] === 'left') ? 'left' : 'right';
        $y = isset($_POST['pos_y_' . $skName]) ? (int)$_POST['pos_y_' . $skName] : 88;
        if ($y < 0) $y = 0;
        if ($y > 100) $y = 100;
        $skinCfg[$skName] = ['pos' => $side . ':' . $y];
    }
    $playerSkinCfg = json_encode($skinCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // 老列 player_pos 继续维护成「当前皮肤的初始位置」：老客户端与未迁移的数据都还能用
    $playerPos = isset($skinCfg[$playerSkin]['pos']) ? $skinCfg[$playerSkin]['pos'] : 'right:88';
    // 老库同样可能没有 player_skin 列：存在性检查 + 缺列自动补（幂等）
    $hasSkinCol = true;
    try { $r = $db->query('SELECT player_skin FROM mapi_users LIMIT 1'); if ($r === false) $hasSkinCol = false; }
    catch (Throwable $e) { $hasSkinCol = false; }
    if (!$hasSkinCol) {
        $db->query("ALTER TABLE mapi_users ADD COLUMN player_skin VARCHAR(32) DEFAULT ''");
    }
    // 皮肤级配置列（JSON）同样缺列自动补
    $hasCfgCol = true;
    try { $r = $db->query('SELECT player_skin_cfg FROM mapi_users LIMIT 1'); if ($r === false) $hasCfgCol = false; }
    catch (Throwable $e) { $hasCfgCol = false; }
    if (!$hasCfgCol) {
        $db->query("ALTER TABLE mapi_users ADD COLUMN player_skin_cfg VARCHAR(1000) DEFAULT ''");
    }
    // 老库可能没有 player_pos 列：存在性检查 + 缺列自动补（幂等）
    $hasPosCol = true;
    try { $db->query('SELECT player_pos FROM mapi_users LIMIT 1'); } catch (Throwable $e) { $hasPosCol = false; }
    if ($hasPosCol) {
        $r = $db->query('SELECT player_pos FROM mapi_users LIMIT 1');
        if ($r === false) $hasPosCol = false;
    }
    if (!$hasPosCol) {
        $db->query(stripos(get_class($db), 'sqlite') !== false
            ? "ALTER TABLE mapi_users ADD COLUMN player_pos VARCHAR(24) DEFAULT ''"
            : "ALTER TABLE mapi_users ADD COLUMN player_pos VARCHAR(24) DEFAULT ''");
    }
    $oldPos = '';
    $or = $db->query("SELECT player_pos FROM mapi_users WHERE id=" . (int)$_SESSION['admin_id']);
    if ($or && $orow = $or->fetch_assoc()) $oldPos = trim((string)($orow['player_pos'] ?? ''));
    $oldSkin = '';
    $sr = $db->query("SELECT player_skin FROM mapi_users WHERE id=" . (int)$_SESSION['admin_id']);
    if ($sr && $srow = $sr->fetch_assoc()) $oldSkin = trim((string)($srow['player_skin'] ?? ''));

    // ═══ 歌词条字体（URL / 上传文件 / 字体名 / 字号）═══
    // 只处理「用户在表单里填的那部分」：URL 填了并且和原来不同 = 换成了外部字体，
    // 这时把之前上传的字体文件删掉；URL 留空则不动上传的文件（否则保存一下别的设置就把字体删了）。
    // 真正的上传/替换/清除在 handlers/lrc_font.php。
    $lrcFontUrl = lrc_font_clean_url(trim((string)($_POST['lrc_font_url'] ?? '')));
    if ($lrcFontUrl !== '' && (strlen($lrcFontUrl) > 500 || !filter_var($lrcFontUrl, FILTER_VALIDATE_URL))) $lrcFontUrl = '';
    $lrcFontName = lrc_font_clean_name((string)($_POST['lrc_font_name'] ?? ''));
    $lrcFontName = function_exists('mb_substr') ? mb_substr($lrcFontName, 0, 100, 'UTF-8') : substr($lrcFontName, 0, 100);
    $lrcFontSize = isset($_POST['lrc_font_size']) ? (int)$_POST['lrc_font_size'] : 0;
    if ($lrcFontSize < 0 || $lrcFontSize > 200) $lrcFontSize = 0;
    $curFont = lrc_font_get($db, (int)$_SESSION['admin_id']);
    $lrcFontKey = $curFont['key'];
    // 只在「这个对象确实是自己账号的」时候删——库里万一存着别人的 key，也不能借这里删掉
    if ($lrcFontUrl !== '' && $lrcFontUrl !== $curFont['url'] && lrc_font_key_is_own($lrcFontKey, (int)$_SESSION['admin_id'])) {
        s3_delete($lrcFontKey);
        $lrcFontKey = '';
    }

    $stmt = $db->prepare("UPDATE mapi_users SET auto_theme=?, theme_mode=?, lyrics_default=?, autoplay_default=?, player_pos=?, player_skin=?, player_skin_cfg=?, lrc_font=?, lrc_font_url=?, lrc_font_name=?, lrc_font_size=? WHERE id=?");
    $stmt->bind_param('isiissssssii', $autoTheme, $themeMode, $lyricsDefault, $autoplayDefault, $playerPos, $playerSkin, $playerSkinCfg, $lrcFontKey, $lrcFontUrl, $lrcFontName, $lrcFontSize, $_SESSION['admin_id']);
    if ($stmt->execute()) {
        $_SESSION['admin_auto_theme'] = $autoTheme;
        $_SESSION['admin_theme_mode'] = $themeMode;
        $_SESSION['admin_lyrics_default'] = $lyricsDefault;
        $_SESSION['admin_autoplay_default'] = $autoplayDefault;
        $_SESSION['admin_player_pos'] = $playerPos;
        $_SESSION['admin_player_skin'] = $playerSkin;
        $_SESSION['admin_player_skin_cfg'] = $playerSkinCfg;
        // 位置或皮肤改了：清掉本机的位置记忆，这样后台自己也能立刻看到新形态
        if ($oldPos !== $playerPos || $oldSkin !== $playerSkin) {
            setcookie('mapi_pos', '', time() - 3600, '/');
        }
        flash_set('msg','配置已保存');
    } else { flash_set('err','保存失败'); }
    header('Location: ?action=config'); exit;
}

// ═══ 通行密钥注册/管理 ═══
